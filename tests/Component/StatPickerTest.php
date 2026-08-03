<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\State\Player;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class StatPickerTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithLiveComponents;

    #[Test]
    #[DataProvider('provideSavePersistsTheChosenValueForEachStatCases')]
    public function savePersistsTheChosenValueForEachStat(string $stat, int $chosen, int $expectedStored): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);

        $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => $stat,
        ])->set('value', $chosen)->call('save');

        $this->assertSame($expectedStored, $this->reloadPlayer($player)->{$stat});
    }

    /** @return iterable<string, array{string, int, int}> */
    public static function provideSavePersistsTheChosenValueForEachStatCases(): iterable
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
    public function saveWithUnchangedValueIsNoOp(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);
        $player->cities = 5;
        $this->entityManager->flush();

        $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'cities',
        ])->set('value', 5)->call('save');

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

    /**
     * OK is gone, so every button left inside the dialog commits the value it carries. Asserting the
     * absence structurally rather than by label catches a confirmation step returning under any
     * other name.
     */
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

    /**
     * The tile is the whole gesture: it carries the value, and submitting closes the dialog, which
     * is what fires save. Driving save from the tile's own data-value is what ties the markup the
     * player taps to the number that gets stored.
     */
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

        $component->set('value', (int) $tile->attr('data-value'))->call('save');

        $this->assertSame('submit', $tile->attr('type'));
        $this->assertSame('live#update', $tile->attr('data-action'));
        $this->assertSame('norender|value', $tile->attr('data-model'));
        $this->assertSame(5, $this->reloadPlayer($player)->cities);
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
        $player = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);
        $player->census = 30;
        $this->entityManager->flush();

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
        $player = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);
        $player->astPosition = 4;
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'astPosition',
            'value' => $player->astPosition,
        ])->render();

        $selected = $rendered->crawler()->filter('fieldset button[data-selected]');

        $this->assertSame('4', $selected->attr('data-value'));
        $this->assertSame('5', trim($selected->text()));
    }

    #[Test]
    public function mountedValueIsOverriddenByPassedValueProp(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);
        $player->cities = 2;
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'cities',
            'value' => 7,
        ])->render();

        $selected = $rendered->crawler()->filter('fieldset button[data-selected]');

        $this->assertSame('7', $selected->attr('data-value'));
    }

    /**
     * Building a ship moves a stat this picker does not own, which is the whole reason the action
     * name travels to the server instead of a target value.
     */
    #[Test]
    public function buildingAShipPersistsBothTheFleetAndItsCost(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);
        $player->treasury = 7;
        $this->entityManager->flush();

        $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'ships',
        ])->set('pendingAction', 'buildShip')->call('save');

        $reloaded = $this->reloadPlayer($player);

        $this->assertSame(1, $reloaded->ships);
        $this->assertSame(5, $reloaded->treasury);
    }

    #[Test]
    public function cuttingToLimitUsesTheGamesOwnHandLimit(): void
    {
        $game = GameBuilder::create()->withPlayerCount(12)->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);
        $player->cards = 15;
        $this->entityManager->flush();

        $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'cards',
        ])->set('pendingAction', 'cutToLimit')->call('save');

        $this->assertSame(9, $this->reloadPlayer($player)->cards);
    }

    /**
     * pendingAction is client-writable, so a picker must refuse an action that belongs to another
     * stat rather than apply it to the player anyway.
     */
    #[Test]
    public function anActionForeignToThePickersStatIsIgnored(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);
        $player->treasury = 7;
        $this->entityManager->flush();

        $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'census',
        ])->set('pendingAction', 'buildShip')->call('save');

        $reloaded = $this->reloadPlayer($player);

        $this->assertSame(0, $reloaded->ships);
        $this->assertSame(7, $reloaded->treasury);
    }

    /** The twin's share of the stock is out of reach, so its tiles are shown but not selectable. */
    #[Test]
    public function treasuryTilesBeyondWhatThePopulationLeavesAreDisabled(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);
        $player->census = 20;
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'treasury',
        ])->render();

        $this->assertCount(0, $rendered->crawler()->filter('fieldset button[data-value="35"][disabled]'));
        $this->assertCount(1, $rendered->crawler()->filter('fieldset button[data-value="36"][disabled]'));
    }

    /**
     * Coinage unlocks the cheaper rate, Monarchy the dearer one; the rest stay off the menu.
     * Three players rather than one mutated in place: rendering a live component resets the
     * EntityManager, which detaches the entity and swallows any later flush.
     */
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

    private function freshEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function reloadPlayer(Player $player): Player
    {
        $reloaded = $this->freshEntityManager()->find(Player::class, $player->id);
        $this->assertInstanceOf(Player::class, $reloaded);

        return $reloaded;
    }
}
