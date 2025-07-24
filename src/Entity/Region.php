<?php

namespace App\Entity;

final readonly class Region {
    public function __construct(
        public string $name,
        public array $civilizations
    ) {}
}
