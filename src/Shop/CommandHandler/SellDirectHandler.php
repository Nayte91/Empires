<?php

declare(strict_types=1);

namespace App\Shop\CommandHandler;

use App\Entity\Order;
use App\Game\Shop\ShopConnector;
use App\Repository\OrderRepository;
use App\Repository\PlayerRepository;
use App\Shop\Command\SellDirect;
use App\Shop\Event\OrderSold;
use App\Shop\Event\ShopEventPublisher;
use App\Shop\Exception\CartException;
use App\Shop\Exception\OrderException;
use App\Shop\OrderStatus;
use App\Shop\Service\LineQuoter;
use App\Shop\Service\OrderValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Workflow\WorkflowInterface;

#[AsMessageHandler]
final readonly class SellDirectHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OrderRepository $orderRepository,
        private PlayerRepository $playerRepository,
        private OrderValidator $orderValidator,
        private LineQuoter $lineQuoter,
        private WorkflowInterface $shopOrderStateMachine,
        private ShopEventPublisher $events,
        private ShopConnector $shopConnector,
    ) {}

    public function __invoke(SellDirect $command): Order
    {
        if ([] === $command->items) {
            throw CartException::empty();
        }

        $player = $this->playerRepository->find($command->playerId) ?? throw new \RuntimeException('Player not found.');

        $order = $this->orderRepository->findOneByPlayerAndWindow($player, $command->window);

        if (OrderStatus::Validated === $order?->status) {
            throw OrderException::windowAlreadyValidated();
        }

        if (!$order instanceof Order) {
            $order = new Order($player, $command->window);
            $this->entityManager->persist($order);
        }

        $order->replaceLines($this->lineQuoter->quote($command->items, $player, $this->shopConnector->buckets()));

        if (OrderStatus::Rejected === $order->status) {
            $this->shopOrderStateMachine->apply($order, 'resubmit');
        }

        $this->orderValidator->validate($order);

        $this->events->publish(new OrderSold($command->playerId, $command->window));

        return $order;
    }
}
