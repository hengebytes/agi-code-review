<?php

namespace App\Gitlab\Agent;

use App\Agent\AbstractAgent;
use App\DTO\AgentMessage;
use App\DTO\LLMAccessCredential;
use App\Entity\ProjectAgentConnection;
use App\Entity\Task;
use App\Entity\TaskResult;
use App\Enum\AgentFieldType;
use App\Enum\AgentMessageRole;
use App\Exception\TokenLimitExceededException;
use App\Gitlab\Service\GitlabMRService;
use App\Service\OAICompletionService;

class GitlabCodeReviewerAgent extends AbstractAgent
{
    public function __construct(
        private readonly OAICompletionService $completionService,
        private readonly GitlabMRService $gitlabMRService,
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
            If git diff is not enough, use the 'getFileContent' function to get the file content.
            Write a brief summary of identified problems.
            Do not explain the code changes, just point out the problems.
            If everything is fine, just respond "BOT VALIDATION PASSED" without any additional details.
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
            $completionBaseMessages[] = new AgentMessage($sampleOutput, AgentMessageRole::SYSTEM);
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

        $realTokensCount = $completion->usage->totalTokens;
        $toolsCalled = false;
        while ($completion->choices[0]->finishReason === 'tool_calls') {
            if ($allowToolCallsCount-- <= 0) {
                break;
            }
            $toolsCalled = true;
            sleep(1); // avoid rate limit issues with OpenAI and GitHub

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
                    case 'getFileContent':
                        try {
                            $toolResponse = $this->gitlabMRService->getFileContent($connection, $task, $arguments['path']);
                        } catch (\Exception $e) {
                            $toolResponse = $e->getMessage();
                        }
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
                    'Max tool calls reached. Provide a remaining brief summary without tools.',
                    AgentMessageRole::USER
                );
            }
            $completion = $this->completionService->getCompletion($llmAccess, $reqMessages);
            $realTokensCount += $completion->usage->totalTokens;
        }

        $reviewCommentPrefix = $connection->getConfigValue('reviewCommentPrefix') ?: '';
        $summaryComment = $completion->choices[0]->message->content;
        $this->gitlabMRService->submitMRReview($connection, $task, $summaryComment, $reviewCommentPrefix);

        $taskResult = new TaskResult();
        $taskResult->input = implode("\n", array_map(static fn($m) => $m->content, $reqMessages));
        $taskResult->output = $reviewCommentPrefix . "\n" . $summaryComment;
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
            if ($message->role !== AgentMessageRole::SYSTEM) {
                continue;
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
                'description' => "available tools: getFileContent",
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
            'gitlabToken' => [
                'label' => 'GitLab token',
                'description' => 'GitLab token to access the repository',
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