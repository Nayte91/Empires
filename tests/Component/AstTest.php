<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\State\ASTVersion;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class AstTest extends WebTestCase
{
    use InteractsWithTwigComponents;

    #[Test]
    #[DataProvider('provideTheOpeningStretchRunsUntilTheFirstEraAskingSomethingCases')]
    public function theOpeningStretchRunsUntilTheFirstEraAskingSomething(ASTVersion $version, int $expectedSpan): void
    {
        $game = GameBuilder::create()->build();
        $game->astVersion = $version;

        $this->assertSame($expectedSpan, $this->mountTwigComponent('Ast', ['game' => $game])->getOpeningSpan());
    }

    /** @return iterable<string, array{ASTVersion, int}> */
    public static function provideTheOpeningStretchRunsUntilTheFirstEraAskingSomethingCases(): iterable
    {
        yield 'basic' => [ASTVersion::BASIC, 5];

        yield 'expert' => [ASTVersion::EXPERT, 5];
    }

    #[Test]
    public function theEraHeadersSpanTheWholeTrackAndNothingMore(): void
    {
        $game = GameBuilder::create()->build();

        $component = $this->mountTwigComponent('Ast', ['game' => $game]);

        $this->assertSame($component->getTrackLength(), array_sum(array_column($component->getEraHeaders(), 'span')));
    }

    #[Test]
    public function theThreeLeadersWearGoldSilverAndBronzeAndAScorelessPlayerWearsNone(): void
    {
        $game = GameBuilder::create()->build();
        $leader = PlayerBuilder::named('Alice')->in($game)->withAdvances(['advanced_military'])->withCities(5)->build();
        $runnerUp = PlayerBuilder::named('Bob')->in($game)->withEmpire('saba')->withCities(5)->build();
        $third = PlayerBuilder::named('Carl')->in($game)->withEmpire('assyria')->withCities(3)->build();
        $scoreless = PlayerBuilder::named('Dan')->in($game)->withEmpire('maurya')->build();

        $component = $this->mountTwigComponent('Ast', ['game' => $game]);

        $this->assertSame('gold', $component->medalOf($leader));
        $this->assertSame('silver', $component->medalOf($runnerUp));
        $this->assertSame('bronze', $component->medalOf($third));
        $this->assertNull($component->medalOf($scoreless));
    }
}
