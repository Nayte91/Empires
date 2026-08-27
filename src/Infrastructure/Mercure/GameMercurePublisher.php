<?php

declare(strict_types=1);

namespace App\Infrastructure\Mercure;

use App\Engine\Event\GameUpdated;
use App\Engine\Event\PlayerUpdated;
use App\Infrastructure\Repository\GameRepository;
use App\Infrastructure\Repository\PlayerRepository;
use App\State\Game;
use App\State\Player;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The single publisher of game-originated Mercure signals: the `src/Engine/Handler/` handlers that
 * mutate a game dispatch a domain event after their own flush, and this turns one into a signal on
 * each screen region the mutation could have changed.
 *
 * `CreateGameHandler` dispatches nothing — a game nobody has open yet has no client to refresh, so
 * the home only shows a fresh game on reload.
 */
final readonly class GameMercurePublisher
{
    /** The topic says which screen; nothing is left for the payload to carry. */
    public const string SIGNAL = '{}';

    public function __construct(
        private HubInterface $hub,
        private PlayerRepository $playerRepository,
        private GameRepository $gameRepository,
        private GameTopics $topics,
        private LoggerInterface $logger,
    ) {}

    /** The shared boards, plus the board of the one player who moved — no other board can differ. */
    #[AsMessageHandler]
    public function onPlayerUpdated(PlayerUpdated $event): void
    {
        $player = $this->playerRepository->find($event->playerId);

        if (!$player instanceof Player) {
            $this->logger->error('Mercure publication skipped: player not found', ['playerId' => (string) $event->playerId]);

            return;
        }

        $game = $player->game;

        $this->publish([...$this->topics->shared($game->id), $this->topics->board($game->id, $player->id)]);
    }

    /** A turn change locks every shop, and no player board reads the turn. */
    #[AsMessageHandler]
    public function onGameUpdated(GameUpdated $event): void
    {
        $game = $this->gameRepository->findById($event->gameId);

        if (!$game instanceof Game) {
            $this->logger->error('Mercure publication skipped: game not found', ['gameId' => (string) $event->gameId]);

            return;
        }

        $shops = array_map(
            fn (Player $player): string => $this->topics->shop($game->id, $player->id),
            $game->players->toArray(),
        );

        $this->publish([...$this->topics->shared($game->id), ...array_values($shops)]);
    }

    /** @param list<string> $topics */
    private function publish(array $topics): void
    {
        foreach ($topics as $topic) {
            $this->hub->publish(new Update($topic, self::SIGNAL));
        }
    }
}
