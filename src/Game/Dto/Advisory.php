<?php

declare(strict_types=1);

namespace App\Game\Dto;

use App\Game\AdvisoryLevel;

/**
 * One thing worth telling the player. The level carries no default on purpose: how loudly a
 * message lands is an editorial call its author has to make, not something to inherit by accident.
 */
final readonly class Advisory
{
    public function __construct(
        public string $message,
        public AdvisoryLevel $level,
        /**
         * The advance that explains this line, when one does. Its key, never its symbol: the view
         * decides that Military wears crossed swords.
         */
        public ?string $advance = null,
    ) {}
}
