<?php

namespace App\Message;

use App\Entity\Task;
use App\Message\Async\TaskCompletedMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class TaskCompletedMessageHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private int $projectTasksLimit,
        private int $globalTasksLimit,
    ) {
    }

    public function __invoke(TaskCompletedMessage $message): void
    {
        $tasksTotalCount = $this->entityManager->getRepository(Task::class)->count();
        if ($tasksTotalCount > $this->globalTasksLimit) {
            $this->removeGlobalOldTasks();
        }

        $task = $this->entityManager->getRepository(Task::class)->find($message->taskId);
        if (!$task) {
            return;
        }
        $projectTasksCount = $this->entityManager->getRepository(Task::class)->count(['project' => $task->projectId]);
        if ($projectTasksCount > $this->projectTasksLimit) {
            $this->removeProjectOldTasks($task->projectId);
        }
    }

    private function removeProjectOldTasks(int $projectId): void
    {
        $tasksToRemove = $this->entityManager->getRepository(Task::class)
            ->findBy(['project' => $projectId], ['createdAt' => 'ASC'], 100, $this->projectTasksLimit);
        foreach ($tasksToRemove as $task) {
            $this->entityManager->remove($task);
        }
        $this->entityManager->flush();
    }

    private function removeGlobalOldTasks(): void
    {
        $tasksToRemove = $this->entityManager->getRepository(Task::class)
            ->findBy([], ['createdAt' => 'ASC'], 100, $this->globalTasksLimit);
        foreach ($tasksToRemove as $task) {
            $this->entityManager->remove($task);
        }
        $this->entityManager->flush();
    }
}
