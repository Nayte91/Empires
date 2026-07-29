<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Rules\Ruleset\AdvanceEffectRegistry;
use App\Rules\Ruleset\AdvanceRegistry;
use App\Rules\Ruleset\AstRegistry;
use App\Rules\Ruleset\GameRegistry;
use Symfony\Component\Asset\Package;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy;

/**
 * The yaml readers, built against the real config/game/ files, for tests that construct a
 * calculator by hand rather than pulling it from the container.
 *
 * Reading the shipped yaml is deliberate: these are the rules the game actually plays by, and a
 * unit test that stubbed them would only prove the calculator agrees with the test. It also spares
 * every such test from counting \dirname() levels back to the project root.
 */
final class GameConfig
{
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

    /** The packages serve one field no rule reads, an advance's image URL; hence the empty strategy. */
    public static function advanceRegistry(): AdvanceRegistry
    {
        return new AdvanceRegistry(self::path('advances.yaml'), new Packages(new Package(new EmptyVersionStrategy())));
    }

    private static function path(string $file): string
    {
        return \dirname(__DIR__, 2).'/config/game/'.$file;
    }
}
