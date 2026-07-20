<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GameSession;
use App\Entity\Player;
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

        self::assertSame(7, $this->reloadPlayer($player)->cities);
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

        self::assertSame(9, $this->reloadPlayer($player)->cities);
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

        self::assertSame(5, $this->reloadPlayer($player)->cities);
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
            self::assertStringContainsString((string) $i, $rendered);
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

        self::assertStringContainsString('>OK<', $rendered);
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

        self::assertSame(30, $this->reloadPlayer($player)->census);
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

        self::assertSame(20, $this->reloadPlayer($player)->treasury);
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
        self::assertStringNotContainsString('value="0"', $rendered);
        // Verify "1" is present
        self::assertStringContainsString('value="1"', $rendered);
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

        self::assertSame('30', $checked->attr('value'));
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

        self::assertSame(2, $this->reloadPlayer($player)->ships);
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

        self::assertSame(5, $this->reloadPlayer($player)->cards);
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

        self::assertSame(15, $this->reloadPlayer($player)->astPosition);
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

        self::assertSame(4, $this->reloadPlayer($player)->astPosition);
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
        self::assertSame('4', $checked->attr('value'));

        self::assertStringContainsString('5', $rendered->crawler()->filter('button')->text());
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
        self::assertSame('7', $checked->attr('value'));
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
        self::assertInstanceOf(Player::class, $reloaded);

        return $reloaded;
    }
}
