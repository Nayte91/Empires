<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\Fixture\Tables;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class PlayerSagaTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithTwigComponents;

    #[Test]
    public function theFinalCountersAreTheFiveTrackedStatsAndNotTheAstPosition(): void
    {
        $player = Tables::seat(Tables::westTable($this->entityManager), 'Alice');

        $rows = $this->renderTwigComponent('PlayerSaga', ['player' => $player])->crawler()->filter('dl > div[data-stat]');

        $this->assertSame(['cities', 'ships', 'census', 'treasury', 'cards'], $rows->each(static fn (Crawler $row): ?string => $row->attr('data-stat')));
        $this->assertSame(['Cities', 'Ships', 'Population', 'Treasury', 'Cards'], $rows->each(static fn (Crawler $row): string => $row->filter('dt')->text()));
        $this->assertSame(['3', '2', '7', '40', '5'], $rows->each(static fn (Crawler $row): string => $row->filter('dd')->text()));
    }

    #[Test]
    public function theAdvancesHeadingCarriesWhatThoseAdvancesWereWorth(): void
    {
        $player = Tables::seat(Tables::westTable($this->entityManager), 'Alice');

        $crawler = $this->renderTwigComponent('PlayerSaga', ['player' => $player])->crawler();

        $this->assertSame('Advances (4 Victory Points)', $crawler->filter('section[aria-label="Owned advances"] h2')->text());
        $this->assertCount(2, $crawler->filter('section[aria-label="Owned advances"] img[id^="product-"]'));
    }

    #[Test]
    public function aPlayerWhoOwnedNothingIsToldSoRatherThanShownAnEmptyGrid(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $collector = PlayerBuilder::named('Alice')->in($game)->withAdvances(['pottery'])->persist($this->entityManager);
        $emptyHanded = PlayerBuilder::named('Bob')->in($game)->withEmpire('hellas')->persist($this->entityManager);

        $withAdvances = $this->renderTwigComponent('PlayerSaga', ['player' => $collector])->crawler();
        $withNone = $this->renderTwigComponent('PlayerSaga', ['player' => $emptyHanded])->crawler();

        $this->assertCount(1, $withAdvances->filter('section[aria-label="Owned advances"] img[id^="product-"]'));
        $this->assertCount(0, $withAdvances->filter('section[aria-label="Owned advances"] p'));

        $this->assertCount(0, $withNone->filter('section[aria-label="Owned advances"] img[id^="product-"]'));
        $this->assertSame('No advance owned.', $withNone->filter('section[aria-label="Owned advances"] p')->text());
    }
}
