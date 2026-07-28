<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rules\Advisory;

use App\State\Game;
use App\State\Player;
use App\Rules\Advisory\AstAdvancementRule;
use App\Rules\Ruleset\AstRegistry;
use App\State\ASTVersion;
use App\Rules\Advisory\Advisory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AstAdvancementRuleTest extends TestCase
{
    private AstAdvancementRule $rule;

    protected function setUp(): void
    {
        $this->rule = new AstAdvancementRule(new AstRegistry(\dirname(__DIR__, 4).'/config/game/ast.yaml'));
    }

    #[Test]
    #[DataProvider('provideAdvisoryFiresWhenCitiesFallShortOfNextEraCases')]
    public function advisoryFiresWhenCitiesFallShortOfNextEra(ASTVersion $astVersion, ?string $empire, int $astPosition, int $cities, string $expectedMessage): void
    {
        $game = new Game();
        $game->astVersion = $astVersion;
        $player = new Player($game, 'Bob');
        $player->empire = $empire;
        $player->astPosition = $astPosition;
        $player->cities = $cities;

        $advisory = $this->rule->evaluate($player);

        $this->assertInstanceOf(Advisory::class, $advisory);
        $this->assertSame($expectedMessage, $advisory->message);
    }

    /** @return iterable<string, array{ASTVersion, ?string, int, int, string}> */
    public static function provideAdvisoryFiresWhenCitiesFallShortOfNextEraCases(): iterable
    {
        yield 'standard basic empire falls short of the Middle Bronze Age' => [ASTVersion::BASIC, 'assyria', 7, 2, '3 cities are required for the Middle Bronze Age.'];

        yield 'expert extended stone age empire falls short of the Middle Bronze Age' => [ASTVersion::EXPERT, 'kushan', 7, 1, '3 cities are required for the Middle Bronze Age.'];

        yield 'null empire falls back to the standard group and still fires' => [ASTVersion::BASIC, null, 7, 2, '3 cities are required for the Middle Bronze Age.'];
    }

    #[Test]
    #[DataProvider('provideNoAdvisoryCasesCases')]
    public function noAdvisoryCases(ASTVersion $astVersion, ?string $empire, int $astPosition, int $cities): void
    {
        $game = new Game();
        $game->astVersion = $astVersion;
        $player = new Player($game, 'Bob');
        $player->empire = $empire;
        $player->astPosition = $astPosition;
        $player->cities = $cities;

        $this->assertNotInstanceOf(Advisory::class, $this->rule->evaluate($player));
    }

    /** @return iterable<string, array{ASTVersion, ?string, int, int}> */
    public static function provideNoAdvisoryCasesCases(): iterable
    {
        yield 'player already meets the next era requirement' => [ASTVersion::BASIC, 'assyria', 7, 3];

        yield 'slow starter empire meets its own lower early bronze age requirement' => [ASTVersion::BASIC, 'minoa', 7, 2];

        yield 'player at the last position of their track has nothing to advance into' => [ASTVersion::BASIC, 'assyria', 15, 0];
    }
}
