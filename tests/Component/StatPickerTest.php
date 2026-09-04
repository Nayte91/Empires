<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\State\Player;
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

    #[Test]
    public function renderContainsTilesZeroThroughMax(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);

        $rendered = $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'cities',
        ])->render()->toString();

        for ($i = 0; $i <= 9; ++$i) {
            $this->assertStringContainsString((string) $i, $rendered);
        }
    }

    #[Test]
    public function theDialogOffersNoConfirmationButtonBesideItsValueTiles(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);

        $rendered = $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'cities',
        ])->render();

        $this->assertCount(0, $rendered->crawler()->filter('dialog button:not([data-value])'));
    }

    #[Test]
    public function tappingAValueTileRecordsThatValueInASingleGesture(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);
        $component = $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'cities',
        ]);
        $tile = $component->render()->crawler()->filter('fieldset button[data-value="5"]');

        $component->call('pick', ['value' => (int) $tile->attr('data-value')]);

        $this->assertSame('submit', $tile->attr('type'));
        $this->assertSame('live#action', $tile->attr('data-action'));
        $this->assertSame('pick', $tile->attr('data-live-action-param'));
        $this->assertSame($tile->attr('data-value'), $tile->attr('data-live-value-param'));
        $this->assertNull($tile->attr('data-model'));
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
    public function censusRenderStartsAtTileTwo(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);

        $rendered = $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'census',
        ])->render();

        $values = $rendered->crawler()->filter('fieldset button[data-value]')->each(
            static fn ($node): string => (string) $node->attr('data-value'),
        );

        $this->assertSame('2', $values[0]);
        $this->assertNotContains('0', $values);
        $this->assertNotContains('1', $values);
    }

    #[Test]
    public function renderInitializesValueFromThePlayer(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->withCensus(30)->persist($this->entityManager);

        $rendered = $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'census',
        ])->render();

        $selected = $rendered->crawler()->filter('fieldset button[data-selected]');

        $this->assertSame('30', $selected->attr('data-value'));
    }

    #[Test]
    public function theAstPositionTileShowsTheDisplayLabelWhileCarryingTheStorageValue(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->withAstPosition(4)->persist($this->entityManager);

        $rendered = $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'astPosition',
            'value' => $player->astPosition,
        ])->render();

        $selected = $rendered->crawler()->filter('fieldset button[data-selected]');

        $this->assertSame('4', $selected->attr('data-value'));
        $selected->text()
            |> trim(...)
            |> (fn($x) => $this->assertSame('5', $x));
    }

    #[Test]
    public function mountedValueIsOverriddenByPassedValueProp(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->withCities(2)->persist($this->entityManager);

        $rendered = $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'cities',
            'value' => 7,
        ])->render();

        $selected = $rendered->crawler()->filter('fieldset button[data-selected]');

        $this->assertSame('7', $selected->attr('data-value'));
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

    #[Test]
    public function treasuryTilesBeyondWhatThePopulationLeavesAreDisabled(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->withCensus(20)->persist($this->entityManager);

        $rendered = $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'treasury',
        ])->render();

        $this->assertCount(0, $rendered->crawler()->filter('fieldset button[data-value="35"][disabled]'));
        $this->assertCount(1, $rendered->crawler()->filter('fieldset button[data-value="36"][disabled]'));
    }

    #[Test]
    public function theTreasuryPickerOffersOnlyTheTaxRatesThePlayerUnlocked(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $plain = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);
        $coinage = PlayerBuilder::named('Alice')->in($game)
            ->withAdvances(['coinage'])
            ->persist($this->entityManager);
        $both = PlayerBuilder::named('Carol')->in($game)
            ->withAdvances(['coinage', 'monarchy'])
            ->persist($this->entityManager);

        $this->assertSame(['payTaxes2'], $this->taxActionsOf($plain));
        $this->assertSame(['payTaxes1', 'payTaxes2', 'payTaxes3'], $this->taxActionsOf($coinage));
        $this->assertSame(['payTaxes1', 'payTaxes2', 'payTaxes3', 'payTaxes4'], $this->taxActionsOf($both));
    }

    #[Test]
    public function citiesPickerOffersNoActionButton(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);

        $rendered = $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'cities',
        ])->render();

        $this->assertCount(0, $rendered->crawler()->filter('menu [data-value]'));
    }

    #[Test]
    public function shipsPickerRendersBothActionsAndDisablesWhatThePlayerCannotAfford(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);

        $rendered = $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'ships',
        ])->render();

        $actions = $rendered->crawler()->filter('menu [data-value]');

        $this->assertSame(['buildShip', 'maintainShips'], $actions->each(
            static fn ($node): string => (string) $node->attr('data-value'),
        ));
        $this->assertCount(2, $actions->filter('[disabled]'));
    }

    /** @return list<string> */
    private function taxActionsOf(Player $player): array
    {
        return $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'treasury',
        ])->render()->crawler()->filter('menu [data-value]')->each(
            static fn ($node): string => (string) $node->attr('data-value'),
        );
    }
}
