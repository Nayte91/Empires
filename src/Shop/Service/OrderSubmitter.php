<?php

declare(strict_types=1);

namespace App\Shop\Service;

use App\Entity\Order;
use App\Entity\Player;
use App\Repository\OrderRepository;
use App\Shop\CartRepository;
use App\Shop\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final readonly class OrderSubmitter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CartRepository $cartRepository,
        private OrderRepository $orderRepository,
        private HubInterface $hub,
    ) {}

    public function submit(Player $player): Order
    {
        $cart = $this->cartRepository->findOrCreate($player->id);

        if ($cart->isEmpty()) {
            throw new \DomainException('Cart is empty.');
        }

        $ownedAdvances = $player->advances;

        foreach ($cart->items as $slug) {
            if (in_array($slug, $ownedAdvances, true)) {
                throw new \DomainException(sprintf('Advance "%s" is already owned.', $slug));
            }
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

        $order->replaceLines($cart->items);

        $this->entityManager->flush();

        $this->hub->publish(new Update(
            'empires/game/'.$order->player->game->id,
            '{"event":"order-updated"}',
        ));

        $this->cartRepository->clear($player->id);

        return $order;
    }
}
