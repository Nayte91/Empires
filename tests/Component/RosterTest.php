<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\Fixture\Tables;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class RosterTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithTwigComponents;

    #[Test]
    public function eachRowIsNumberedByItsPlaceInTheCensusOrder(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->withCensus(2)->persist($this->entityManager);
        PlayerBuilder::named('Bob')->in($game)->withEmpire('saba')->withCensus(9)->persist($this->entityManager);
        PlayerBuilder::named('Carl')->in($game)->withEmpire('assyria')->withAdvances(['military'])->withCensus(20)->persist($this->entityManager);

        $rows = $this->mountTwigComponent('Roster', ['game' => $game])->getPlayerRows();

        $this->assertSame([1, 2, 3], array_column($rows, 'rank'));
    }

    #[Test]
    public function theMilitaryAdvanceIsFlaggedNextToTheEmpireItBelongsTo(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->withAdvances(['military'])->persist($this->entityManager);
        PlayerBuilder::named('Bob')->in($game)->withEmpire('saba')->persist($this->entityManager);

        $crawler = $this->renderTwigComponent('Roster', ['game' => $game])->crawler();

        $this->assertCount(1, $crawler->filter('tr[data-empire="minoa"] [data-military]'));
        $this->assertCount(0, $crawler->filter('tr[data-empire="saba"] [data-military]'));
    }

    #[Test]
    public function theRosterOffersTheTargetItsPushReplaces(): void
    {
        $game = Tables::westTable($this->entityManager);

        $rendered = $this->renderTwigComponent('Roster', ['game' => $game])->crawler();

        $this->assertSame('roster', $rendered->filter('table')->attr('data-region'));
    }
}
