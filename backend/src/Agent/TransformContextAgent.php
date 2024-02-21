<?php

namespace App\Agent;

use App\DTO\AgentMessage;
use App\DTO\LLMAccessCredential;
use App\Entity\ProjectAgentConnection;
use App\Entity\Task;
use App\Entity\TaskResult;
use App\Enum\AgentFieldType;
use App\Enum\AgentMessageRole;
use App\Service\OAICompletionService;

class TransformContextAgent extends AbstractAgent
{
    public function __construct(
        private readonly OAICompletionService $completionService,
    ) {
    }

    /**
     * @inheritDoc
     * @param AgentMessage[] $messages
     */
    public function processTask(
        Task $task,
        ProjectAgentConnection $connection,
        array &$messages,
    ): ?TaskResult {
        $rules = $this->getMatchRules($connection);
        $systemPrompt = $connection->getConfigValue('systemPrompt');
        $sampleInput = $connection->getConfigValue('sampleInput');
        $sampleOutput = $connection->getConfigValue('sampleOutput');
        $llmAccess = LLMAccessCredential::fromConnection($connection);

        $completionBaseMessages = [new AgentMessage($systemPrompt, AgentMessageRole::SYSTEM)];
        if ($sampleInput && $sampleOutput) {
            $completionBaseMessages[] = new AgentMessage($sampleInput, AgentMessageRole::USER);
            $completionBaseMessages[] = new AgentMessage($sampleOutput, AgentMessageRole::SYSTEM);
        }

        foreach ($messages as $message) {
            foreach ($rules as $field => $val) {
                if (!isset($message->metadata[$field]) || $message->metadata[$field] !== $val) {
                    continue 2;
                }
            }

            $completion = $this->completionService->getCompletion($llmAccess, [
                ...$completionBaseMessages,
                $message,
            ]);

            $message->content = $completion->choices[0]->message->content;
        }

        $taskResult = new TaskResult();
        $taskResult->input = $systemPrompt . "\n" . $sampleInput . "\n" . $sampleOutput;
        $taskResult->output = implode("\n", array_map(static fn($m) => $m->content, $messages));

        return $taskResult;
    }

    private function getMatchRules(ProjectAgentConnection $connection): array
    {
        $rulesTextParts = explode(',', $connection->getConfigValue('matchMessage'));
        $rules = [];
        foreach ($rulesTextParts as $ruleText) {
            $ruleParts = explode('=', $ruleText);
            $rules[trim($ruleParts[0])] = trim($ruleParts[1]);
        }

        return $rules;
    }

    public function getExtraDataFields(): array
    {
        return [
            'matchMessage' => [
                'label' => 'Match message (extra data)',
                'description' => 'e.g. "type = jira-issue, hasComments = Y',
                'type' => AgentFieldType::STRING,
                'required' => true,
            ],
            'systemPrompt' => [
                'label' => 'System prompt',
                'description' => 'e.g. "Remove all links from Jira Description"',
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
                'description' => 'e.g. "https://api.openai.com/v1"',
                'type' => AgentFieldType::STRING,
                'required' => false,
            ],
        ];
    }
}