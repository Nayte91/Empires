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
 * The topic and the event name are a contract shared with the `mercure-refresh` Stimulus controller
 * and with nothing else.
 *
 * Two payload shapes, and which is which is the whole design: the dashboard regions travel already
 * rendered, because nothing on that screen holds state a replace could destroy; every other region
 * travels as a signal its own component answers by re-rendering itself.
 */
final readonly class GameMercurePublisher
{
    public const string SIGNAL = '{}';

    public function __construct(
        private HubInterface $hub,
        private PlayerRepository $playerRepository,
        private GameRepository $gameRepository,
        private GameTopics $topics,
        private DashboardStreamPublisher $dashboard,
        private LoggerInterface $logger,
    ) {}

    #[AsMessageHandler]
    public function onPlayerUpdated(PlayerUpdated $event): void
    {
        $player = $this->playerRepository->find($event->playerId);

        if (!$player instanceof Player) {
            $this->logger->error('Mercure publication skipped: player not found', ['playerId' => (string) $event->playerId]);

            return;
        }

        $game = $player->game;

        $this->dashboard->publish($game);
        $this->publish([$this->topics->operator($game->id), $this->topics->board($game->id, $player->id)]);
    }

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

        $this->dashboard->publish($game);
        $this->publish([$this->topics->operator($game->id), ...array_values($shops)]);
    }

    /** @param list<string> $topics */
    private function publish(array $topics): void
    {
        foreach ($topics as $topic) {
            $this->hub->publish(new Update($topic, self::SIGNAL));
        }
    }
}
