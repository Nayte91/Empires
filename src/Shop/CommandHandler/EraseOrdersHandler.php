<?php

declare(strict_types=1);

namespace App\Shop\CommandHandler;

use App\Entity\Order;
use App\Repository\OrderRepository;
use App\Repository\PlayerRepository;
use App\Shop\Command\EraseOrders;
use App\Shop\Event\OrdersErased;
use App\Shop\Event\ShopEventPublisher;
use App\Shop\FulfillmentInterface;
use App\Shop\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class EraseOrdersHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OrderRepository $orderRepository,
        private PlayerRepository $playerRepository,
        private ShopEventPublisher $events,
        private FulfillmentInterface $fulfillment,
    ) {}

    public function __invoke(EraseOrders $command): void
    {
        if ([] === $command->windows) {
            return;
        }

        $player = $this->playerRepository->find($command->playerId) ?? throw new \RuntimeException('Player not found.');

        $orders = array_values(array_filter(array_map(
            fn (int $window): ?Order => $this->orderRepository->findOneByPlayerAndWindow($player, $window),
            $command->windows,
        )));

        if ([] === $orders) {
            return;
        }

        $entityManager = $this->entityManager;
        $fulfillment = $this->fulfillment;

        $entityManager->wrapInTransaction(static function () use ($orders, $entityManager, $fulfillment): void {
            foreach ($orders as $o) {
                if (OrderStatus::Validated === $o->status) {
                    $fulfillment->revoke($o->player->id, $o->keys());
                }

                $entityManager->remove($o);
            }
        });

        $this->events->publish(new OrdersErased(
            $command->playerId,
            array_map(static fn (Order $order): int => $order->turn, $orders),
        ));
    }
}
