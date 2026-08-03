<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\State\Game;
use App\State\Order;
use App\State\Player;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;
use Userforged\ShopEngine\OrderInterface;
use Userforged\ShopEngine\OrderRepositoryInterface;
use Userforged\ShopEngine\OrderStatus;

/** @extends ServiceEntityRepository<Order> */
final class OrderRepository extends ServiceEntityRepository implements OrderRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    public function findOneByBuyerAndWindow(Uuid $buyerId, int $window): ?OrderInterface
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.player = :player')
            ->andWhere('o.turn = :window')
            ->setParameter('player', $buyerId, 'uuid')
            ->setParameter('window', $window)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }

    public function findOneByPlayerAndWindow(Player $player, int $window): ?Order
    {
        $order = $this->findOneByBuyerAndWindow($player->id, $window);

        return $order instanceof Order ? $order : null;
    }

    public function create(Uuid $buyerId, int $window): OrderInterface
    {
        $order = new Order($this->getEntityManager()->getReference(Player::class, $buyerId), $window);
        $this->getEntityManager()->persist($order);

        return $order;
    }

    public function remove(OrderInterface $order): void
    {
        $this->getEntityManager()->remove($order);
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

    /**
     * Every validated basket of a whole game, in turn order — the raw material of the
     * post-game advance history. Pending baskets are excluded: they were never delivered.
     *
     * @return list<Order>
     */
    public function findValidatedByGame(Game $game): array
    {
        return $this->createQueryBuilder('o')
            ->join('o.player', 'p')
            ->andWhere('p.game = :game')
            ->andWhere('o.status = :status')
            ->setParameter('game', $game->id, 'uuid')
            ->setParameter('status', OrderStatus::Validated->value)
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

    /**
     * Every validated basket a player placed, in turn order — the raw material of the
     * saga's purchase-value chart. Pending or rejected baskets are excluded: they were
     * never delivered, so they never cost anything.
     *
     * @return list<Order>
     */
    public function findValidatedByPlayer(Player $player): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.player = :player')
            ->andWhere('o.status = :status')
            ->setParameter('player', $player->id, 'uuid')
            ->setParameter('status', OrderStatus::Validated->value)
            ->addOrderBy('o.turn', 'ASC')
            ->getQuery()
            ->getResult()
        ;
    }
}
