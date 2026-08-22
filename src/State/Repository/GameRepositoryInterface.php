<?php

declare(strict_types=1);

namespace App\State\Repository;

use App\State\Game;
use Symfony\Component\Uid\Uuid;

interface GameRepositoryInterface
{
    public function findById(Uuid $id): ?Game;

    public function findOneBySlug(string $slug): ?Game;

    /** @return list<Game> */
    public function findInProgress(): array;
}
