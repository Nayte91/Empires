<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GameSession;
use App\Entity\Player;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class ControlBoardTest extends WebTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $entityManager; // @phpstan-ignore property.uninitialized (initialized in setUp)

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function renderShowsAllFiveStatControls(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Bob');

        $crawler = $this->renderTwigComponent('molecules:ControlBoard', ['player' => $player])->crawler();

        $this->assertCount(5, $crawler->filter('button[commandfor]'));
    }

    /**
     * The operator console drives the same component with its own stat list, AST position
     * included — a stat the player must never be able to move on their own board.
     */
    #[Test]
    public function anExplicitStatListRendersExactlyThoseControlsInOrder(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Bob');

        $crawler = $this->renderTwigComponent('molecules:ControlBoard', [
            'player' => $player,
            'stats' => ['cities', 'astPosition', 'treasury'],
        ])->crawler();

        $this->assertSame(
            ['stat-picker-cities-'.$player->id, 'stat-picker-astPosition-'.$player->id, 'stat-picker-treasury-'.$player->id],
            $crawler->filter('button[commandfor]')->each(static fn ($node): string => (string) $node->attr('commandfor')),
        );
    }

    #[Test]
    public function theShopLinkIsAbsentUnlessAskedFor(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Bob');

        $crawler = $this->renderTwigComponent('molecules:ControlBoard', ['player' => $player])->crawler();

        $this->assertCount(0, $crawler->filter('a'));
    }

    #[Test]
    public function theShopLinkPointsAtThePlayersOwnShop(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Bob');

        $crawler = $this->renderTwigComponent('molecules:ControlBoard', [
            'player' => $player,
            'withShopLink' => true,
        ])->crawler();

        $this->assertSame(
            '/'.$game->slug.'/player/'.$player->slug.'/shop',
            $crawler->filter('a')->attr('href'),
        );
    }

    /** The advisories moved to the Outlook block; the control board is now controls only. */
    #[Test]
    public function noAdvisoryIsRenderedHereAnyMore(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Bob');
        $player->cities = 5;
        $player->census = 1;
        $player->treasury = 50;
        $this->entityManager->flush();

        $crawler = $this->renderTwigComponent('molecules:ControlBoard', ['player' => $player])->crawler();

        $this->assertCount(0, $crawler->filter('li'));
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
