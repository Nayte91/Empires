<?php

declare(strict_types=1);

namespace App\Tests\Integration\Engine\Handler;

use App\Engine\Handler\RenamePlayerHandler;
use App\Rules\Action\RenamePlayer;
use App\State\Player;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\Mercure\RecordingHub;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class RenamePlayerHandlerTest extends WebTestCase
{
    private RenamePlayerHandler $handler;
    private EntityManagerInterface $entityManager;
    private RecordingHub $hub;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->handler = self::getContainer()->get(RenamePlayerHandler::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->hub = self::getContainer()->get(RecordingHub::class);
    }

    #[Test]
    public function writingANewNameRewritesTheSlugTheBoardUrlIsBuiltFrom(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);
        $playerId = $player->id;

        ($this->handler)(new RenamePlayer($playerId, 'Bob the Builder'));

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Player::class)->find($playerId);

        $this->assertInstanceOf(Player::class, $reloaded);
        $this->assertSame('Bob the Builder', $reloaded->name);
        $this->assertSame('bob-the-builder', $reloaded->slug);
    }

    #[Test]
    public function aRenameWakesTheSharedBoardsAndThatPlayersOwnBoard(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);

        ($this->handler)(new RenamePlayer($player->id, 'Bob the Builder'));

        $this->assertSame(['roster', 'ast', 'operator', 'player/'.$player->id], $this->hub->regions());
        $this->assertSame('empires/game/'.$game->id.'/roster', $this->hub->topics()[0]);
    }

    #[Test]
    public function renamingAPlayerToTheNameTheyAlreadyCarryIsANoOp(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);

        ($this->handler)(new RenamePlayer($player->id, 'Bob'));

        $this->assertSame([], $this->hub->regions());
        $this->assertSame('Bob', $player->name);
        $this->assertSame('bob', $player->slug);
    }

    #[Test]
    public function twoPlayersOfDifferentGamesMayCarryTheSameName(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $outsideBobsUniqPlayerGameSlugScope = GameBuilder::create()->persist($this->entityManager);
        $bob = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($outsideBobsUniqPlayerGameSlugScope)->persist($this->entityManager);
        $bobId = $bob->id;

        ($this->handler)(new RenamePlayer($bobId, 'Alice'));

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Player::class)->find($bobId);

        $this->assertInstanceOf(Player::class, $reloaded);
        $this->assertSame('Alice', $reloaded->name);
        $this->assertSame('alice', $reloaded->slug);
    }

    #[Test]
    public function renamingAPlayerWhoDoesNotExistFails(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Player not found.');

        ($this->handler)(new RenamePlayer(Uuid::v7(), 'Ghost'));
    }
}
