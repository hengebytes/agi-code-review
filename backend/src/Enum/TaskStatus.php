<?php

namespace App\Enum;

enum TaskStatus: int
{
    case NEW = 1;
    case READY_TO_PROCESS = 2;
    case PROCESSING = 3;
    case COMPLETED = 4;
    case FAILED = 5;
}
