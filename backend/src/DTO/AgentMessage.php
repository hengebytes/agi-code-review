<?php

namespace App\DTO;

use App\Enum\AgentMessageRole;

class AgentMessage
{
    public function __construct(
        public string $content,
        public AgentMessageRole $role = AgentMessageRole::USER,
        public ?array $metadata = null,
        public ?string $toolCallId = null,
        public ?array $toolCalls = null,
    ) {
    }
}