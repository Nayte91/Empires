<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Player;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Player> */
final class PlayerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Player::class);
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
