<?php

namespace App\Runtime;

use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Runtime\{RunnerInterface, SymfonyRuntime};

class Runtime extends SymfonyRuntime
{
    public function getRunner(?object $application): RunnerInterface
    {
        if ($application instanceof HttpKernelInterface) {
            return new Runner($application);
        }

        return parent::getRunner($application);
    }
}
