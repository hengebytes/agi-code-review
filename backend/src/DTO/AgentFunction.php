<?php

namespace App\DTO;

class AgentFunction
{
    public function __construct(
        public string $name,
        public string $description,
        public array $parameters = [],
    ) {
    }
}