<?php

declare(strict_types=1);

namespace App\State\Repository;

use App\State\Player;
use Symfony\Component\Uid\Uuid;

interface PlayerRepositoryInterface
{
    public function findById(Uuid $id): ?Player;

    public function findOneByGameSlugAndSlug(string $gameSlug, string $playerSlug): ?Player;
}
