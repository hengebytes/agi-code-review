<?php

namespace App\Github\Agent;

use App\Agent\AbstractAgent;
use App\DTO\AgentMessage;
use App\Entity\ProjectAgentConnection;
use App\Entity\Task;
use App\Entity\TaskResult;
use App\Enum\AgentFieldType;
use App\Enum\AgentMessageRole;
use App\Github\Entity\GithubPullRequest;
use Doctrine\ORM\EntityManagerInterface;

class GithubContextAgent extends AbstractAgent
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function processTask(
        Task $task,
        ProjectAgentConnection $connection,
        array &$messages,
    ): ?TaskResult {
        $codeDescription = $connection->getConfigValue('codeDescription');
        if ($codeDescription) {
            $messages[] = new AgentMessage(
                'Project code description: ' . $codeDescription,
                AgentMessageRole::USER,
            );
        }

        $pr = $this->entityManager->getRepository(GithubPullRequest::class)->findOneBy(['task' => $task]);
        if (!$pr || !$pr->diffFiles) {
            return null;
        }

        if ($pr->reviews) {
            $previousReviewsText = '';
            foreach ($pr->reviews as $review) {
                if (!empty($review['body'])) {
                    $previousReviewsText .= $review['body'] . PHP_EOL;
                }
                if (!empty($review['comments'])) {
                    $previousReviewsText .= 'Code blocks comments: ' . PHP_EOL;
                    foreach ($review['comments'] as $comment) {
                        $previousReviewsText .= $comment['path']
                            . '('
                            . (!empty($comment['startLine']) ? 'From line ' . $comment['startLine'] . ' to ' : '')
                            . 'line ' . $comment['line']
                            . '):' . PHP_EOL
                            . $comment['body'] . PHP_EOL;
                    }
                }
            }
            if (trim($previousReviewsText)) {
                $messages[] = new AgentMessage(
                    'Previously provided reviews: ' . PHP_EOL . trim($previousReviewsText),
                    AgentMessageRole::ASSISTANT,
                );
            }
        }

        $messages[] = new AgentMessage(
            'Pull request code changes: ' . PHP_EOL . $this->wrapFilesInCodeBlocks($pr->diffFiles),
            AgentMessageRole::USER,
        );

        $taskResult = new TaskResult();
        $taskResult->input = implode("\n", array_column($pr->diffFiles, 'filename'));
        $taskResult->output = implode("\n", array_map(static fn($m) => $m->content, $messages));

        return $taskResult;
    }

    private function wrapFilesInCodeBlocks(array $files): string
    {
        $text = '';
        foreach ($files as $file) {
            $text .= ($file['status'] ?? '') . ' "' . $file['filename'] . '"' . PHP_EOL;
            if ($file['patch']) {
                // AI complains too much about this note in gihub diff
                $patch = str_replace("\ No newline at end of file\n```", "\n```", $file['patch']);

                $text .= '```' . PHP_EOL . $patch . PHP_EOL . '```' . PHP_EOL;
            }
        }

        return $text;
    }

    public function getConnectionFields(): array
    {
        return [
            'repository' => [
                'label' => 'Repository',
                'description' => 'e.g. "hengebytes/agi-code-review"',
                'type' => AgentFieldType::STRING,
                'required' => true,
            ],
            'codeDescription' => [
                'label' => 'Repository Description',
                'description' => 'e.g. "Website frontend developed with React 18, NextJS 14, TailwindCSS 3, TypeScript 5. Connected to Ibexa CMS GraphQL API and REST booking API."',
                'type' => AgentFieldType::TEXT,
                'required' => false,
            ],
        ];
    }
}