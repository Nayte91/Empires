<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class StandingsTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithLiveComponents;

    /** The phone's question is who is winning, so this list is read down the score. */
    #[Test]
    public function rowsAreOrderedByScoreWithTheLeaderFirst(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);
        $bob = PlayerBuilder::named('Bob')->in($game)->withEmpire('saba')->persist($this->entityManager);
        $bob->cities = 6;
        $this->entityManager->flush();

        $rows = $this->render($game)->filter('li');

        $this->assertSame('Bob', trim($rows->eq(0)->filter('b')->text()));
        $this->assertSame('Alice', trim($rows->eq(1)->filter('b')->text()));
    }

    /** Two players on one score are separated by who plays first, never by who was created first. */
    #[Test]
    public function aTieIsBrokenByMovementOrderRatherThanBySeatOrder(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $alice = PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);
        $bob = PlayerBuilder::named('Bob')->in($game)->withEmpire('saba')->persist($this->entityManager);
        $alice->census = 2;
        $bob->census = 9; // higher census plays first, and both score zero
        $this->entityManager->flush();

        $rows = $this->render($game)->filter('li');

        $this->assertSame('Bob', trim($rows->eq(0)->filter('b')->text()));
        $this->assertSame('Alice', trim($rows->eq(1)->filter('b')->text()));
    }

    /** The band is stored state: a player who has scored without moving is still where they were. */
    #[Test]
    public function theBandIsReadFromTheAstPositionAndNotFromTheScore(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);
        $player->cities = 9; // nine points, still standing on the opening square
        $this->entityManager->flush();

        $rendered = $this->render($game);

        $this->assertSame('9 VP', preg_replace('/\s+/', ' ', trim($rendered->filter('[data-headline]')->text())));
        $this->assertStringContainsString('Start', $rendered->filter('[data-seat] small')->text());
    }

    /** One drawer at a time, and no JavaScript to enforce it: the name attribute already does. */
    #[Test]
    public function everyDrawerSharesOneAccordionName(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);
        PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);

        $drawers = $this->render($game)->filter('details');

        $this->assertCount(2, $drawers);
        $this->assertSame(['standings', 'standings'], $drawers->each(static fn (Crawler $node): ?string => $node->attr('name')));
    }

    #[Test]
    public function theDrawerCarriesTheFiveStatsTheRowDoesNotState(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);
        $player->treasury = 12;
        $player->census = 8;
        $player->cities = 3;
        $player->cards = 4;
        $player->ownAdvances(['military']);
        $this->entityManager->flush();

        $drawer = $this->render($game)->filter('dl');

        $this->assertSame(['Trea', 'Pop', 'Cities', 'Cards', 'Adv'], $drawer->filter('dt')->each(static fn (Crawler $node): string => trim($node->text())));
        $this->assertSame(['12', '8', '3', '4', '1'], $drawer->filter('dd')->each(static fn (Crawler $node): string => trim($node->text())));
    }

    private function render(\App\State\Game $game): Crawler
    {
        return new Crawler($this->createLiveComponent('Standings', ['game' => $game])->render()->toString());
    }
}
