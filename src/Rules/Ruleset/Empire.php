<?php

declare(strict_types=1);

namespace App\Rules\Ruleset;

final readonly class Empire
{
    public function __construct(
        public int $position,
        public string $name,
        public string $demonym,
        public string $adjective,
        public ?string $peopleIcon,
        public ?string $shipIcon,
        public ?string $cityIcon,
        public string $color,
    ) {}
}
