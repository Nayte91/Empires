<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GameSession;
use App\Entity\Player;
use App\Game\Service\TaxCalculator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class StatPickerTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private EntityManagerInterface $entityManager; // @phpstan-ignore property.uninitialized (initialized in setUp)

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function savePersistsTheNewValue(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Bob');

        $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'cities',
        ])->set('value', 7)->call('save');

        $this->assertSame(7, $this->reloadPlayer($player)->cities);
    }

    #[Test]
    public function saveClampsAtMaximum(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Bob');

        $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'cities',
        ])->set('value', 42)->call('save');

        $this->assertSame(9, $this->reloadPlayer($player)->cities);
    }

    #[Test]
    public function saveWithUnchangedValueIsNoOp(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Bob');
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
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Bob');

        $rendered = $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'cities',
        ])->render()->toString();

        for ($i = 0; $i <= 9; ++$i) {
            $this->assertStringContainsString((string) $i, $rendered);
        }
    }

    #[Test]
    public function renderContainsOkButton(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Bob');

        $rendered = $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'cities',
        ])->render()->toString();

        $this->assertStringContainsString('>OK<', $rendered);
    }

    #[Test]
    public function censusSavePersistsTheNewValue(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Bob');

        $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'census',
        ])->set('value', 30)->call('save');

        $this->assertSame(30, $this->reloadPlayer($player)->census);
    }

    #[Test]
    public function treasurySavePersistsTheNewValue(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Bob');

        $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'treasury',
        ])->set('value', 20)->call('save');

        $this->assertSame(20, $this->reloadPlayer($player)->treasury);
    }

    #[Test]
    public function censusRenderStartsAtTileOne(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Bob');

        $rendered = $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'census',
        ])->render()->toString();

        // Verify no "0" value in radio inputs
        $this->assertStringNotContainsString('value="0"', $rendered);
        // Verify "1" is present
        $this->assertStringContainsString('value="1"', $rendered);
    }

    #[Test]
    public function renderInitializesValueFromThePlayer(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Bob');
        $player->census = 30;
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'census',
        ])->render();

        $checked = $rendered->crawler()->filter('input[type="radio"][checked]');

        $this->assertSame('30', $checked->attr('value'));
    }

    #[Test]
    public function shipsSavePersistsTheNewValue(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Bob');

        $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'ships',
        ])->set('value', 2)->call('save');

        $this->assertSame(2, $this->reloadPlayer($player)->ships);
    }

    #[Test]
    public function cardsSavePersistsTheNewValue(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Bob');

        $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'cards',
        ])->set('value', 5)->call('save');

        $this->assertSame(5, $this->reloadPlayer($player)->cards);
    }

    #[Test]
    public function astPositionSavePersistsStorageValueAtMaximum(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Bob');

        $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'astPosition',
        ])->set('value', 15)->call('save');

        $this->assertSame(15, $this->reloadPlayer($player)->astPosition);
    }

    #[Test]
    public function astPositionSaveMidRangePersistsStorageValue(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Bob');

        $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'astPosition',
        ])->set('value', 4)->call('save');

        $this->assertSame(4, $this->reloadPlayer($player)->astPosition);
    }

    #[Test]
    public function astPositionRenderShowsDisplayLabelWithStorageCheckedValue(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Bob');
        $player->astPosition = 4;
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'astPosition',
            'value' => $player->astPosition,
        ])->render();

        $checked = $rendered->crawler()->filter('input[type="radio"][checked]');
        $this->assertSame('4', $checked->attr('value'));

        $this->assertStringContainsString('5', $rendered->crawler()->filter('button')->text());
    }

    #[Test]
    public function mountedValueIsOverriddenByPassedValueProp(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Bob');
        $player->cities = 2;
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'cities',
            'value' => 7,
        ])->render();

        $checked = $rendered->crawler()->filter('input[type="radio"][checked]');
        $this->assertSame('7', $checked->attr('value'));
    }

    /**
     * Building a ship moves a stat this picker does not own, which is the whole reason the action
     * name travels to the server instead of a target value.
     */
    #[Test]
    public function buildingAShipPersistsBothTheFleetAndItsCost(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Bob');
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
        $game = $this->createGame();
        $game->playerCount = 12;
        $player = $this->createPlayer($game, 'Bob');
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
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Bob');
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
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Bob');
        $player->census = 20;
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'treasury',
        ])->render();

        $this->assertCount(0, $rendered->crawler()->filter('input[value="35"][disabled]'));
        $this->assertCount(1, $rendered->crawler()->filter('input[value="36"][disabled]'));
    }

    /**
     * Coinage unlocks the cheaper rate, Monarchy the dearer one; the rest stay off the menu.
     * Three players rather than one mutated in place: rendering a live component resets the
     * EntityManager, which detaches the entity and swallows any later flush.
     */
    #[Test]
    public function theTreasuryPickerOffersOnlyTheTaxRatesThePlayerUnlocked(): void
    {
        $game = $this->createGame();
        $plain = $this->createPlayer($game, 'Bob');
        $coinage = $this->createPlayer($game, 'Alice');
        $coinage->ownAdvances([TaxCalculator::RATE_CHOICE_ADVANCE]);
        $both = $this->createPlayer($game, 'Carol');
        $both->ownAdvances([TaxCalculator::RATE_CHOICE_ADVANCE, TaxCalculator::RATE_RAISE_ADVANCE]);
        $this->entityManager->flush();

        $this->assertSame(['payTaxes2'], $this->taxActionsOf($plain));
        $this->assertSame(['payTaxes1', 'payTaxes2', 'payTaxes3'], $this->taxActionsOf($coinage));
        $this->assertSame(['payTaxes1', 'payTaxes2', 'payTaxes3', 'payTaxes4'], $this->taxActionsOf($both));
    }

    #[Test]
    public function citiesPickerOffersNoActionButton(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Bob');

        $rendered = $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'cities',
        ])->render();

        $this->assertCount(0, $rendered->crawler()->filter('[data-value]'));
    }

    #[Test]
    public function shipsPickerRendersBothActionsAndDisablesWhatThePlayerCannotAfford(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Bob');

        $rendered = $this->createLiveComponent('molecules:StatPicker', [
            'player' => $player,
            'stat' => 'ships',
        ])->render();

        $actions = $rendered->crawler()->filter('[data-value]');

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
        ])->render()->crawler()->filter('[data-value]')->each(
            static fn ($node): string => (string) $node->attr('data-value'),
        );
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
