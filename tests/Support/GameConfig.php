<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Game\AdvanceEffectCatalog;
use App\Game\GameData;

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
    public static function gameData(): GameData
    {
        return new GameData(self::path('game_data.yaml'));
    }

    public static function advanceEffects(): AdvanceEffectCatalog
    {
        return new AdvanceEffectCatalog(self::path('advances.yaml'));
    }

    private static function path(string $file): string
    {
        return \dirname(__DIR__, 2).'/config/game/'.$file;
    }
}
