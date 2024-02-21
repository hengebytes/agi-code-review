<?php

namespace App\Message\Async;

readonly class TaskCompletedMessage
{
    public function __construct(
        public int $taskId,
    ) {
    }
}
