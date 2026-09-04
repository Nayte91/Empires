<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\State\Game;
use App\State\Player;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\Fixture\Tables;
use App\Tests\Support\GameFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class OperatorBoardTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithLiveComponents;

    #[Test]
    public function theBoardListensOnItsOwnTopic(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        $rendered = $this->renderBoard($game);

        $this->assertSame(
            'empires/game/'.$game->id.'/operator',
            $rendered->filter('[data-mercure-refresh-topic-value]')->attr('data-mercure-refresh-topic-value'),
        );
    }

    #[Test]
    #[DataProvider('provideTheBoardCarriesOneControlPerTurnCommandCases')]
    public function theBoardCarriesOneControlPerTurnCommand(string $label): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        $controls = $this->renderBoard($game)->filter('button')->reduce(
            static fn (Crawler $node): bool => str_contains($node->text(), $label),
        );

        $this->assertCount(1, $controls);
    }

    /** @return iterable<string, array{string}> */
    public static function provideTheBoardCarriesOneControlPerTurnCommandCases(): iterable
    {
        yield 'going back a turn' => ['Previous turn'];

        yield 'advancing a turn' => ['Next turn'];

        yield 'closing the game' => ['Finish game'];
    }

    #[Test]
    #[DataProvider('providePreviousTurnButtonIsDisabledOnlyOnTheFirstTurnCases')]
    public function previousTurnButtonIsDisabledOnlyOnTheFirstTurn(int $currentTurn, bool $expectedDisabled): void
    {
        $game = GameBuilder::create()->withCurrentTurn($currentTurn)->persist($this->entityManager);

        $button = $this->renderBoard($game)->filter('button')->reduce(
            static fn (Crawler $node): bool => str_contains($node->text(), 'Previous turn'),
        );

        $this->assertSame($expectedDisabled, null !== $button->attr('disabled'));
    }

    /** @return iterable<string, array{int, bool}> */
    public static function providePreviousTurnButtonIsDisabledOnlyOnTheFirstTurnCases(): iterable
    {
        yield 'first turn has nothing to go back to' => [1, true];

        yield 'second turn can go back' => [2, false];
    }

    #[Test]
    public function everyPlayerAtTheTableGetsATrackingRowInSeatingOrder(): void
    {
        $game = Tables::westTable($this->entityManager);

        $rows = $this->renderBoard($game)->filter('[data-player-id]');

        $this->assertSame(
            $this->seatedValues($game, static fn (Player $player): string => (string) $player->id),
            $rows->each(static fn (Crawler $row): string => (string) $row->attr('data-player-id')),
        );
        $this->assertSame(
            $this->seatedValues($game, static fn (Player $player): string => $player->empire),
            $rows->each(static fn (Crawler $row): string => (string) $row->attr('data-empire')),
        );
    }

    #[Test]
    public function aTrackingRowFollowsTheCensusTheCitiesAndTheAstPositionInThatOrder(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);

        $crawler = $this->renderBoard($game);

        $this->assertSame(
            ['census', 'cities', 'astPosition'],
            $this->statsOffered($crawler->filter("[data-player-id=\"{$player->id}\"]")),
        );
    }

    #[Test]
    public function theBoardOffersOneStatPickerPerTrackedStatOfEveryPlayer(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);
        PlayerBuilder::named('Bob')->in($game)->withEmpire('rome')->persist($this->entityManager);

        $crawler = $this->renderBoard($game);

        $trackedStatsPerPlayer = 3;

        $this->assertCount(2 * $trackedStatsPerPlayer, $crawler->filter('button[command="show-modal"]'));
    }

    #[Test]
    public function aFinishedGameOffersNoTrackingRow(): void
    {
        $game = GameBuilder::create()->finished()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);
        PlayerBuilder::named('Bob')->in($game)->withEmpire('rome')->persist($this->entityManager);

        $crawler = $this->renderBoard($game);

        $this->assertCount(0, $crawler->filter('button[command="show-modal"]'));
    }

    #[Test]
    public function aFinishedGameShowsItsFinishedMarkInsteadOfTheTurnControls(): void
    {
        $game = GameBuilder::create()->finished()->persist($this->entityManager);

        $crawler = $this->renderBoard($game);

        $this->assertStringContainsString('Finished', $crawler->text());
        $this->assertCount(0, $crawler->filter('button'));
    }

    #[Test]
    public function theBoardOffersNoShopLink(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);

        $crawler = $this->renderBoard($game);

        $this->assertCount(0, $crawler->filter('a[href$="/shop"]'));
    }

    #[Test]
    public function theBoardCarriesNoAdvisory(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->withCities(3)->withCensus(2)->persist($this->entityManager);

        $crawler = $this->renderBoard($game);

        $this->assertSame([], array_filter(
            $crawler->filter('li')->each(static fn (Crawler $node): string => trim($node->text())),
            static fn (string $text): bool => str_contains($text, "You can't"),
        ));
    }

    /**
     * `commandfor` resolves by id, so a duplicate opens whichever dialog comes first in the
     * document — one player's button then edits another player's stat.
     */
    #[Test]
    public function theBoardRendersNoDuplicatedElementId(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);
        PlayerBuilder::named('Bob')->in($game)->withEmpire('rome')->persist($this->entityManager);

        $crawler = $this->renderBoard($game);

        $ids = $crawler->filter('[id]')->each(static fn (Crawler $node): string => (string) $node->attr('id'));

        $this->assertSame([], array_values(array_unique(array_diff_assoc($ids, array_unique($ids)))));
    }

    #[Test]
    public function finishGameFillsInFinishedAt(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        $this->createLiveComponent('OperatorBoard', ['game' => $game])->call('finishGame');

        $this->assertInstanceOf(\DateTimeImmutable::class, $this->reloadGame($game)->finishedAt);
    }

    #[Test]
    #[DataProvider('provideNextTurnAdvancesTheGameUpToTheTwentiethTurnCases')]
    public function nextTurnAdvancesTheGameUpToTheTwentiethTurn(int $startingTurn, int $expectedTurn): void
    {
        $game = GameBuilder::create()->withCurrentTurn($startingTurn)->persist($this->entityManager);

        $this->createLiveComponent('OperatorBoard', ['game' => $game])->call('nextTurn');

        $this->assertSame($expectedTurn, $this->reloadGame($game)->currentTurn);
    }

    /** @return iterable<string, array{int, int}> */
    public static function provideNextTurnAdvancesTheGameUpToTheTwentiethTurnCases(): iterable
    {
        yield 'a turn in the middle of the game advances by one' => [1, 2];

        yield 'the twentieth turn is the last, and clamps' => [20, 20];
    }

    #[Test]
    public function previousTurnNeverGoesBelowTheFirstTurn(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        $this->createLiveComponent('OperatorBoard', ['game' => $game])->call('previousTurn');

        $this->assertSame(1, $this->reloadGame($game)->currentTurn);
    }

    #[Test]
    public function nextTurnOnAFinishedGameDoesNotChangeTheCurrentTurn(): void
    {
        $game = GameBuilder::create()->finished()->persist($this->entityManager);

        $this->createLiveComponent('OperatorBoard', ['game' => $game])->call('nextTurn');

        $this->assertSame(1, $this->reloadGame($game)->currentTurn);
    }

    private function renderBoard(Game $game): Crawler
    {
        return $this->createLiveComponent('OperatorBoard', ['game' => $game])->render()->crawler();
    }

    /** @return list<string> */
    private function statsOffered(Crawler $scope): array
    {
        return $scope->filter('dialog[id^="stat-picker-"]')->each(
            static fn (Crawler $picker): string => explode('-', (string) $picker->attr('id'))[2],
        );
    }

    /**
     * @param callable(Player): string $read
     *
     * @return list<string>
     */
    private function seatedValues(Game $game, callable $read): array
    {
        return array_map($read, array_values($game->players->toArray()));
    }

    private function freshEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function reloadGame(Game $game): Game
    {
        $reloaded = $this->freshEntityManager()->find(Game::class, $game->id);
        $this->assertInstanceOf(Game::class, $reloaded);

        return $reloaded;
    }
}
