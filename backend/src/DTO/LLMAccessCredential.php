<?php

namespace App\DTO;

use App\Entity\ProjectAgentConnection;

class LLMAccessCredential
{
    public function __construct(
        public string $apiUrl,
        public string $token,
        public string $model,
    ) {
    }

    public static function fromConnection(ProjectAgentConnection $connection): self
    {
        return new self(
            $connection->getConfigValue('aiBaseUrl'),
            $connection->getAccessKey(),
            $connection->getAccessName(),
        );
    }
}