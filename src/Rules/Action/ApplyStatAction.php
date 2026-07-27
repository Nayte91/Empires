<?php

declare(strict_types=1);

namespace App\Rules\Action;

use Symfony\Component\Uid\Uuid;

final readonly class ApplyStatAction
{
    public function __construct(
        public Uuid $playerId,
        public StatAction $action,
    ) {}
}
