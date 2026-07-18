<?php

declare(strict_types=1);

namespace App\Game\Dto;

final readonly class Advance
{
    /**
     * @param list<string>       $categories
     * @param array<string, int> $credits
     * @param list<string>       $mitigations
     * @param list<string>       $aggravations
     */
    public function __construct(
        public string $key,
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
