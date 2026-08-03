<?php

declare(strict_types=1);

namespace App\Rules\Action;

use Symfony\Component\Uid\Uuid;

final readonly class RenamePlayer
{
    public function __construct(
        public Uuid $playerId,
        public string $name,
    ) {}
}
