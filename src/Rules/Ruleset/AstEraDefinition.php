<?php

declare(strict_types=1);

namespace App\Rules\Ruleset;

final readonly class AstEraDefinition
{
    /**
     * @param array<string, int> $basicRequirements
     * @param array<string, int> $expertRequirements
     */
    public function __construct(
        public string $key,
        public string $name,
        public array $basicRequirements,
        public array $expertRequirements,
        public int $index,
    ) {}
}
