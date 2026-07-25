<?php

declare(strict_types=1);

namespace App\Shop\Service;

use App\Entity\Order;
use App\Game\Shop\ShopConnector;
use App\Shop\Dto\OrderLine;
use App\Shop\Exception\EligibilityException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Workflow\WorkflowInterface;

final readonly class OrderValidator
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LineQuoter $lineQuoter,
        private HubInterface $hub,
        private WorkflowInterface $shopOrderStateMachine,
        private ShopConnector $shopConnector,
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
        $frozenLines = $this->lineQuoter->quote($intents, $player, $this->shopConnector->buckets());
        $total = array_sum(array_map(static fn (OrderLine $line): int => $line->netCost, $frozenLines));

        $hub = $this->hub;
        $machine = $this->shopOrderStateMachine;

        $this->entityManager->wrapInTransaction(static function () use ($order, $frozenLines, $total, $player, $slugs, $hub, $machine): void {
            $machine->apply($order, 'validate');
            $order->freeze($frozenLines, $total);
            $player->ownAdvances($slugs);

            $topic = 'empires/game/'.$player->game->id;

            $hub->publish(new Update($topic, '{"event":"order-updated"}'));
            $hub->publish(new Update($topic, '{"event":"player-updated"}'));
        });
    }
}
