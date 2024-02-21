<?php

namespace App\Github\Message;

use App\Github\Message\Async\GithubPullRequestUpdate;
use App\Github\Service\GithubPRService;
use App\Handler\TaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class GithubPullRequestUpdateMessageHandler
{
    public function __construct(
        private GithubPRService $prService,
        private TaskHandler $taskHandler,
    ) {
    }

    public function __invoke(GithubPullRequestUpdate $message): void
    {
        $existingTask = $this->prService->getTaskByPRDetails($message->owner, $message->repo, $message->prId);

        if ($message->status === 'closed') {
            if ($existingTask) {
                $this->taskHandler->markTaskCompleted($existingTask->id);
            }
            return;
        }

        if ($existingTask) {
            $this->prService->refreshTaskPR($existingTask);
        } else {
            $this->prService->createPRTask($message->owner, $message->repo, $message->prId);
        }
    }
}