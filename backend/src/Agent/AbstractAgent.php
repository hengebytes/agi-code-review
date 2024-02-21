<?php

namespace App\Agent;

use App\Contracts\AgentInterface;

abstract class AbstractAgent implements AgentInterface
{
    public function getConnectionFields(): array
    {
        return [];
    }

    public function getExtraDataFields(): array
    {
        return [];
    }

    public function getName(): string
    {
        return preg_replace(
            '/(?<!^)[A-Z]/',
            ' $0',
            (new \ReflectionClass($this))->getShortName()
        );
    }

    public function getType(): string
    {
        return (new \ReflectionClass($this))->getShortName();
    }
}