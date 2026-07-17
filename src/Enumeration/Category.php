<?php

declare(strict_types=1);

namespace App\Enumeration;

enum Category: string
{
    case ART = 'art';
    case CIVIC = 'civic';
    case CRAFT = 'craft';
    case RELIGION = 'religion';
    case SCIENCE = 'science';
}
