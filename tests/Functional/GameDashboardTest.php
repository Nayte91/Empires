<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GameSession;
use App\Entity\Player;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class GameDashboardTest extends WebTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $entityManager; // @phpstan-ignore property.uninitialized (assigned in setUp before each test)

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function headingDisplaysTheGameSlugWithoutTurn(): void
    {
        $game = $this->createGame();

        $html = $this->renderTwigComponent('GameDashboard', ['game' => $game])->toString();

        $this->assertSame(1, preg_match('/<h1>(.*?)<\/h1>/s', $html, $matches), '<h1> not found in rendered output.');
        $this->assertSame($game->slug, trim($matches[1]));
    }

    #[Test]
    public function operatorBoardOpensModalContainingTheOperatorUrl(): void
    {
        $game = $this->createGame();
        $this->createPlayer($game, 'Alice');

        $component = $this->mountTwigComponent('GameDashboard', ['game' => $game]);
        $operatorUrl = $component->getOperatorUrl();

        $html = $this->renderTwigComponent('GameDashboard', ['game' => $game])->toString();

        $this->assertStringContainsString('/'.$game->slug.'/operator', (string) $operatorUrl);
        $this->assertStringContainsString('<dialog', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*>\s*Operator board\s*<\/button>/', $html);
        $this->assertStringContainsString(\sprintf('<a href="%s">%s</a>', $operatorUrl, $operatorUrl), $html);
    }

    #[Test]
    public function rendersTheOperatorQrCode(): void
    {
        $game = $this->createGame();

        $html = $this->renderTwigComponent('GameDashboard', ['game' => $game])->toString();

        // No players: the only QR code on the dashboard is the operator's.
        $this->assertSame(1, substr_count($html, '<svg'));
    }

    #[Test]
    public function dashboardRootCarriesNoMercureRefreshController(): void
    {
        $game = $this->createGame();

        $html = $this->renderTwigComponent('GameDashboard', ['game' => $game])->toString();
        $rootTag = substr($html, 0, (int) strpos($html, '>') + 1);
        $beforeFirstTable = substr($html, 0, (int) strpos($html, '<table'));

        $this->assertStringNotContainsString('data-controller', $rootTag);
        $this->assertStringNotContainsString('mercure-refresh', $beforeFirstTable, 'mercure-refresh must only appear on the embedded ScoreBoard and Ast tables.');
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
