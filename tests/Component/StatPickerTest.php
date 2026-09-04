<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use App\Tests\Support\ShopFixtureTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class StatPickerTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithLiveComponents;
    use ShopFixtureTrait;

    #[Test]
    #[DataProvider('providePickingATilePersistsTheChosenValueForEachStatCases')]
    public function pickingATilePersistsTheChosenValueForEachStat(string $stat, int $chosen, int $expectedStored): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);

        $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => $stat,
        ])->call('pick', ['value' => $chosen]);

        $this->assertSame($expectedStored, $this->reloadPlayer($player)->{$stat});
    }

    /** @return iterable<string, array{string, int, int}> */
    public static function providePickingATilePersistsTheChosenValueForEachStatCases(): iterable
    {
        yield 'cities' => ['cities', 7, 7];

        yield 'cities above the nine-city ceiling clamps' => ['cities', 42, 9];

        yield 'census' => ['census', 30, 30];

        yield 'treasury' => ['treasury', 20, 20];

        yield 'ships' => ['ships', 2, 2];

        yield 'cards' => ['cards', 5, 5];

        yield 'ast position at the top of the track' => ['astPosition', 15, 15];

        yield 'ast position mid-range' => ['astPosition', 4, 4];
    }

    #[Test]
    public function pickingTheCurrentValueIsANoOp(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->withCities(5)->persist($this->entityManager);

        $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'cities',
        ])->call('pick', ['value' => 5]);

        $this->assertSame(5, $this->reloadPlayer($player)->cities);
    }

    /**
     * The Live runtime mirrors the bound model into every `data-model` element it finds, and re-sends any
     * action bound on the dialog each time it is dismissed: either would write a value nobody tapped.
     */
    #[Test]
    public function nothingButAValueTileCommitsFromInsideTheDialog(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);

        $dialog = $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'cities',
        ])->render()->crawler()->filter('dialog');

        $this->assertCount(0, $dialog->filter('[data-model]'));
        $this->assertNull($dialog->attr('data-action'));
        $this->assertNull($dialog->attr('data-live-action-param'));
    }

    /** `action` is a reserved LiveArg name, so an action button names itself through `name` instead. */
    #[Test]
    public function anActionButtonCarriesItsOwnNameAsTheActionArgument(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);

        $button = $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'ships',
        ])->render()->crawler()->filter('menu button[data-value="buildShip"]');

        $this->assertSame('run', $button->attr('data-live-action-param'));
        $this->assertSame($button->attr('data-value'), $button->attr('data-live-name-param'));
    }

    #[Test]
    public function mountInitializesTheValueFromThePlayer(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->withCensus(30)->persist($this->entityManager);

        $component = $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'census',
        ]);

        $this->assertSame(30, $component->component()->value);
    }

    #[Test]
    public function buildingAShipPersistsBothTheFleetAndItsCost(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->withTreasury(7)->persist($this->entityManager);

        $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'ships',
        ])->call('run', ['name' => 'buildShip']);

        $reloaded = $this->reloadPlayer($player);

        $this->assertSame(1, $reloaded->ships);
        $this->assertSame(5, $reloaded->treasury);
    }

    #[Test]
    public function cuttingToLimitUsesTheGamesOwnHandLimit(): void
    {
        $game = GameBuilder::create()->withPlayerCount(12)->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->withCards(15)->persist($this->entityManager);

        $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'cards',
        ])->call('run', ['name' => 'cutToLimit']);

        $this->assertSame(9, $this->reloadPlayer($player)->cards);
    }

    #[Test]
    public function anActionForeignToThePickersStatIsIgnored(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->withTreasury(7)->persist($this->entityManager);

        $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'census',
        ])->call('run', ['name' => 'buildShip']);

        $reloaded = $this->reloadPlayer($player);

        $this->assertSame(0, $reloaded->ships);
        $this->assertSame(7, $reloaded->treasury);
    }
}
