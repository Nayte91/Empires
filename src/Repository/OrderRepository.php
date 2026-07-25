<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Order;
use App\Entity\Player;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Order> */
final class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    public function findOneByPlayerAndWindow(Player $player, int $window): ?Order
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.player = :player')
            ->andWhere('o.turn = :window')
            ->setParameter('player', $player->id, 'uuid')
            ->setParameter('window', $window)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }

    /** @return list<Order> */
    public function findByPlayerFromTurn(Player $player, int $turn): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.player = :player')
            ->andWhere('o.turn >= :turn')
            ->setParameter('player', $player->id, 'uuid')
            ->setParameter('turn', $turn)
            ->addOrderBy('o.turn', 'ASC')
            ->getQuery()
            ->getResult()
        ;
    }

    /** @return list<Order> */
    public function findByPlayer(Player $player): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.player = :player')
            ->setParameter('player', $player->id, 'uuid')
            ->addOrderBy('o.turn', 'ASC')
            ->getQuery()
            ->getResult()
        ;
    }
}
