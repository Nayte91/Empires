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
