<?php

declare(strict_types=1);

namespace App\Shop\Service;

use App\Entity\Order;
use App\Entity\Player;
use App\Enumeration\OrderStatus;
use App\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DirectSale
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OrderRepository $orderRepository,
        private OrderValidator $orderValidator,
    ) {}

    /** @param list<string> $keys */
    public function sell(Player $player, array $keys, int $money): Order
    {
        if ([] === $keys) {
            throw new \DomainException('Cart is empty.');
        }

        $turn = $player->game->currentTurn;
        $order = $this->orderRepository->findOneByPlayerAndTurn($player, $turn);

        if (OrderStatus::Validated === $order?->status) {
            throw new \DomainException('An order has already been validated for this turn.');
        }

        if (!$order instanceof Order) {
            $order = new Order($player, $turn);
            $this->entityManager->persist($order);
        }

        $order->replaceLines($keys);

        $this->orderValidator->validate($order, $money);

        return $order;
    }
}
