<?php

namespace App\Enum;

enum AgentMessageRole: string
{
    case SYSTEM = 'SYSTEM';
    case USER = 'USER';
    case TOOL = 'TOOL';
}
