<?php

namespace App\Jira\Service;

use App\DTO\AgentMessage;
use App\Entity\ProjectAgentConnection;
use App\Enum\AgentMessageRole;
use App\Jira\DTO\JiraIssue;
use JiraRestApi\Configuration\ArrayConfiguration;
use JiraRestApi\Issue\IssueService;

class JiraIssueService
{
    /**
     * @param string[] $allowedProjects
     * @return string[]
     */
    public function detectJiraIssueIds(string $text, array $allowedProjects): array
    {
        $regEx = '/(' . implode('|', $allowedProjects) . '[- ]\\d+)/i';
        preg_match_all($regEx, strtoupper($text), $matches);
        $matches = $matches[0] ?? null;
        if (!$matches || !count($matches)) {
            return [];
        }
        $matches = array_map(static fn($match) => str_replace(' ', '-', $match), $matches);

        return array_unique($matches);
    }

    public function loadIssue(ProjectAgentConnection $connection, string $jiraId): ?JiraIssue
    {
        $issue = $this->getJiraIssueClient($connection)->get($jiraId, [
            'fields' => ['description', 'summary'],
        ]);
        if (empty($issue->fields)) {
            return null;
        }
        $comments = $this->getJiraIssueClient($connection)->getComments($jiraId)->comments;
        $comments = array_map(fn($comment) => $this->clearComment($comment->body), $comments);

        return new JiraIssue(
            $jiraId,
            $issue->fields->summary,
            $issue->fields->description ?: '',
            $comments,
        );
    }

    protected function clearComment(string $comment): string
    {
        $clear = preg_replace('/\[~accountid:.*?]/', '', $comment);
        $clear = preg_replace('/Thanks./i', '', $clear);

        return trim($clear);
    }

    public function getNormalizedJiraHost(ProjectAgentConnection $connection): string
    {
        $host = $connection->getConfigValue('jiraHost');
        if (!$host) {
            throw new \InvalidArgumentException('Jira host is not configured');
        }
        if (!str_contains($host, 'http')) {
            $host = 'https://' . $host;
        }
        if (!str_contains($host, '.')) {
            $host .= '.atlassian.net';
        }

        return $host;
    }

    private function getJiraIssueClient(ProjectAgentConnection $connection): IssueService
    {
        $config = [
            'jiraLogEnabled' => false,
            'jiraHost' => $this->getNormalizedJiraHost($connection),
        ];
        $jiraUser = $connection->getAccessName();
        if ($jiraUser) {
            $config['jiraUser'] = $jiraUser;
            $config['jiraPassword'] = $connection->getAccessKey();
        } else {
            $config['useTokenBasedAuth'] = true;
            $config['personalAccessToken'] = $connection->getAccessKey();
        }

        return new IssueService(new ArrayConfiguration($config));
    }

    public function convertIssueToAgentMessage(JiraIssue $issue): AgentMessage
    {
        $msg = "Task name:\n{$issue->name}\n\nTask description:\n{$issue->description}";
        if ($issue->comments) {
            $msg .= "\n\nCOMMENTS START:\n\n"
                . implode("\n---\n", $issue->comments)
                . "\n\nCOMMENTS END.";
        }

        return new AgentMessage($msg, AgentMessageRole::USER, [
            'type' => 'jira-issue',
            'hasComments' => $issue->comments ? 'Y' : 'N',
        ]);
    }
}