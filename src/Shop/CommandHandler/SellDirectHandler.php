<?php

declare(strict_types=1);

namespace App\Shop\CommandHandler;

use App\Shop\BuyerProviderInterface;
use App\Shop\Command\SellDirect;
use App\Shop\Event\OrderSold;
use App\Shop\Event\ShopEventPublisher;
use App\Shop\Exception\CartException;
use App\Shop\Exception\OrderException;
use App\Shop\OrderInterface;
use App\Shop\OrderRepositoryInterface;
use App\Shop\OrderStatus;
use App\Shop\Service\LineQuoter;
use App\Shop\Service\OrderValidator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Workflow\WorkflowInterface;

#[AsMessageHandler]
final readonly class SellDirectHandler
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private OrderValidator $orderValidator,
        private LineQuoter $lineQuoter,
        private WorkflowInterface $shopOrderStateMachine,
        private ShopEventPublisher $events,
        private BuyerProviderInterface $buyers,
    ) {}

    public function __invoke(SellDirect $command): OrderInterface
    {
        if ([] === $command->items) {
            throw CartException::empty();
        }

        $buyer = $this->buyers->buyerFor($command->playerId);

        $existing = $this->orderRepository->findOneByBuyerAndWindow($command->playerId, $command->window);

        if (OrderStatus::Validated === $existing?->status) {
            throw OrderException::windowAlreadyValidated();
        }

        // Quoted before the order row exists: if this throws (PromotionException on
        // a bad elective allocation), no persisted-but-unflushed empty Order is left
        // dangling in the unit of work for a later flush() to insert.
        $lines = $this->lineQuoter->quote($command->items, $buyer);
        $order = $existing ?? $this->orderRepository->create($command->playerId, $command->window);

        // No scope opened here: these mutations stay in-memory, unflushed. They ride
        // into OrderValidator::validate()'s transactional() call below via the unit
        // of work — the only real scope in this flow. This also keeps every
        // exception validate() can throw (EligibilityException, PromotionException
        // from re-quoting) outside any open scope, so none of them can trigger
        // EntityManager::close().
        $order->replaceLines($lines);

        if (OrderStatus::Rejected === $order->status) {
            $this->shopOrderStateMachine->apply($order, 'resubmit');
        }

        $this->orderValidator->validate($order);

        $this->events->publish(new OrderSold($command->playerId, $command->window));

        return $order;
    }
}
