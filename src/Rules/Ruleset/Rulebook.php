<?php

declare(strict_types=1);

namespace App\Rules\Ruleset;

final readonly class Rulebook
{
    public function __construct(
        public string $label,
        public string $caption,
        public string $url,
    ) {}
}
