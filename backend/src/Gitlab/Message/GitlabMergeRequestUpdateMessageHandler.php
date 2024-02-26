<?php

namespace App\Gitlab\Message;

use App\Github\Message\Async\GithubPullRequestUpdate;
use App\Gitlab\Message\Async\GitlabMergeRequestUpdate;
use App\Gitlab\Service\GitlabMRService;
use App\Handler\TaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class GitlabMergeRequestUpdateMessageHandler
{
    public function __construct(
        private GitlabMRService $mrService,
        private TaskHandler $taskHandler,
    ) {
    }

    public function __invoke(GitlabMergeRequestUpdate $message): void
    {
        $existingTask = $this->mrService->getTaskByMRDetails($message->repoURL, $message->prId);

        if ($message->status === 'closed') {
            if ($existingTask) {
                $this->taskHandler->markTaskCompleted($existingTask->id);
            }
            return;
        }

        if ($existingTask) {
            $this->mrService->refreshTaskPR($existingTask);
        } else {
            $this->mrService->createMRTask($message->repoURL, $message->prId);
        }
    }
}