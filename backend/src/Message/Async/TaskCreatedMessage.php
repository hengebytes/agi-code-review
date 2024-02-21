<?php

namespace App\Message\Async;

readonly class TaskCreatedMessage
{
    public function __construct(
        public int $taskId,
    ) {
    }
}
