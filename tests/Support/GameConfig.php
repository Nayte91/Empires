<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Rules\Ruleset\Advance;
use App\Rules\Ruleset\AdvanceEffectRegistry;
use App\Rules\Ruleset\AdvanceRegistry;
use App\Rules\Ruleset\AstRegistry;
use App\Rules\Ruleset\GameRegistry;
use App\Rules\Ruleset\ScenarioRegistry;
use App\Rules\Ruleset\TradeCardRegistry;
use Symfony\Component\Asset\Package;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy;

/**
 * Built against the real config/game/ files: a unit test that stubbed them would only prove the
 * calculator agrees with the test.
 */
final class GameConfig
{
    /** Memoized: a registry only reads, so a data-provider run shares one parse. */
    private static ?ScenarioRegistry $scenarioRegistry = null;

    private static ?TradeCardRegistry $tradeCardRegistry = null;

    public static function gameRegistry(): GameRegistry
    {
        return new GameRegistry(self::path('game_data.yaml'));
    }

    public static function advanceEffects(): AdvanceEffectRegistry
    {
        return new AdvanceEffectRegistry(self::path('advances.yaml'));
    }

    public static function astRegistry(): AstRegistry
    {
        return new AstRegistry(self::path('ast.yaml'));
    }

    public static function scenarioRegistry(): ScenarioRegistry
    {
        return self::$scenarioRegistry ??= new ScenarioRegistry(self::path('scenarios.yaml'));
    }

    public static function tradeCardRegistry(): TradeCardRegistry
    {
        return self::$tradeCardRegistry ??= new TradeCardRegistry(self::path('trade_cards.yaml'));
    }

    /** The packages serve one field no rule reads, an advance's image URL; hence the empty strategy. */
    public static function advanceRegistry(): AdvanceRegistry
    {
        return new AdvanceRegistry(self::path('advances.yaml'), new Packages(new Package(new EmptyVersionStrategy())));
    }

    /**
     * Spelled out rather than taken from the yaml: naming a shipped advance whose numbers happen to
     * fit ties the test to a balancing decision.
     *
     * @param list<string>       $facets
     * @param array<string, int> $credits
     */
    public static function advance(string $key, int $cost = 0, int $points = 0, array $facets = [], array $credits = []): Advance
    {
        return new Advance(
            key: $key,
            name: str_replace('_', ' ', $key),
            fileName: $key.'.webp',
            cost: $cost,
            points: $points,
            facets: $facets,
            credits: $credits,
            mitigations: [],
            aggravations: [],
        );
    }

    private static function path(string $file): string
    {
        return \dirname(__DIR__, 2).'/config/game/'.$file;
    }
}
