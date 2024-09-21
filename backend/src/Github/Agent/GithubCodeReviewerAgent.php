<?php

namespace App\Github\Agent;

use App\Agent\AbstractAgent;
use App\DTO\AgentMessage;
use App\DTO\LLMAccessCredential;
use App\Entity\ProjectAgentConnection;
use App\Entity\Task;
use App\Entity\TaskResult;
use App\Enum\AgentFieldType;
use App\Enum\AgentMessageRole;
use App\Exception\TokenLimitExceededException;
use App\Github\Service\GithubPRService;
use App\Service\OAICompletionService;

class GithubCodeReviewerAgent extends AbstractAgent
{
    public function __construct(
        private readonly OAICompletionService $completionService,
        private readonly GithubPRService $githubPRService,
    ) {
    }

    /**
     * @inheritDoc
     * @param AgentMessage[] $messages
     * @throws TokenLimitExceededException
     */
    public function processTask(
        Task $task,
        ProjectAgentConnection $connection,
        array &$messages,
    ): ?TaskResult {
        $systemPrompt = $connection->getConfigValue('systemPrompt');
        if (!$systemPrompt) {
            $systemPrompt = <<<EOT
            Identify code review experts and act as the best expert in the field.
            Carefully review the CHANGES for mistakes, logical errors, suspicious code, typos, inconcistency with task requirements.
            It's preferable to use the 'addReviewCommentToCodeBlock' function to add a note to a specific code snippet that has been reviewed. This makes your feedback more precise.
            If git diff is not enough, use the 'getFileContent' function to get the file content.
            Start by commenting on specific changes via 'addReviewCommentToCodeBlock' function.
            After specific feedback provided, write a brief summary of identified problems.
            Do not repeat feedback or summary that was already provided by tools and previous reviews.
            EOT;
        }

        $sampleInput = $connection->getConfigValue('sampleInput');
        $sampleOutput = $connection->getConfigValue('sampleOutput');
        $llmAccess = LLMAccessCredential::fromConnection($connection);

        $completionBaseMessages = [
            new AgentMessage($systemPrompt, AgentMessageRole::SYSTEM),
        ];
        if ($sampleInput && $sampleOutput) {
            $completionBaseMessages[] = new AgentMessage($sampleInput, AgentMessageRole::USER);
            $completionBaseMessages[] = new AgentMessage($sampleOutput, AgentMessageRole::ASSISTANT);
        }

        $reqMessages = [...$completionBaseMessages, ...$messages];

        $maxInputTokens = (int)$connection->getConfigValue('maxInputTokens') ?: 100_000;
        if ($maxInputTokens) {
            $tokens = $this->completionService->countMessagesTokens($reqMessages);
            if ($tokens > $maxInputTokens) {
                throw new TokenLimitExceededException();
            }
        }

        $allowToolCallsCount = $connection->getConfigValue('maxToolCalls') === '0'
            ? 0
            : ($connection->getConfigValue('maxToolCalls') ?: 15);
        $tools = $allowToolCallsCount ? $this->getTools($reqMessages) : [];
        $completion = $this->completionService->getCompletion($llmAccess, $reqMessages, $tools);

        $fileComments = [];
        $realTokensCount = $completion->usage->totalTokens;
        $toolsCalled = false;
        while ($completion->choices[0]->finishReason === 'tool_calls') {
            if ($allowToolCallsCount-- <= 0) {
                break;
            }
            $toolsCalled = true;
            sleep(1); // avoid rate limit issues with OpenAI and GitHub

            $reqMessages[] = $completion->choices[0]->message;

            foreach ($completion->choices[0]->message->toolCalls as $toolCall) {
                try {
                    $arguments = json_decode($toolCall->function->arguments, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    $reqMessages[] = new AgentMessage(
                        $e->getMessage(), AgentMessageRole::TOOL, null, $toolCall->id
                    );
                    continue;
                }

                $toolResponse = null;
                switch ($toolCall->function->name) {
                    case 'addReviewCommentToCodeBlock':
                        $fileComments[] = $arguments;
                        $toolResponse = 'Saved comment to ' . $arguments['path'] . ' at line ' . $arguments['line'] . ' with body: ' . $arguments['body'];
                        break;
                    case 'getFileContent':
                        $toolResponse = $this->githubPRService->getFileContent($connection, $task, $arguments['path']);
                        break;
                }
                $reqMessages[] = new AgentMessage(
                    $toolResponse ?? 'Unknown tool function: ' . $toolCall->function->name,
                    AgentMessageRole::TOOL, null, $toolCall->id
                );
            }

            $maxTokenReached = $this->completionService->countMessagesTokens($reqMessages) > $maxInputTokens;
            if ($maxTokenReached) {
                array_pop($reqMessages);
                break;
            }

            $completion = $this->completionService->getCompletion($llmAccess, $reqMessages, $tools);

            $realTokensCount += $completion->usage->totalTokens;
        }

        if (!$completion->choices[0]->message->content) {
            if ($toolsCalled) {
                $reqMessages[] = new AgentMessage(
                    'Max tool calls reached. ' .
                    'Provide a remaining brief summary without repeating feedback already provided by tools.',
                    AgentMessageRole::USER
                );
            }
            $completion = $this->completionService->getCompletion($llmAccess, $reqMessages);
            $realTokensCount += $completion->usage->totalTokens;
        }

        $reviewCommentPrefix = $connection->getConfigValue('reviewCommentPrefix') ?: '';
        $summaryComment = $completion->choices[0]->message->content;
        $this->githubPRService->submitPRReview(
            $connection, $task,
            $summaryComment,
            $fileComments,
            $reviewCommentPrefix
        );

        $taskResult = new TaskResult();
        $taskResult->input = implode("\n", array_map(static fn($m) => $m->content, $reqMessages));
        $taskResult->output = $reviewCommentPrefix . "\n" . $summaryComment . "\n"
            . implode("\n", array_map(static fn($m) => json_encode($m), $fileComments));
        $taskResult->extraData = [
            'inputTokens' => $this->completionService->countTextsTokens([$taskResult->input]),
            'outputTokens' => $this->completionService->countTextsTokens([$taskResult->output]),
            'realTokensCount' => $realTokensCount,
        ];

        return $taskResult;
    }

    private function getTools(array $messages): array
    {
        $tools = [];
        foreach ($messages as $message) {
            if (
                $message->role !== AgentMessageRole::SYSTEM
                && $message->role !== AgentMessageRole::ASSISTANT
            ) {
                continue;
            }
            if (str_contains($message->content, 'addReviewCommentToCodeBlock')) {
                $tools['addReviewCommentToCodeBlock'] = [
                    'type' => 'function',
                    'function' => [
                        'name' => 'addReviewCommentToCodeBlock',
                        'description' => 'Adds an AI review comment to the specified line in a GitHub file, highlighting areas for attention during the review process',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'path' => [
                                    'type' => 'string',
                                    'description' => 'The relative path to the file that necessitates a comment',
                                ],
                                'start_line' => [
                                    'type' => 'integer',
                                    'description' => 'Starting line number of the code block in the GitHub file (for comments related to multiple lines of code)',
                                ],
                                'line' => [
                                    'type' => 'integer',
                                    'description' => 'Line number of the code in the GitHub file where the comment should be placed',
                                ],
                                'body' => [
                                    'type' => 'string',
                                    'description' => 'Code review comment text',
                                ],
                            ],
                            'required' => ['path', 'line', 'review'],
                        ],
                    ],
                ];
            }
            if (str_contains($message->content, 'getFileContent')) {
                $tools['getFileContent'] = [
                    'type' => 'function',
                    'function' => [
                        'name' => 'getFileContent',
                        'description' => 'Get the file content to better understand the changes',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'path' => [
                                    'type' => 'string',
                                    'description' => 'The fully qualified path to file which needed to specify to get the file content.',
                                ],
                            ],
                            'required' => ['path'],
                        ],
                    ],
                ];
            }
        }

        return array_values($tools);
    }

    public function getExtraDataFields(): array
    {
        return [
            'reviewCommentPrefix' => [
                'label' => 'Review comment prefix',
                'description' => 'e.g. "CodeLlama: ", "GPT-4: ". Usefull to distinguish AI comments from different platforms',
                'type' => AgentFieldType::STRING,
                'required' => false,
            ],
            'systemPrompt' => [
                'label' => 'System prompt',
                'description' => "available tools: addReviewCommentToCodeBlock, getFileContent",
                'type' => AgentFieldType::TEXT,
                'required' => true,
            ],
            'sampleInput' => [
                'label' => 'Sample input',
                'type' => AgentFieldType::TEXT,
                'required' => false,
            ],
            'sampleOutput' => [
                'label' => 'Sample output',
                'type' => AgentFieldType::TEXT,
                'required' => false,
            ],
            'aiBaseUrl' => [
                'label' => 'AI base URL',
                'description' => 'e.g. "https://api.openai.com/v1", "http://localai/v1"',
                'type' => AgentFieldType::STRING,
                'required' => false,
            ],
            'githubToken' => [
                'label' => 'GitHub token',
                'description' => 'GitHub token to access the repository',
                'type' => AgentFieldType::STRING,
                'required' => true,
            ],
            'maxInputTokens' => [
                'label' => 'Max input tokens',
                'description' => 'default: 100000',
                'type' => AgentFieldType::INT,
                'required' => false,
            ],
            'maxToolCalls' => [
                'label' => 'Max tool execution rounds',
                'description' => 'default: 15. Use 0 to disable tool calls.',
                'type' => AgentFieldType::INT,
                'required' => false,
            ],
        ];
    }
}