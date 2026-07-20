<?php

declare(strict_types=1);

namespace App\Shop\Service;

use App\Entity\Order;
use App\Repository\OrderRepository;
use App\Shop\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final readonly class OrderEraser
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OrderRepository $orderRepository,
        private HubInterface $hub,
    ) {}

    public function erase(Order $order): void
    {
        $orders = OrderStatus::Validated === $order->status
            ? $this->orderRepository->findByPlayerFromTurn($order->player, $order->turn)
            : [$order];

        $entityManager = $this->entityManager;

        $entityManager->wrapInTransaction(static function () use ($orders, $entityManager): void {
            foreach ($orders as $o) {
                if (OrderStatus::Validated === $o->status) {
                    /** @var list<array{key: string, netCost: int}> $frozenLines */
                    $frozenLines = $o->lines;
                    $o->player->disownAdvances(array_column($frozenLines, 'key'));
                }

                $entityManager->remove($o);
            }
        });

        $topic = 'empires/game/'.$order->player->game->id;

        $this->hub->publish(new Update($topic, '{"event":"player-updated"}'));
        $this->hub->publish(new Update($topic, '{"event":"order-updated"}'));
    }
}
