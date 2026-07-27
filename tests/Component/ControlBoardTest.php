<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class ControlBoardTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithTwigComponents;

    #[Test]
    public function renderShowsAllFiveStatControls(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);

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
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);

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
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);

        $crawler = $this->renderTwigComponent('molecules:ControlBoard', ['player' => $player])->crawler();

        $this->assertCount(0, $crawler->filter('a'));
    }

    #[Test]
    public function theShopLinkPointsAtThePlayersOwnShop(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);

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
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);
        $player->cities = 5;
        $player->census = 1;
        $player->treasury = 50;
        $this->entityManager->flush();

        $crawler = $this->renderTwigComponent('molecules:ControlBoard', ['player' => $player])->crawler();

        $this->assertCount(0, $crawler->filter('li'));
    }
}
