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
    public function theBoardOffersOneStatControlPerTrackedStat(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);

        $crawler = $this->renderTwigComponent('molecules:ControlBoard', ['player' => $player])->crawler();

        $this->assertCount(5, $crawler->filter('button[commandfor^="stat-picker-"]'));
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

}
