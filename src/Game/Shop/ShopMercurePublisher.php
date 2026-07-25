<?php

declare(strict_types=1);

namespace App\Game\Shop;

use App\Repository\PlayerRepository;
use App\Shop\Event\OrderRejected;
use App\Shop\Event\OrdersErased;
use App\Shop\Event\OrderSubmitted;
use App\Shop\Event\OrderValidated;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * The Game→Shop seam, Mercure side: this is the only publisher of
 * shop-originated Mercure updates. `src/Shop/` dispatches granular,
 * business-named events (`OrderSubmitted`, `OrderValidated`, `OrderRejected`,
 * `OrdersErased`) on `shop.event.bus`; collapsing them onto the two Mercure
 * event names the frontend already listens for (`order-updated`,
 * `player-updated`) is a decision that belongs to this host, not the lib —
 * the lib must never learn about Mercure or these two names.
 *
 * `OrderSold` (`SellDirect`) is deliberately unmapped: `SellDirect` sells by
 * calling `OrderValidator::validate()` internally, which already publishes
 * `OrderValidated` for the same mutation. Mapping `OrderSold` too would
 * double-publish.
 *
 * `OrderRejected` is mapped even though it is unreachable in production:
 * `OrderWorkflowPolicy` blocks the `reject` transition outright. The lib
 * still ships the event, so the seam still translates it.
 *
 * Each handler resolves `playerId` to a `Player` via `PlayerRepository`, then
 * publishes to `'empires/game/'.$player->game->id` — matching the topic the
 * lib used to write directly before this seam existed.
 *
 * Registered on `bus: 'shop.event.bus'` only, never `#[AsEventListener]`:
 * `ShopEventPublisher` dispatches every event on both a PSR-14 dispatcher and
 * this Messenger bus, so subscribing on both channels here would publish
 * every Mercure update twice.
 *
 * Defensive note: `mercure.hub.default.message_handler` is also registered
 * on `shop.event.bus` (for `Symfony\Component\Mercure\Update`). Dispatching
 * an `Update` directly on that bus from inside `src/Shop/` would silently
 * reintroduce the Mercure coupling this seam exists to remove — with no
 * Mercure import visible anywhere in the lib.
 */
final readonly class ShopMercurePublisher
{
    public function __construct(
        private HubInterface $hub,
        private PlayerRepository $playerRepository,
    ) {}

    #[AsMessageHandler(bus: 'shop.event.bus')]
    public function onOrderSubmitted(OrderSubmitted $event): void
    {
        $this->publish($event->playerId, 'order-updated');
    }

    #[AsMessageHandler(bus: 'shop.event.bus')]
    public function onOrderValidated(OrderValidated $event): void
    {
        $this->publish($event->playerId, 'order-updated');
        $this->publish($event->playerId, 'player-updated');
    }

    #[AsMessageHandler(bus: 'shop.event.bus')]
    public function onOrderRejected(OrderRejected $event): void
    {
        $this->publish($event->playerId, 'order-updated');
    }

    #[AsMessageHandler(bus: 'shop.event.bus')]
    public function onOrdersErased(OrdersErased $event): void
    {
        $this->publish($event->playerId, 'player-updated');
        $this->publish($event->playerId, 'order-updated');
    }

    private function publish(Uuid $playerId, string $mercureEvent): void
    {
        $player = $this->playerRepository->find($playerId) ?? throw new \RuntimeException('Player not found.');

        $this->hub->publish(new Update(
            'empires/game/'.$player->game->id,
            sprintf('{"event":"%s"}', $mercureEvent),
        ));
    }
}
