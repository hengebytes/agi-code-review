<?php

namespace App\Handler;

use App\Entity\Task;
use App\Enum\TaskStatus;
use App\Message\Async\TaskCompletedMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

readonly class TaskHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
    ) {
    }

    public function markTaskCompleted(int $taskId): void
    {
        $task = $this->entityManager->find(Task::class, $taskId);
        if (!$task) {
            throw new \RuntimeException('Task not found');
        }

        $task->status = TaskStatus::COMPLETED;
        $this->entityManager->persist($task);
        $this->entityManager->flush();

        $this->bus->dispatch(new TaskCompletedMessage($taskId));
    }
}