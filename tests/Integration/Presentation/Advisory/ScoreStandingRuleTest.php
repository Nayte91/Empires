<?php

declare(strict_types=1);

namespace App\Tests\Integration\Presentation\Advisory;

use App\Presentation\Advisory\ScoreStandingRule;
use App\Presentation\Advisory\AdvisoryLevel;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ScoreStandingRuleTest extends WebTestCase
{
    use GameFixtureTrait;

    private ScoreStandingRule $rule; // @phpstan-ignore property.uninitialized (initialized in setUp)

    protected function setUp(): void
    {
        $this->initEntityManager();

        $this->rule = self::getContainer()->get(ScoreStandingRule::class);
    }

    #[Test]
    public function theLeaderReadsTheirMarginOverTheRunnerUp(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $leader = PlayerBuilder::named('Alice')->in($game)->withCities(9)->persist($this->entityManager);
        PlayerBuilder::named('Bob')->in($game)->withCities(4)->persist($this->entityManager);

        $advisory = $this->rule->evaluate($leader);

        $this->assertSame('You lead by 5 VP', $advisory->message);
        $this->assertSame(AdvisoryLevel::Good, $advisory->level);
    }

    #[Test]
    public function aChaserReadsTheirPlaceAndDeficit(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withCities(9)->persist($this->entityManager);
        PlayerBuilder::named('Bob')->in($game)->withCities(6)->persist($this->entityManager);
        $third = PlayerBuilder::named('Carol')->in($game)->withCities(1)->persist($this->entityManager);

        $advisory = $this->rule->evaluate($third);

        $this->assertSame('You are 3rd, 8 VP behind the leader', $advisory->message);
        $this->assertSame(AdvisoryLevel::Neutral, $advisory->level);
    }

    #[Test]
    public function tiedLeadersShareTheLead(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $first = PlayerBuilder::named('Alice')->in($game)->withCities(6)->persist($this->entityManager);
        PlayerBuilder::named('Bob')->in($game)->withCities(6)->persist($this->entityManager);

        $this->assertSame('You share the lead', $this->rule->evaluate($first)->message);
    }

    /** Nobody to compare against reads as leading, never as sharing. */
    #[Test]
    public function aSoloPlayerSimplyLeads(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $alone = PlayerBuilder::named('Alice')->in($game)->withCities(5)->persist($this->entityManager);

        $this->assertSame('You lead the game', $this->rule->evaluate($alone)->message);
    }
}
