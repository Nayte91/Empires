<?php

declare(strict_types=1);

namespace App\State;

enum CreditSource: string
{
    case Shop = 'shop';
    case Scenario = 'scenario';
    case Ability = 'special ability';
}
