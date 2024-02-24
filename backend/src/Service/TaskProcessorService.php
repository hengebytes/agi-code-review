<?php

namespace App\Service;

use App\Contracts\AgentInterface;
use App\Entity\ProjectAgentConnection;
use App\Entity\Task;
use App\Entity\TaskResult;
use App\Enum\TaskStatus;
use App\Message\Async\TaskCompletedMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Component\Messenger\MessageBusInterface;

readonly class TaskProcessorService
{
    public function __construct(
        #[TaggedIterator('agi.task.agent')]
        private iterable $agents,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
    ) {
    }

    public function processTask(Task $task): void
    {
        $task->status = TaskStatus::PROCESSING;
        $this->entityManager->flush();

        try {
            $messages = [];

            /** @var ProjectAgentConnection $connection */
            foreach ($task->project->agents as $connection) {
                $agentConfig = $connection->agent;

                $taskAgent = null;
                /** @var AgentInterface $agent */
                foreach ($this->agents as $agent) {
                    if ($agent->getType() === $agentConfig->type) {
                        $taskAgent = $agent;
                        break;
                    }
                }
                if (!$taskAgent) {
                    continue;
                }

                $result = $taskAgent->processTask($task, $connection, $messages);
                if ($result) {
                    $result->task = $task;
                    $result->agent = $agentConfig;
                    $result->agentName = $agentConfig->name;
                    $this->entityManager->persist($result);
                }
            }

            $task->status = TaskStatus::COMPLETED;
        } catch (\Exception $e) {
            $result = new TaskResult();
            $result->task = $task;
            $result->input = '';
            if (isset($agentConfig)) {
                $result->agent = $agentConfig;
                $result->agentName = $agentConfig->name;
            }
            $result->output = $e->getMessage();
            $this->entityManager->persist($result);
            $task->results->add($result);

            $task->status = TaskStatus::FAILED;
        }

        $this->entityManager->flush();
        if ($task->status === TaskStatus::COMPLETED) {
            $this->bus->dispatch(new TaskCompletedMessage($task->id));
        }
    }
}