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

final class ScoreBoardTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithLiveComponents;

    #[Test]
    public function rendersCitiesAndCensusColumnsWithoutPoints(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);
        PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);

        $rendered = $this->createLiveComponent('ScoreBoard', ['game' => $game])->render()->toString();

        $this->assertStringContainsString('Cities', $rendered);
        $this->assertStringContainsString('Census', $rendered);
        $this->assertStringContainsString('Treasury', $rendered);
        $this->assertStringNotContainsString('Points', $rendered);
    }

    #[Test]
    public function rendersPlayerTreasuryValue(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);
        $player->treasury = 12;
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('ScoreBoard', ['game' => $game])->render()->toString();

        $this->assertMatchesRegularExpression('/>\s*Alice\s*<\/button>.*?<td>12<\/td>/s', $rendered);
    }

    #[Test]
    public function empireCellNamesTheEmpireAdjective(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);

        $rendered = $this->createLiveComponent('ScoreBoard', ['game' => $game])->render()->toString();
        $crawler = new Crawler($rendered);

        $this->assertSame('minoan', trim($crawler->filter('tbody tr td:nth-of-type(2)')->text()));
    }

    #[Test]
    public function rendersDefaultStatColumnsInTheNewOrder(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);

        $rendered = $this->createLiveComponent('ScoreBoard', ['game' => $game])->render()->toString();

        // Treasury(0), Census(1), Cities(0), Cards(0), Advances(0) — Name/Empire cells are not plain <td>value</td>.
        $this->assertMatchesRegularExpression('/<td>0<\/td>\s*<td>1<\/td>\s*<td>0<\/td>\s*<td>0<\/td>\s*<td>0<\/td>\s*<\/tr>/', $rendered);
    }

    #[Test]
    public function shipsAreNotDisplayedAtAll(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);
        $player->ships = 4;
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('ScoreBoard', ['game' => $game])->render()->toString();

        $this->assertStringNotContainsString('Ships', $rendered);
        $this->assertStringNotContainsString('<td>4</td>', $rendered);
    }

    #[Test]
    public function playerNameOpensModalContainingTheirPlayerBoardUrl(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);

        $rendered = $this->createLiveComponent('ScoreBoard', ['game' => $game]);
        $component = $rendered->component();
        $playerBoardUrl = $component->getPlayerUrl($player);

        $html = $rendered->render()->toString();

        $this->assertStringNotContainsString('/shop', (string) $playerBoardUrl, 'Player board URL must not point to the kiosk (shop).');
        $this->assertMatchesRegularExpression('/<button[^>]*>\s*Alice\s*<\/button>/', $html);
        $this->assertStringContainsString(\sprintf('<a href="%s">%s</a>', $playerBoardUrl, $playerBoardUrl), $html);
        $this->assertSame(2, substr_count($html, $playerBoardUrl), 'URL must appear only in the modal link (as both href and text).');
    }

    #[Test]
    public function rendersOneQrCodePerPlayer(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);
        PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);

        $rendered = $this->createLiveComponent('ScoreBoard', ['game' => $game])->render()->toString();

        $this->assertSame(2, substr_count($rendered, '<svg'));
    }

    /** The score belongs to the A.S.T. board now: the scoreboard must not grow a second one. */
    #[Test]
    public function noVictoryPointColumnIsRendered(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);
        $player->ownAdvances(['advanced_military']); // 6 points
        $player->cities = 5;                         // 11 victory points in total
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('ScoreBoard', ['game' => $game])->render()->toString();

        $this->assertStringNotContainsString('VP', $rendered);
        $this->assertStringNotContainsString('data-medal', $rendered);
        $this->assertStringNotContainsString('<td>11</td>', $rendered);
    }

    /**
     * The table is read down the movement-phase order, not down the standings: the operator uses it
     * to call the players in turn.
     */
    #[Test]
    public function playersAreOrderedByPlayOrderRatherThanByScore(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $alice = PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->withCities(5)->persist($this->entityManager);
        $bob = PlayerBuilder::named('Bob')->in($game)->withEmpire('saba')->persist($this->entityManager);
        $carl = PlayerBuilder::named('Carl')->in($game)->withEmpire('assyria')->withAdvances(['military'])->persist($this->entityManager);
        $alice->census = 2;
        $bob->census = 9;
        $carl->census = 20;
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('ScoreBoard', ['game' => $game])->render()->toString();

        $this->assertLessThan(strpos($rendered, 'Alice'), strpos($rendered, 'Bob'), 'Higher census (Bob) plays before lower census (Alice).');
        $this->assertLessThan(strpos($rendered, 'Carl'), strpos($rendered, 'Alice'), 'Military owner (Carl) plays last whatever their census.');
    }

    #[Test]
    public function theMilitaryAdvanceIsFlaggedNextToTheEmpireItBelongsTo(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->withAdvances(['military'])->persist($this->entityManager);
        PlayerBuilder::named('Bob')->in($game)->withEmpire('saba')->persist($this->entityManager);

        $rendered = $this->createLiveComponent('ScoreBoard', ['game' => $game])->render()->toString();
        $rows = new Crawler($rendered)->filter('tbody tr');

        $this->assertSame('sabaean', trim($rows->eq(0)->filter('td:nth-of-type(2)')->text()));
        $this->assertSame('minoan ⚔', preg_replace('/\s+/u', ' ', trim($rows->eq(1)->filter('td:nth-of-type(2)')->text())), 'The military owner plays last, and its people carries the ⚔ flag.');
    }

    #[Test]
    public function captionDisplaysTheCurrentTurn(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $game->currentTurn = 7;
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('ScoreBoard', ['game' => $game])->render()->toString();

        $this->assertStringContainsString('<caption id="scoreboard">Turn 7</caption>', $rendered);
    }

    #[Test]
    public function mercureRefreshFiltersOutOrderUpdatedButKeepsGameStateEvents(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        $rendered = $this->createLiveComponent('ScoreBoard', ['game' => $game])->render()->toString();

        $this->assertStringContainsString('data-mercure-refresh-events-value', $rendered);
        $this->assertStringContainsString('player-updated', $rendered);
        $this->assertStringContainsString('game-updated', $rendered);
        $this->assertStringNotContainsString('order-updated', $rendered);
    }
}
