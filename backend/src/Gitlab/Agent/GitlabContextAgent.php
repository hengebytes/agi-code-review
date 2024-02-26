<?php

namespace App\Gitlab\Agent;

use App\Agent\AbstractAgent;
use App\DTO\AgentMessage;
use App\Entity\ProjectAgentConnection;
use App\Entity\Task;
use App\Entity\TaskResult;
use App\Enum\AgentFieldType;
use App\Enum\AgentMessageRole;
use App\Gitlab\Entity\GitlabMergeRequest;
use Doctrine\ORM\EntityManagerInterface;

class GitlabContextAgent extends AbstractAgent
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

        $mr = $this->entityManager->getRepository(GitlabMergeRequest::class)->findOneBy(['task' => $task]);
        if (!$mr || !$mr->diffFiles) {
            return null;
        }

        $messages[] = new AgentMessage(
            'Merge request code changes: ' . PHP_EOL . $this->wrapFilesInCodeBlocks($mr->diffFiles),
            AgentMessageRole::USER,
        );

        $taskResult = new TaskResult();
        $taskResult->input = implode("\n", array_column($mr->diffFiles, 'filename'));
        $taskResult->output = implode("\n", array_map(static fn($m) => $m->content, $messages));

        return $taskResult;
    }

    private function wrapFilesInCodeBlocks(array $files): string
    {
        $text = '';
        foreach ($files as $file) {
            $text .= ($file['status'] ?? '') . ' "' . $file['filename'] . '"' . PHP_EOL;
            if ($file['patch']) {
                // AI complains too much about this note in gilab diff
                $patch = str_replace("\n\\ No newline at end of file\n```", "\n```", $file['patch']);

                $text .= '```' . PHP_EOL . $patch . PHP_EOL . '```' . PHP_EOL;
            }
        }

        return $text;
    }

    public function getConnectionFields(): array
    {
        return [
            'repository' => [
                'label' => 'Repository URL',
                'description' => 'WITH leading slash! e.g. "https://gitlab.com/hengebytes/agi/agi-code-review/", "https://git.yourdomain.com/hengebytes/agi-code-review/"',
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