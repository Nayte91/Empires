<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\State\Player;
use App\State\Repository\PlayerRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Player> */
final class PlayerRepository extends ServiceEntityRepository implements PlayerRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Player::class);
    }

    public function findById(Uuid $id): ?Player
    {
        return $this->find($id);
    }

    public function findOneByGameSlugAndSlug(string $gameSlug, string $playerSlug): ?Player
    {
        return $this->createQueryBuilder('p')
            ->innerJoin('p.game', 'g')
            ->andWhere('g.slug = :gameSlug')
            ->andWhere('p.slug = :playerSlug')
            ->setParameter('gameSlug', $gameSlug)
            ->setParameter('playerSlug', $playerSlug)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
}
