<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\State\GameSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<GameSession> */
final class GameSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameSession::class);
    }

    /** @return list<GameSession> */
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
