<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\State\Game;
use App\State\Repository\GameRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Game> */
final class GameRepository extends ServiceEntityRepository implements GameRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Game::class);
    }

    public function findById(Uuid $id): ?Game
    {
        return $this->find($id);
    }

    public function findOneBySlug(string $slug): ?Game
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /** @return list<Game> */
    public function findAllInProgressFirst(): array
    {
        return $this->createQueryBuilder('g')
            ->addSelect('CASE WHEN g.finishedAt IS NULL THEN 0 ELSE 1 END AS HIDDEN finishedRank')
            ->addOrderBy('finishedRank', 'ASC')
            ->addOrderBy('g.id', 'DESC')
            ->getQuery()
            ->getResult()
        ;
    }
}
