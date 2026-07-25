<?php

declare(strict_types=1);

namespace App\Game\Shop;

use App\Entity\Order;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\GuardEvent;

/**
 * The Game→Shop seam, workflow side: `shop_order` (src/Shop/) is canon-complete
 * and ships a `reject` transition — the lib stays generic, it doesn't know
 * Mega Civilization has an opinion on it. This is the one place where our
 * context expresses that opinion, by blocking the transition outright.
 *
 * Mega Civilization runs a single peer-validated purchase phase per turn: a
 * purchase is either validated or it never happened. There is no such thing
 * as a refused purchase to keep around — so `reject` is unreachable here by
 * declared policy, not by an accidental absence of a caller.
 */
final class OrderWorkflowPolicy
{
    /** @param GuardEvent<Order> $event */
    #[AsEventListener(event: 'workflow.shop_order.guard.reject')]
    public function blockReject(GuardEvent $event): void
    {
        $event->setBlocked(
            true,
            'Mega Civilization plays a single peer-validated purchase phase per turn — a refused purchase is simply no purchase.',
        );
    }
}
