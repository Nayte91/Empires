<?php

declare(strict_types=1);

namespace App\Infrastructure\Mercure;

use App\Infrastructure\Repository\PlayerRepository;
use App\State\Player;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;
use Userforged\ShopEngine\Event\OrdersErased;
use Userforged\ShopEngine\Event\OrderSubmitted;
use Userforged\ShopEngine\Event\OrderValidated;

/**
 * The Game→Shop seam, Mercure side: this is the only publisher of
 * shop-originated Mercure updates. `packages/userforged/shop-engine/`
 * dispatches granular,
 * business-named events (`OrderSubmitted`, `OrderValidated`, `OrdersErased`)
 * on `shop.event.bus`; collapsing them onto the two Mercure
 * event names the frontend already listens for (`order-updated`,
 * `player-updated`) is a decision that belongs to this host, not the lib —
 * the lib must never learn about Mercure or these two names.
 *
 * `OrderSold` (`SellDirect`) is deliberately unmapped: `SellDirect` sells by
 * calling `OrderValidator::validate()` internally, which already publishes
 * `OrderValidated` for the same mutation. Mapping `OrderSold` too would
 * double-publish.
 *
 * Registered on `bus: 'shop.event.bus'` only, never `#[AsEventListener]`:
 * `ShopEventPublisher` dispatches every event on both a PSR-14 dispatcher and
 * this Messenger bus, so subscribing on both channels here would publish
 * every Mercure update twice.
 *
 * Defensive note: `mercure.hub.default.message_handler` is also registered
 * on `shop.event.bus` (for `Symfony\Component\Mercure\Update`). Dispatching
 * an `Update` directly on that bus from inside `packages/userforged/shop-engine/`
 * would silently
 * reintroduce the Mercure coupling this seam exists to remove — with no
 * Mercure import visible anywhere in the lib.
 */
final readonly class ShopMercurePublisher
{
    public function __construct(
        private HubInterface $hub,
        private PlayerRepository $playerRepository,
        private GameTopics $topics,
    ) {}

    /** A submitted order changes what the console owes the operator, and nothing else. */
    #[AsMessageHandler(bus: 'shop.event.bus')]
    public function onOrderSubmitted(OrderSubmitted $event): void
    {
        $player = $this->player($event->buyerId);

        $this->publish([
            $this->topics->operator($player->game->id),
            $this->topics->shop($player->game->id, $player->id),
        ]);
    }

    /** A purchase moves the player's own state, so the shared boards move with it. */
    #[AsMessageHandler(bus: 'shop.event.bus')]
    public function onOrderValidated(OrderValidated $event): void
    {
        $this->publishPurchase($event->buyerId);
    }

    #[AsMessageHandler(bus: 'shop.event.bus')]
    public function onOrdersErased(OrdersErased $event): void
    {
        $this->publishPurchase($event->buyerId);
    }

    private function publishPurchase(Uuid $playerId): void
    {
        $player = $this->player($playerId);
        $gameId = $player->game->id;

        $this->publish([
            ...$this->topics->shared($gameId),
            $this->topics->board($gameId, $player->id),
            $this->topics->shop($gameId, $player->id),
        ]);
    }

    /** @param list<string> $topics */
    private function publish(array $topics): void
    {
        foreach ($topics as $topic) {
            $this->hub->publish(new Update($topic, GameMercurePublisher::SIGNAL));
        }
    }

    private function player(Uuid $playerId): Player
    {
        return $this->playerRepository->find($playerId) ?? throw new \RuntimeException('Player not found.');
    }
}
