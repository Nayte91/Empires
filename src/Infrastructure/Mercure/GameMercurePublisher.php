<?php

declare(strict_types=1);

namespace App\Infrastructure\Mercure;

use App\Engine\Event\GameUpdated;
use App\Engine\Event\PlayerUpdated;
use App\Infrastructure\Repository\PlayerRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * The single publisher of game-originated Mercure updates (turn advance/rewind, game finish,
 * stat writes and stat actions): the `src/Engine/Handler/` handlers that mutate an existing game
 * dispatch a domain event (`PlayerUpdated`, `GameUpdated`) after their own flush, and this is the
 * only place that turns one into a `player-updated`/`game-updated` Mercure update — the two names
 * the frontend (`mercure-refresh` Stimulus controller) already listens for, unchanged by this move.
 *
 * Exclusive producer of `game-updated` only: {@see ShopMercurePublisher} emits `player-updated` too.
 * `CreateGameHandler` is the one handler that dispatches nothing — a game nobody has open yet has
 * no client to refresh, so the home only shows a fresh game on reload.
 */
final readonly class GameMercurePublisher
{
    public function __construct(
        private HubInterface $hub,
        private PlayerRepository $playerRepository,
        private LoggerInterface $logger,
    ) {}

    #[AsMessageHandler]
    public function onPlayerUpdated(PlayerUpdated $event): void
    {
        $player = $this->playerRepository->find($event->playerId);

        if (null === $player) {
            $this->logger->error('Mercure publication skipped: player not found', ['playerId' => (string) $event->playerId]);

            return;
        }

        $this->publish($player->game->id, 'player-updated');
    }

    #[AsMessageHandler]
    public function onGameUpdated(GameUpdated $event): void
    {
        $this->publish($event->gameId, 'game-updated');
    }

    // REFACTOR-WHEN: first private Update, per-player topic, or a second topic shape
    // → single topic builder (PHP service + Twig function) emitting IRI-form topics. The form is
    //   spelled out at every call site while the topic stays public and guards nothing.
    private function publish(Uuid $gameId, string $mercureEvent): void
    {
        $this->hub->publish(new Update(
            'empires/game/'.$gameId,
            sprintf('{"event":"%s"}', $mercureEvent),
        ));
    }
}
