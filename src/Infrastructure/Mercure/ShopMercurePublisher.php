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

final readonly class ShopMercurePublisher
{
    public function __construct(
        private HubInterface $hub,
        private PlayerRepository $playerRepository,
        private GameTopics $topics,
        private DashboardStreamPublisher $dashboard,
    ) {}

    #[AsMessageHandler(bus: 'shop.event.bus')]
    public function onOrderSubmitted(OrderSubmitted $event): void
    {
        $player = $this->player($event->buyerId);

        $this->publish([
            $this->topics->operator($player->game->id),
            $this->topics->shop($player->game->id, $player->id),
        ]);
    }

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

        $this->dashboard->publish($player->game);
        $this->publish([
            $this->topics->operator($gameId),
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
