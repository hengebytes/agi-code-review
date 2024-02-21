<?php

namespace App\Jira\Agent;

use App\Agent\AbstractAgent;
use App\Entity\ProjectAgentConnection;
use App\Entity\Task;
use App\Entity\TaskResult;
use App\Enum\AgentFieldType;
use App\Jira\Service\JiraIssueService;
use Doctrine\ORM\EntityManagerInterface;

class JiraContextAgent extends AbstractAgent
{
    public function __construct(
        private readonly JiraIssueService $jiraIssueService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function processTask(
        Task $task,
        ProjectAgentConnection $connection,
        array &$messages,
    ): ?TaskResult {
        $allowedProjects = array_filter(array_map(
            'trim',
            explode(',', $connection->getConfigValue('jiraProjects'))
        ));
        $jiraIds = $this->jiraIssueService->detectJiraIssueIds(
            $task->name . ' ' . $task->description,
            $allowedProjects
        );
        if (!$jiraIds) {
            return null;
        }
        $refBaseURL = $this->jiraIssueService->getNormalizedJiraHost($connection) . '/browse/';

        foreach ($jiraIds as $jiraId) {
            $issue = $this->jiraIssueService->loadIssue($connection, $jiraId);
            if (!$issue) {
                continue;
            }
            $messages[] = $this->jiraIssueService->convertIssueToAgentMessage($issue);

            $task->externalRefs[] = $refBaseURL . $issue->id;
        }

        $task->externalRefs = array_unique($task->externalRefs);
        $this->entityManager->flush();

        $taskResult = new TaskResult();
        $taskResult->input = $task->name . ': ' . $task->description . ' = ' . implode(',', $jiraIds);
        $taskResult->output = implode("\n", array_map(static fn($m) => $m->content, $messages));

        return $taskResult;
    }

    public function getConnectionFields(): array
    {
        return [
            'jiraProjects' => [
                'label' => 'Jira Projects',
                'description' => 'Comma separated list of Jira project keys. E.g. "HB,CW,ABC"',
                'type' => AgentFieldType::STRING,
                'required' => true,
            ],
        ];
    }

    public function getExtraDataFields(): array
    {
        return [
            'jiraHost' => [
                'label' => 'Jira Host',
                'description' => 'E.g. "hengebytes", "hengebytes.atlassian.net", "https://hengebytes.internal.hb"',
                'type' => AgentFieldType::STRING,
                'required' => true,
            ],
        ];
    }
}