<?php

declare(strict_types=1);

namespace App\Tests\Integration\Rules;

use App\Rules\StandingsCalculator;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StandingsCalculatorTest extends WebTestCase
{
    use GameFixtureTrait;

    private StandingsCalculator $standingsCalculator; // @phpstan-ignore property.uninitialized (initialized in setUp)

    protected function setUp(): void
    {
        $this->initEntityManager();

        $this->standingsCalculator = self::getContainer()->get(StandingsCalculator::class);
    }

    #[Test]
    public function theTableIsOrderedByScoreLeaderFirst(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $middle = PlayerBuilder::named('Bob')->in($game)->withCities(4)->persist($this->entityManager);
        $leader = PlayerBuilder::named('Alice')->in($game)->withCities(9)->persist($this->entityManager);
        $last = PlayerBuilder::named('Carol')->in($game)->withCities(1)->persist($this->entityManager);

        $this->assertSame([$leader, $middle, $last], $this->standingsCalculator->standings($game));
        $this->assertSame(1, $this->standingsCalculator->rankOf($leader));
        $this->assertSame(3, $this->standingsCalculator->rankOf($last));
    }

    #[Test]
    public function theGapIsMeasuredAgainstTheLeaderAndIsZeroForThemselves(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $leader = PlayerBuilder::named('Alice')->in($game)->withCities(9)->persist($this->entityManager);
        $chaser = PlayerBuilder::named('Bob')->in($game)->withCities(4)->persist($this->entityManager);

        $this->assertSame(0, $this->standingsCalculator->gapToLeader($leader));
        $this->assertSame(5, $this->standingsCalculator->gapToLeader($chaser));
        $this->assertSame(5, $this->standingsCalculator->leadOverRunnerUp($leader));
    }

    #[Test]
    public function aSoloPlayerHasNoRunnerUpToMeasureAgainst(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $alone = PlayerBuilder::named('Alice')->in($game)->withCities(5)->persist($this->entityManager);

        $this->assertNull($this->standingsCalculator->leadOverRunnerUp($alone));
    }

    #[Test]
    public function tiedLeadersBothShowAZeroMargin(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $first = PlayerBuilder::named('Alice')->in($game)->withCities(6)->persist($this->entityManager);
        $second = PlayerBuilder::named('Bob')->in($game)->withCities(6)->persist($this->entityManager);

        $this->assertSame(0, $this->standingsCalculator->leadOverRunnerUp($first));
        $this->assertSame(0, $this->standingsCalculator->gapToLeader($second));
    }
}
