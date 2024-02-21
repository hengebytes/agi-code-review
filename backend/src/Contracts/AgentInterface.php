<?php

namespace App\Contracts;

use App\DTO\AgentMessage;
use App\Entity\ProjectAgentConnection;
use App\Entity\Task;
use App\Entity\TaskResult;

interface AgentInterface
{
    /**
     * @param Task $task
     * @param ProjectAgentConnection $connection
     * @param AgentMessage[] $messages
     */
    public function processTask(
        Task $task,
        ProjectAgentConnection $connection,
        array &$messages,
    ): ?TaskResult;

    /**
     * @return array = [string => ['label' => string, 'description' => string, 'type' => string, 'required' => bool]]
     */
    public function getConnectionFields(): array;

    /**
     * @return array = [string => ['label' => string, 'description' => string, 'type' => \App\Enum\AgentFieldType, 'required' => bool]]
     */
    public function getExtraDataFields(): array;

    public function getName(): string;

    public function getType(): string;
}