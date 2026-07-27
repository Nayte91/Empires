<?php

declare(strict_types=1);

namespace App\Tests\Functional;

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
    public function empireCellDisplaysTheEmpireAdjectiveInLowercase(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);
        $player->empire = 'minoa';
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('ScoreBoard', ['game' => $game])->render()->toString();
        $crawler = new Crawler($rendered);

        $this->assertSame('minoan', trim($crawler->filter('tbody tr td:nth-of-type(2)')->text()));
    }

    #[Test]
    public function empireCellDisplaysADashWhenPlayerHasNoEmpire(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);

        $rendered = $this->createLiveComponent('ScoreBoard', ['game' => $game])->render()->toString();
        $crawler = new Crawler($rendered);

        $this->assertSame('—', trim($crawler->filter('tbody tr td:nth-of-type(2)')->text()));
    }

    #[Test]
    public function rendersDefaultStatColumnsInTheNewOrder(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);

        $rendered = $this->createLiveComponent('ScoreBoard', ['game' => $game])->render()->toString();

        // Treasury(0), Census(1), Ships(0), Cities(0), Cards(0), Advances(0), VP(0) — Name/Empire cells are not plain <td>value</td>.
        $this->assertMatchesRegularExpression('/<td>0<\/td>\s*<td>1<\/td>\s*<td>0<\/td>\s*<td>0<\/td>\s*<td>0<\/td>\s*<td>0<\/td>\s*<td>0<\/td>\s*<\/tr>/', $rendered);
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

    /**
     * Canary for the scoreboard: the victory-point total ScoreCalculator computes reaches the cell.
     * Each contributing term (advance points, cities, A.S.T. position) is pinned in
     * ScoreCalculatorTest — re-deriving the sum here for every combination only duplicated it.
     */
    #[Test]
    public function rendersVictoryPointsAsAdvancePointsPlusCities(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);
        $player->ownAdvances(['advanced_military']); // 6 points
        $player->cities = 5;
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('ScoreBoard', ['game' => $game])->render()->toString();

        $this->assertMatchesRegularExpression('/<td data-scored>11<\/td>\s*<\/tr>/', $rendered);
    }

    #[Test]
    public function playersAreSortedByVictoryPointsDescending(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $bob = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);
        $alice = PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);
        $bob->cities = 1;
        $alice->cities = 5;
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('ScoreBoard', ['game' => $game])->render()->toString();

        $alicePosition = strpos($rendered, 'Alice');
        $bobPosition = strpos($rendered, 'Bob');

        $this->assertNotFalse($alicePosition);
        $this->assertNotFalse($bobPosition);
        $this->assertLessThan($bobPosition, $alicePosition, 'Higher-scoring player (Alice) must be rendered before the lower-scoring one (Bob).');
    }

    #[Test]
    public function scoredCitiesAndVictoryPointsAreMarkedButZeroAdvancesIsNot(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);
        $player->cities = 3;
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('ScoreBoard', ['game' => $game])->render()->toString();
        $crawler = new Crawler($rendered);
        $row = $crawler->filter('tbody tr');

        $this->assertNotNull($row->filter('td:nth-of-type(6)')->attr('data-scored'), 'Cities cell should be marked as scored.');
        $this->assertNull($row->filter('td:nth-of-type(8)')->attr('data-scored'), 'Advances cell should not be marked as scored when it is zero.');
        $this->assertNotNull($row->filter('td:nth-of-type(9)')->attr('data-scored'), 'Victory points cell should be marked as scored.');
    }

    #[Test]
    public function emptyStateColspanMatchesTheNineColumns(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        $rendered = $this->createLiveComponent('ScoreBoard', ['game' => $game])->render()->toString();

        $this->assertStringContainsString('colspan="9"', $rendered);
    }

    #[Test]
    public function captionDisplaysTheCurrentTurn(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $game->currentTurn = 7;
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('ScoreBoard', ['game' => $game])->render()->toString();

        $this->assertStringContainsString('<caption>Turn 7</caption>', $rendered);
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
