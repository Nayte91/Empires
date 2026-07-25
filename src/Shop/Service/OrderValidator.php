<?php

declare(strict_types=1);

namespace App\Shop\Service;

use App\Entity\Order;
use App\Game\Shop\ShopConnector;
use App\Shop\Dto\OrderLine;
use App\Shop\Event\OrderValidated;
use App\Shop\Event\ShopEventPublisher;
use App\Shop\Exception\EligibilityException;
use App\Shop\FulfillmentInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Workflow\WorkflowInterface;

final readonly class OrderValidator
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LineQuoter $lineQuoter,
        private WorkflowInterface $shopOrderStateMachine,
        private ShopConnector $shopConnector,
        private ShopEventPublisher $events,
        private FulfillmentInterface $fulfillment,
    ) {}

    public function validate(Order $order): void
    {
        $player = $order->player;
        $ownedSlugs = $player->advances;

        $slugs = $order->keys();

        foreach ($slugs as $slug) {
            if (in_array($slug, $ownedSlugs, true)) {
                throw EligibilityException::productAlreadyOwned($slug);
            }
        }

        $intents = $this->lineQuoter->intentsFromLines($order->lines());
        // Built right here, not before: the order is still Pending at this point,
        // so it's correctly excluded from its own elective credits (see
        // ShopConnector::buyerFor()'s no-self-crediting note). Never hoist this
        // above the transition below, and never reuse a buyer across it.
        $buyer = $this->shopConnector->buyerFor($player);
        $frozenLines = $this->lineQuoter->quote($intents, $buyer, $this->shopConnector->facets());
        $total = array_sum(array_map(static fn (OrderLine $line): int => $line->netCost, $frozenLines));

        $machine = $this->shopOrderStateMachine;
        $fulfillment = $this->fulfillment;

        $this->entityManager->wrapInTransaction(static function () use ($order, $frozenLines, $total, $player, $slugs, $machine, $fulfillment): void {
            $machine->apply($order, 'validate');
            $order->freeze($frozenLines, $total);
            $fulfillment->grant($player->id, $slugs);
        });

        $this->events->publish(new OrderValidated($player->id, $order->turn));
    }
}
