<?php

namespace App\Agent;

use App\DTO\AgentMessage;
use App\Entity\ProjectAgentConnection;
use App\Entity\Task;
use App\Entity\TaskResult;
use App\Enum\AgentFieldType;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SlackNotificationAgent extends AbstractAgent
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
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
        $msg = $this->replaceMessageVariables(
            $connection->config['messageText']
            ?? '{projectName}: Task {taskName} ({taskId}).' . PHP_EOL . '{references}',
            $task
        );
        if (empty($msg)) {
            return TaskResult::fromOutput('Skip sending empty message');
        }

        try {
            $response = $this->httpClient->request('POST', $connection->getAccessKey(), [
                'json' => [
                    'text' => $msg,
                ],
            ])->getContent();
        } catch (\Exception $e) {
            return TaskResult::fromOutput('Error sending slack message: ' . $e->getMessage());
        }

        return TaskResult::fromOutput('Slack message sent: ' . json_encode($response));
    }

    public function getExtraDataFields(): array
    {
        return [
            'messageText' => [
                'label' => 'Message text',
                'description' => 'Variables: {projectName}, {taskName}, {taskId}, {references}. ' .
                    'Default: `{projectName}: Task {taskName} ({taskId}).\n{references}`',
                'type' => AgentFieldType::TEXT,
            ],
        ];
    }

    private function replaceMessageVariables(string $messageText, Task $task): string
    {
        return str_replace(
            [
                '{projectName}',
                '{taskName}',
                '{taskId}',
                '{references}',
            ],
            [
                $task->project->name,
                $task->name,
                $task->id,
                implode(PHP_EOL, $task->externalRefs),
            ],
            $messageText
        );
    }
}