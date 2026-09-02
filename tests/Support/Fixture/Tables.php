<?php

declare(strict_types=1);

namespace App\Tests\Support\Fixture;

use App\Rules\Ruleset\Scenario;
use App\State\Game;
use App\State\Player;
use App\State\Region;
use App\Tests\Support\GameConfig;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One seating per screen, not a factory: a table growing arguments puts back the construction noise
 * the builders removed. Reach a player through seat() — an index into the roster is a value the
 * test cannot see.
 */
final class Tables
{
    /** @var list<string> */
    private const array NAMES = [
        'Alice', 'Bob', 'Carol', 'Dave', 'Eve', 'Frank', 'Grace', 'Heidi',
        'Ivan', 'Judy', 'Kim', 'Leo', 'Mia', 'Ned', 'Oscar',
    ];

    /**
     * Turn 1, two players: Alice owns agriculture, Bob owns nothing.
     *
     * @return array{Game, Player, Player}
     */
    public static function aliceAndBob(EntityManagerInterface $entityManager): array
    {
        $game = GameBuilder::create()->build();

        return [
            $game,
            PlayerBuilder::named('Alice')->in($game)->withAdvances(['agriculture'])->persist($entityManager),
            PlayerBuilder::named('Bob')->in($game)->persist($entityManager),
        ];
    }

    /**
     * Running, turn 4, West.
     * Alice/minoa, Bob/hellas, Carol/assyria, Dave/egypt, Eve/hatti.
     * Alice owns pottery and agriculture; cities 3, population 7, treasury 40, cards 5, ships 2.
     * Everyone else is on the builder defaults.
     */
    public static function westTable(EntityManagerInterface $entityManager): Game
    {
        $game = GameBuilder::create()->withCurrentTurn(4)->withPlayerCount(5)->withRegion(Region::West)->persist($entityManager);
        $empires = self::leadWith(self::empiresOf(5, Region::West), 'minoa', 'hellas');

        PlayerBuilder::named('Alice')->in($game)
            ->withEmpire($empires[0])
            ->withAdvances(['pottery', 'agriculture'])
            ->withCities(3)
            ->withCensus(7)
            ->withTreasury(40)
            ->withCards(5)
            ->withShips(2)
            ->persist($entityManager)
        ;

        self::seatTheRest($entityManager, $game, $empires);

        return $game;
    }

    /**
     * Running, turn 4, East.
     * Alice/babylon, Bob/nubia, Carol/parthia, Dave/persia, Eve/saba.
     * No advances, every stat on its default.
     */
    public static function eastTable(EntityManagerInterface $entityManager): Game
    {
        $game = GameBuilder::create()->withCurrentTurn(4)->withPlayerCount(5)->withRegion(Region::East)->persist($entityManager);
        $empires = self::empiresOf(5, Region::East);

        PlayerBuilder::named('Alice')->in($game)->withEmpire($empires[0])->persist($entityManager);

        self::seatTheRest($entityManager, $game, $empires);

        return $game;
    }

    /**
     * Finished, turn 12, no region: the `15` scenario, fifteen seats, Alice/minoa first.
     * Alice owns pottery and agriculture; nobody else owns anything.
     * Each seat is scored off its place: cities = place modulo 10, A.S.T. position = place.
     */
    public static function grandTable(EntityManagerInterface $entityManager): Game
    {
        $game = GameBuilder::create()->withCurrentTurn(12)->withPlayerCount(15)->withRegion(null)->finished()->persist($entityManager);
        $empires = self::leadWith(self::empiresOf(15, null), 'minoa');

        PlayerBuilder::named('Alice')->in($game)
            ->withEmpire($empires[0])
            ->withAdvances(['pottery', 'agriculture'])
            ->withCities(0)
            ->withAstPosition(0)
            ->persist($entityManager)
        ;

        foreach (\array_slice($empires, 1, preserve_keys: true) as $place => $empire) {
            PlayerBuilder::named(self::NAMES[$place])->in($game)
                ->withEmpire($empire)
                ->withCities($place % 10)
                ->withAstPosition($place)
                ->persist($entityManager)
            ;
        }

        return $game;
    }

    /**
     * Running, turn 1, West, nine seats: Alice/minoa, Bob/assyria, Carol/carthage, Dave/celt,
     * Eve/egypt, Frank/hatti, Grace/hellas, Heidi/iberia, Ivan/rome.
     * Nothing bought, nothing scored.
     */
    public static function typicalTable(EntityManagerInterface $entityManager): Game
    {
        $game = GameBuilder::create()->withCurrentTurn(1)->withPlayerCount(9)->withRegion(Region::West)->persist($entityManager);
        $empires = self::leadWith(self::empiresOf(9, Region::West), 'minoa');

        PlayerBuilder::named('Alice')->in($game)->withEmpire($empires[0])->persist($entityManager);

        self::seatTheRest($entityManager, $game, $empires);

        return $game;
    }

    public static function seat(Game $game, string $name): Player
    {
        foreach ($game->players as $player) {
            if ($player->name === $name) {
                return $player;
            }
        }

        throw new \LogicException(\sprintf('No seat named "%s" at the table of game "%s".', $name, $game->slug));
    }

    /** @param list<string> $empires */
    private static function seatTheRest(EntityManagerInterface $entityManager, Game $game, array $empires): void
    {
        foreach (\array_slice($empires, 1, preserve_keys: true) as $place => $empire) {
            PlayerBuilder::named(self::NAMES[$place])->in($game)->withEmpire($empire)->persist($entityManager);
        }
    }

    /** @return list<string> */
    private static function empiresOf(int $playerCount, ?Region $region): array
    {
        $scenario = GameConfig::scenarioRegistry()->find($playerCount, $region);

        if (!$scenario instanceof Scenario) {
            throw new \LogicException(\sprintf('scenarios.yaml no longer describes %d players in %s.', $playerCount, $region?->value ?? 'both blocks'));
        }

        return $scenario->empires;
    }

    /**
     * @param list<string> $empires
     *
     * @return list<string>
     */
    private static function leadWith(array $empires, string ...$first): array
    {
        return [...$first, ...array_values(array_diff($empires, $first))];
    }
}
