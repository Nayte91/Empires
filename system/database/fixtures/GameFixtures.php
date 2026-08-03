<?php

declare(strict_types=1);

namespace System\database\fixtures;

use App\State\ASTVersion;
use App\State\CreditEntry;
use App\State\Game;
use App\State\Order;
use App\State\Player;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\OrderStatus;

/**
 * Loads every game described in this directory — one YAML file per game, no code to touch when
 * another one is added. The files hold finished (or half-played) games captured from real play;
 * see bretonneux.yaml for the shape and the reasons behind it.
 *
 * Games are rebuilt through the entities' own API rather than by writing rows: statuses go
 * through the state machine's marking, advances through ownAdvances(), credits through
 * postCredit(). The fixture therefore breaks the moment an entity's contract changes, which is
 * the point — a fixture that bypassed the domain would keep loading long after it stopped
 * describing anything real.
 */
final class GameFixtures extends Fixture
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/system/database/fixtures')] private readonly string $fixturesPath,
    ) {}

    public function load(ObjectManager $manager): void
    {
        foreach ($this->files() as $file) {
            $this->loadGame(Yaml::parseFile($file), $manager);
        }

        $manager->flush();
    }

    /** @return list<string> */
    private function files(): array
    {
        $files = glob($this->fixturesPath.'/*.yaml');

        if (false === $files) {
            throw new \RuntimeException(\sprintf('Cannot read fixtures directory "%s".', $this->fixturesPath));
        }

        sort($files);

        return $files;
    }

    /** @param array{game: array<string, mixed>, players: list<array<string, mixed>>} $data */
    private function loadGame(array $data, ObjectManager $manager): void
    {
        $game = new Game($data['game']['slug']);
        $game->currentTurn = $data['game']['current_turn'];
        $game->playerCount = $data['game']['player_count'];
        $game->astVersion = ASTVersion::from($data['game']['ast_version']);
        $game->region = $data['game']['region'];

        // Assigned rather than derived: a game is finished because the file says so, and this is
        // what decides whether the app serves the chronicle or the dashboard.
        if (isset($data['game']['finished_at'])) {
            $game->finishedAt = new \DateTimeImmutable($data['game']['finished_at']);
        }

        $manager->persist($game);

        foreach ($data['players'] as $playerData) {
            $this->loadPlayer($game, $playerData, $manager);
        }
    }

    /** @param array<string, mixed> $data */
    private function loadPlayer(Game $game, array $data, ObjectManager $manager): void
    {
        $player = new Player($game, $data['name'], $data['empire']);

        // The slug is derived from the name by the entity, so the file cannot impose one. Rather
        // than drop the column silently, it is checked: a mismatch means the fixture was written
        // against different slugify() rules and its stored slug no longer describes what loads.
        if ($player->slug !== $data['slug']) {
            throw new \RuntimeException(\sprintf(
                'Player "%s" slugifies to "%s", but the fixture records "%s".',
                $data['name'],
                $player->slug,
                $data['slug'],
            ));
        }

        $player->cities = $data['cities'];
        $player->census = $data['census'];
        $player->treasury = $data['treasury'];
        $player->ships = $data['ships'];
        $player->cards = $data['cards'];
        $player->astPosition = $data['ast_position'];

        $player->ownAdvances($data['advances']);

        foreach ($data['credit_ledger'] as $entry) {
            $player->postCredit(CreditEntry::fromArray($entry));
        }

        $manager->persist($player);

        foreach ($data['orders'] as $orderData) {
            $this->loadOrder($player, $orderData, $manager);
        }
    }

    /** @param array{turn: int, status: string, total: ?int, lines: list<array<string, mixed>>} $data */
    private function loadOrder(Player $player, array $data, ObjectManager $manager): void
    {
        $order = new Order($player, $data['turn']);
        $lines = array_map(OrderLine::fromArray(...), $data['lines']);
        $status = OrderStatus::from($data['status']);

        // freeze() is what a validated order actually went through: it locks the lines, keeps the
        // total the game charged at the time, and stamps validatedAt. Re-quoting instead would
        // recompute today's prices and quietly rewrite the history the file exists to preserve.
        if (OrderStatus::Validated === $status) {
            $order->freeze($lines, $data['total'] ?? 0);
        } else {
            $order->replaceLines($lines);
        }

        $order->setMarking($status->value);

        $manager->persist($order);
    }
}
