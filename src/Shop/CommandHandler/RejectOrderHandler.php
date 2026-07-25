<?php

declare(strict_types=1);

namespace App\Shop\CommandHandler;

use App\Entity\Order;
use App\Repository\OrderRepository;
use App\Repository\PlayerRepository;
use App\Shop\Command\RejectOrder;
use App\Shop\Event\OrderRejected;
use App\Shop\Event\ShopEventPublisher;
use App\Shop\Exception\OrderException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Workflow\WorkflowInterface;

#[AsMessageHandler]
final readonly class RejectOrderHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OrderRepository $orderRepository,
        private PlayerRepository $playerRepository,
        private HubInterface $hub,
        private WorkflowInterface $shopOrderStateMachine,
        private ShopEventPublisher $events,
    ) {}

    public function __invoke(RejectOrder $command): void
    {
        $player = $this->playerRepository->find($command->playerId) ?? throw new \RuntimeException('Player not found.');

        $order = $this->orderRepository->findOneByPlayerAndWindow($player, $command->window);

        if (!$order instanceof Order) {
            return;
        }

        if (!$this->shopOrderStateMachine->can($order, 'reject')) {
            throw OrderException::rejectionUnavailable();
        }

        $this->shopOrderStateMachine->apply($order, 'reject');

        $this->entityManager->flush();

        $this->hub->publish(new Update(
            'empires/game/'.$order->player->game->id,
            '{"event":"order-updated"}',
        ));

        $this->events->publish(new OrderRejected($command->playerId, $command->window));
    }
}
