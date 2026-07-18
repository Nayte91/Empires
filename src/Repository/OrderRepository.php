<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GameSession;
use App\Entity\Order;
use App\Entity\Player;
use App\Shop\OrderStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Order> */
final class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    /** @return list<Order> */
    public function findPendingByGameAndTurn(GameSession $game, int $turn): array
    {
        return $this->createQueryBuilder('o')
            ->join('o.player', 'p')
            ->andWhere('p.game = :game')
            ->andWhere('o.turn = :turn')
            ->andWhere('o.status = :status')
            ->setParameter('game', $game->id, 'uuid')
            ->setParameter('turn', $turn)
            ->setParameter('status', OrderStatus::Pending)
            ->getQuery()
            ->getResult()
        ;
    }

    public function findOneByPlayerAndTurn(Player $player, int $turn): ?Order
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.player = :player')
            ->andWhere('o.turn = :turn')
            ->setParameter('player', $player->id, 'uuid')
            ->setParameter('turn', $turn)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }

    /** @return list<Order> */
    public function findByGameAndTurn(GameSession $game, int $turn): array
    {
        return $this->createQueryBuilder('o')
            ->join('o.player', 'p')
            ->andWhere('p.game = :game')
            ->andWhere('o.turn = :turn')
            ->setParameter('game', $game->id, 'uuid')
            ->setParameter('turn', $turn)
            ->addOrderBy('o.createdAt', 'DESC')
            ->getQuery()
            ->getResult()
        ;
    }
}
