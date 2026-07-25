<?php

declare(strict_types=1);

namespace App\Shop\CommandHandler;

use App\Entity\Order;
use App\Game\Shop\ShopConnector;
use App\Repository\OrderRepository;
use App\Repository\PlayerRepository;
use App\Shop\Command\SubmitOrder;
use App\Shop\Event\OrderSubmitted;
use App\Shop\Event\ShopEventPublisher;
use App\Shop\Exception\CartException;
use App\Shop\Exception\EligibilityException;
use App\Shop\Exception\OrderException;
use App\Shop\OrderStatus;
use App\Shop\Service\LineQuoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Workflow\WorkflowInterface;

#[AsMessageHandler]
final readonly class SubmitOrderHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OrderRepository $orderRepository,
        private PlayerRepository $playerRepository,
        private LineQuoter $lineQuoter,
        private WorkflowInterface $shopOrderStateMachine,
        private ShopEventPublisher $events,
        private ShopConnector $shopConnector,
    ) {}

    public function __invoke(SubmitOrder $command): Order
    {
        if ([] === $command->items) {
            throw CartException::empty();
        }

        $player = $this->playerRepository->find($command->playerId) ?? throw new \RuntimeException('Player not found.');

        $ownedAdvances = $player->advances;

        foreach ($command->items as $item) {
            if (in_array($item->key, $ownedAdvances, true)) {
                throw EligibilityException::productAlreadyOwned($item->key);
            }
        }

        $order = $this->orderRepository->findOneByPlayerAndWindow($player, $command->window);

        if (OrderStatus::Validated === $order?->status) {
            throw OrderException::windowAlreadyValidated();
        }

        if (!$order instanceof Order) {
            $order = new Order($player, $command->window);
            $this->entityManager->persist($order);
        }

        $buyer = $this->shopConnector->buyerFor($player);
        $order->replaceLines($this->lineQuoter->quote($command->items, $buyer, $this->shopConnector->facets()));

        // Canon rule: submitting onto a rejected slot reopens it.
        if (OrderStatus::Rejected === $order->status) {
            $this->shopOrderStateMachine->apply($order, 'resubmit');
        }

        $this->entityManager->flush();

        $this->events->publish(new OrderSubmitted($command->playerId, $command->window));

        return $order;
    }
}
