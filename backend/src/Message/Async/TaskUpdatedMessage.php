<?php

namespace App\Message\Async;

readonly class TaskUpdatedMessage
{
    public function __construct(
        public int $taskId,
    ) {
    }
}
