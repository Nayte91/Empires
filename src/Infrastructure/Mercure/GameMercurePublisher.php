<?php

declare(strict_types=1);

namespace App\Infrastructure\Mercure;

use App\Engine\Event\GameUpdated;
use App\Engine\Event\PlayerUpdated;
use App\Infrastructure\Repository\PlayerRepository;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * The single publisher of game-originated Mercure updates (turn advance/rewind, game finish,
 * stat writes and stat actions): every `src/Engine/Handler/` handler dispatches a domain event
 * (`PlayerUpdated`, `GameUpdated`) after its own flush, and this is the only place that turns one
 * into a `player-updated`/`game-updated` Mercure update — the two names the frontend
 * (`mercure-refresh` Stimulus controller) already listens for, unchanged by this move.
 *
 * PlayerUpdated resolves to a Player via PlayerRepository purely to reach the owning game's id;
 * GameUpdated already carries the game id directly.
 *
 * Mirrors {@see ShopMercurePublisher} in shape: one handler method per event, one private
 * publish() building the topic and payload.
 */
final readonly class GameMercurePublisher
{
    public function __construct(
        private HubInterface $hub,
        private PlayerRepository $playerRepository,
    ) {}

    #[AsMessageHandler]
    public function onPlayerUpdated(PlayerUpdated $event): void
    {
        $player = $this->playerRepository->find($event->playerId) ?? throw new \RuntimeException('Player not found.');

        $this->publish($player->game->id, 'player-updated');
    }

    #[AsMessageHandler]
    public function onGameUpdated(GameUpdated $event): void
    {
        $this->publish($event->gameId, 'game-updated');
    }

    private function publish(Uuid $gameId, string $mercureEvent): void
    {
        $this->hub->publish(new Update(
            'empires/game/'.$gameId,
            sprintf('{"event":"%s"}', $mercureEvent),
        ));
    }
}
