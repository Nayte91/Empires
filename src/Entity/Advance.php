<?php

namespace App\Entity;

class Advance {
    public function __construct(
        public string $name,
        public string $fileName,
        public int $cost,
        public int $points,
        public array $categories,
        public array $credits,
        public array $mitigations,
        public array $aggravations,
    ) {}
}
