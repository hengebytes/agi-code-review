<?php

namespace App\Message;

use App\Entity\Task;
use App\Enum\TaskStatus;
use App\Message\Async\TaskCreatedMessage;
use App\Message\Async\TaskUpdatedMessage;
use App\Service\TaskProcessorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class TaskCreatedMessageHandler
{
    public function __construct(
        private TaskProcessorService $taskProcessorService,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(TaskCreatedMessage|TaskUpdatedMessage $message): void
    {
        /** @var Task $task */
        $task = $this->entityManager->getRepository(Task::class)->find($message->taskId);
        if (!$task || $task->status !== TaskStatus::READY_TO_PROCESS) {
            return;
        }
        $this->taskProcessorService->processTask($task);
    }
}