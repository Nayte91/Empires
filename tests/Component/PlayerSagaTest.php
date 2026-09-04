<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Presentation\Component\PlayerSaga;
use App\State\Player;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\Fixture\Tables;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class PlayerSagaTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithTwigComponents;

    #[Test]
    public function theFinalCountersAreTheFiveTrackedStatsAndNotTheAstPosition(): void
    {
        $player = Tables::seat(Tables::westTable($this->entityManager), 'Alice');

        $counters = $this->mount($player)->getCounters();

        $this->assertSame(['cities', 'ships', 'census', 'treasury', 'cards'], array_column($counters, 'key'));
        $this->assertSame(['3', '2', '7', '40', '5'], array_column($counters, 'value'));
    }

    #[Test]
    public function aPlayerWhoOwnedNothingIsToldSoRatherThanShownAnEmptyGrid(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $collector = PlayerBuilder::named('Alice')->in($game)->withAdvances(['pottery'])->persist($this->entityManager);
        $emptyHanded = PlayerBuilder::named('Bob')->in($game)->withEmpire('hellas')->persist($this->entityManager);

        $withAdvances = $this->renderTwigComponent('PlayerSaga', ['player' => $collector])->crawler();
        $withNone = $this->renderTwigComponent('PlayerSaga', ['player' => $emptyHanded])->crawler();

        $this->assertCount(1, $withAdvances->filter('section[aria-label="Owned advances"] img[id^="product-"]'));
        $this->assertCount(0, $withAdvances->filter('section[aria-label="Owned advances"] p'));

        $this->assertCount(0, $withNone->filter('section[aria-label="Owned advances"] img[id^="product-"]'));
        $this->assertCount(1, $withNone->filter('section[aria-label="Owned advances"] p'));
    }

    private function mount(Player $player): PlayerSaga
    {
        $component = $this->mountTwigComponent('PlayerSaga', ['player' => $player]);
        $this->assertInstanceOf(PlayerSaga::class, $component);

        return $component;
    }
}
