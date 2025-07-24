<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enumeration\ASTType;

final readonly class Game
{
    public function __construct(
        public ?string $playerName = null,
        public ?Civilization $civilization = null,
        public ASTType $astType = ASTType::BASIC,
        public int $playerCount = 9,
        public ?string $region = 'west',
        public array $advances = [],
        public array $resources = [],
        public int $currentTurn = 1,
    ) {}
}
