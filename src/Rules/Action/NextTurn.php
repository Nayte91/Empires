<?php

declare(strict_types=1);

namespace App\Rules\Action;

use Symfony\Component\Uid\Uuid;

final readonly class NextTurn
{
    public function __construct(public Uuid $gameId) {}
}
