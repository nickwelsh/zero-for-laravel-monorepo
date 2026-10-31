<?php

declare(strict_types=1);

namespace App\Enums;

enum TodoStatus: string
{
    case All = 'all';
    case Active = 'active';
    case Completed = 'completed';
}
