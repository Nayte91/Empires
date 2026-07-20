<?php

declare(strict_types=1);

namespace App\Shop\Service;

use App\Entity\Order;
use App\Game\AdvanceCatalog;
use App\Game\Dto\Advance;
use App\Shop\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final readonly class OrderValidator
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AdvanceCatalog $advanceCatalog,
        private PriceCalculator $priceCalculator,
        private HubInterface $hub,
    ) {}

    public function validate(Order $order): void
    {
        if (OrderStatus::Validated === $order->status) {
            throw new \DomainException('Order is already validated.');
        }

        $player = $order->player;
        $ownedSlugs = $player->advances;

        /** @var list<string> $slugs */
        $slugs = $order->lines;

        foreach ($slugs as $slug) {
            if (in_array($slug, $ownedSlugs, true)) {
                throw new \DomainException(sprintf('Advance "%s" is already owned.', $slug));
            }
        }

        /** @var list<Advance> $advances */
        $advances = $this->advanceCatalog->getAdvancesByNames($slugs);

        /** @var list<Advance> $ownedAdvances */
        $ownedAdvances = $this->advanceCatalog->getAdvancesByNames($ownedSlugs);

        $frozenLines = [];
        $total = 0;

        foreach ($advances as $advance) {
            $netCost = $this->priceCalculator->netCost($advance, $ownedAdvances);
            $frozenLines[] = ['key' => $advance->key, 'netCost' => $netCost];
            $total += $netCost;
        }

        $hub = $this->hub;

        $this->entityManager->wrapInTransaction(static function () use ($order, $frozenLines, $total, $player, $slugs, $hub): void {
            $order->validate($frozenLines, $total);
            $player->ownAdvances($slugs);

            $topic = 'empires/game/'.$player->game->id;

            $hub->publish(new Update($topic, '{"event":"order-updated"}'));
            $hub->publish(new Update($topic, '{"event":"player-updated"}'));
        });
    }
}
