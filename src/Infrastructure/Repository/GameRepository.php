<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\State\Game;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Game> */
final class GameRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Game::class);
    }

    /** @return list<Game> */
    public function findInProgress(): array
    {
        return $this->createQueryBuilder('g')
            ->andWhere('g.finishedAt IS NULL')
            ->addOrderBy('g.id', 'DESC')
            ->getQuery()
            ->getResult()
        ;
    }
}
