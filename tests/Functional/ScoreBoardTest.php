<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GameSession;
use App\Entity\Player;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class ScoreBoardTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private EntityManagerInterface $entityManager; // @phpstan-ignore property.uninitialized (assigned in setUp before each test)

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

        $rendered = $this->createLiveComponent('ScoreBoard', ['game' => $game])->render()->toString();

        self::assertStringContainsString('Cities', $rendered);
        self::assertStringContainsString('Census', $rendered);
        self::assertStringContainsString('Treasury', $rendered);
        self::assertStringNotContainsString('Points', $rendered);
    }

    #[Test]
    public function rendersPlayerTreasuryValue(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Alice');
        $player->treasury = 12;
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('ScoreBoard', ['game' => $game])->render()->toString();

        self::assertMatchesRegularExpression('/>\s*Alice\s*<\/button>.*?<td>12<\/td>/s', $rendered);
    }

    #[Test]
    public function rendersDefaultCitiesAndCensusValues(): void
    {
        $game = $this->createGame();
        $this->createPlayer($game, 'Alice');

        $rendered = $this->createLiveComponent('ScoreBoard', ['game' => $game])->render()->toString();

        self::assertMatchesRegularExpression('/<td>0<\/td>\s*<td>1<\/td>/', $rendered);
    }

    #[Test]
    public function playerNameOpensModalContainingTheirPlayerBoardUrl(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Alice');

        $rendered = $this->createLiveComponent('ScoreBoard', ['game' => $game]);
        $component = $rendered->component();
        $playerBoardUrl = $component->getPlayerUrl($player);

        $html = $rendered->render()->toString();

        self::assertStringNotContainsString('/shop', $playerBoardUrl, 'Player board URL must not point to the kiosk (shop).');
        self::assertMatchesRegularExpression('/<button[^>]*>\s*Alice\s*<\/button>/', $html);
        self::assertStringContainsString(\sprintf('<a href="%s">%s</a>', $playerBoardUrl, $playerBoardUrl), $html);
        self::assertSame(2, substr_count($html, $playerBoardUrl), 'URL must appear only in the modal link (as both href and text).');
    }

    #[Test]
    public function rendersOneQrCodePerPlayer(): void
    {
        $game = $this->createGame();
        $this->createPlayer($game, 'Alice');
        $this->createPlayer($game, 'Bob');

        $rendered = $this->createLiveComponent('ScoreBoard', ['game' => $game])->render()->toString();

        self::assertSame(2, substr_count($rendered, '<svg'));
    }

    #[Test]
    public function rendersVictoryPointsAsAdvancePointsPlusCities(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Alice');
        $player->ownAdvances(['advanced_military']); // 6 points
        $player->cities = 5;
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('ScoreBoard', ['game' => $game])->render()->toString();

        self::assertMatchesRegularExpression('/<td>11<\/td>\s*<\/tr>/', $rendered);
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

        $rendered = $this->createLiveComponent('ScoreBoard', ['game' => $game])->render()->toString();

        self::assertMatchesRegularExpression('/<td>21<\/td>\s*<\/tr>/', $rendered);
    }

    #[Test]
    public function playersAreSortedByVictoryPointsDescending(): void
    {
        $game = $this->createGame();
        $bob = $this->createPlayer($game, 'Bob');
        $alice = $this->createPlayer($game, 'Alice');
        $bob->cities = 1;
        $alice->cities = 5;
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('ScoreBoard', ['game' => $game])->render()->toString();

        $alicePosition = strpos($rendered, 'Alice');
        $bobPosition = strpos($rendered, 'Bob');

        self::assertNotFalse($alicePosition);
        self::assertNotFalse($bobPosition);
        self::assertLessThan($bobPosition, $alicePosition, 'Higher-scoring player (Alice) must be rendered before the lower-scoring one (Bob).');
    }

    #[Test]
    public function emptyStateColspanMatchesTheSevenColumns(): void
    {
        $game = $this->createGame();

        $rendered = $this->createLiveComponent('ScoreBoard', ['game' => $game])->render()->toString();

        self::assertStringContainsString('colspan="7"', $rendered);
    }

    #[Test]
    public function captionDisplaysTheCurrentTurn(): void
    {
        $game = $this->createGame();
        $game->currentTurn = 7;
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('ScoreBoard', ['game' => $game])->render()->toString();

        self::assertStringContainsString('<caption>Turn 7</caption>', $rendered);
    }

    #[Test]
    public function mercureRefreshFiltersOutOrderSubmittedButKeepsGameStateEvents(): void
    {
        $game = $this->createGame();

        $rendered = $this->createLiveComponent('ScoreBoard', ['game' => $game])->render()->toString();

        self::assertStringContainsString('data-mercure-refresh-events-value', $rendered);
        self::assertStringContainsString('player-updated', $rendered);
        self::assertStringContainsString('order-validated', $rendered);
        self::assertStringContainsString('turn-changed', $rendered);
        self::assertStringContainsString('game-finished', $rendered);
        self::assertStringNotContainsString('order-submitted', $rendered);
    }

    private function createGame(): GameSession
    {
        $game = new GameSession();
        $this->entityManager->persist($game);
        $this->entityManager->flush();

        return $game;
    }

    private function createPlayer(GameSession $game, string $name): Player
    {
        $player = new Player($game, $name);
        $this->entityManager->persist($player);
        $this->entityManager->flush();

        return $player;
    }
}
