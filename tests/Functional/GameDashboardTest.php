<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Game;
use App\Entity\Player;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class GameDashboardTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function rendersCitiesAndCensusColumnsWithoutPoints(): void
    {
        $game = $this->createGame();
        $this->createPlayer($game, 'Alice');
        $this->createPlayer($game, 'Bob');

        $rendered = $this->createLiveComponent('GameDashboard', ['game' => $game])->render()->toString();
        $playersTable = $this->extractPlayersTable($rendered);

        $this->assertStringContainsString('Cities', $playersTable);
        $this->assertStringContainsString('Census', $playersTable);
        $this->assertStringContainsString('Treasury', $playersTable);
        $this->assertStringNotContainsString('Points', $playersTable);
    }

    #[Test]
    public function rendersPlayerTreasuryValue(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Alice');
        $player->treasury = 12;
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('GameDashboard', ['game' => $game])->render()->toString();

        $this->assertMatchesRegularExpression('/>\s*Alice\s*<\/button>.*?<td>12<\/td>/s', $rendered);
    }

    #[Test]
    public function rendersDefaultCitiesAndCensusValues(): void
    {
        $game = $this->createGame();
        $this->createPlayer($game, 'Alice');

        $rendered = $this->createLiveComponent('GameDashboard', ['game' => $game])->render()->toString();

        $this->assertMatchesRegularExpression('/<td>0<\/td>\s*<td>1<\/td>/', $rendered);
    }

    #[Test]
    public function playerNameOpensModalContainingTheirPlayerBoardUrl(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Alice');

        $rendered = $this->createLiveComponent('GameDashboard', ['game' => $game]);
        $component = $rendered->component();
        $playerBoardUrl = $component->getPlayerUrl($player);

        $html = $rendered->render()->toString();

        $this->assertStringNotContainsString('/shop', (string) $playerBoardUrl, 'Player board URL must not point to the kiosk (shop).');
        $this->assertMatchesRegularExpression('/<button[^>]*>\s*Alice\s*<\/button>/', $html);
        $this->assertStringContainsString(\sprintf('<a href="%s">%s</a>', $playerBoardUrl, $playerBoardUrl), $html);
        $this->assertSame(2, substr_count($html, $playerBoardUrl), 'URL must appear only in the modal link (as both href and text).');
    }

    #[Test]
    public function rendersOneQrCodePerPlayerPlusOneForTheOperator(): void
    {
        $game = $this->createGame();
        $this->createPlayer($game, 'Alice');
        $this->createPlayer($game, 'Bob');

        $rendered = $this->createLiveComponent('GameDashboard', ['game' => $game])->render()->toString();

        // One <svg> per generated QR code: 2 players + 1 operator = 3.
        $this->assertSame(3, substr_count($rendered, '<svg'));
    }

    #[Test]
    public function operatorBoardOpensModalContainingTheOperatorUrl(): void
    {
        $game = $this->createGame();
        $this->createPlayer($game, 'Alice');

        $rendered = $this->createLiveComponent('GameDashboard', ['game' => $game]);
        $component = $rendered->component();
        $operatorUrl = $component->getOperatorUrl();

        $html = $rendered->render()->toString();

        $this->assertStringContainsString('<dialog', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*>\s*Operator board\s*<\/button>/', $html);
        $this->assertStringContainsString(\sprintf('<a href="%s">%s</a>', $operatorUrl, $operatorUrl), $html);
    }

    #[Test]
    public function rendersVictoryPointsAsAdvancePointsPlusCities(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Alice');
        $player->ownAdvances(['advanced_military']); // 6 points
        $player->cities = 5;
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('GameDashboard', ['game' => $game])->render()->toString();

        $this->assertMatchesRegularExpression('/<td>11<\/td>\s*<\/tr>/', $rendered);
    }

    #[Test]
    public function rendersVictoryPointsIncludingAstPositionBonus(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Alice');
        $player->ownAdvances(['advanced_military']); // 6 points
        $player->cities = 5;
        $player->astPosition = 2; // +10 points
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('GameDashboard', ['game' => $game])->render()->toString();

        $this->assertMatchesRegularExpression('/<td>21<\/td>\s*<\/tr>/', $rendered);
    }

    #[Test]
    public function emptyStateColspanMatchesTheSevenColumns(): void
    {
        $game = $this->createGame();

        $rendered = $this->createLiveComponent('GameDashboard', ['game' => $game])->render()->toString();

        $this->assertStringContainsString('colspan="7"', $rendered);
    }

    #[Test]
    public function mercureRefreshFiltersOutOrderSubmittedButKeepsGameStateEvents(): void
    {
        $game = $this->createGame();

        $rendered = $this->createLiveComponent('GameDashboard', ['game' => $game])->render()->toString();

        $this->assertStringContainsString('data-mercure-refresh-events-value', $rendered);
        $this->assertStringContainsString('order-validated', $rendered);
        $this->assertStringContainsString('turn-changed', $rendered);
        $this->assertStringContainsString('game-finished', $rendered);
        $this->assertStringContainsString('player-updated', $rendered);
        $this->assertStringNotContainsString('order-submitted', $rendered);
    }

    private function createGame(): Game
    {
        $game = new Game();
        $this->entityManager->persist($game);
        $this->entityManager->flush();

        return $game;
    }

    private function createPlayer(Game $game, string $name): Player
    {
        $player = new Player($game, $name);
        $this->entityManager->persist($player);
        $this->entityManager->flush();

        return $player;
    }

    /**
     * Scopes assertions to the players table, as opposed to the whole dashboard
     * (which also embeds the AST molecule's own <table>).
     */
    private function extractPlayersTable(string $html): string
    {
        // The players table carries class="score-board": the embedded
        // AST molecule's table always carries class="ast" instead.
        $start = strpos($html, '<table class="score-board">');
        $this->assertNotFalse($start, 'Players <table> not found in rendered output.');

        $end = strpos($html, '</table>', $start);
        $this->assertNotFalse($end, 'Players </table> not found in rendered output.');

        return substr($html, $start, $end - $start);
    }
}
