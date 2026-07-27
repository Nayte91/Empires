<?php

declare(strict_types=1);

namespace App\State;

enum ASTVersion: string
{
    case BASIC = 'basic';
    case EXPERT = 'expert';
}
