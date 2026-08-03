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

    /**
     * The slug is not a second field to keep in step, it is derived by Player's own name hook —
     * and it is the URL segment the board is served under, so the write is only complete once the
     * database holds both.
     */
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
    public function aRenameAnnouncesThePlayerAsUpdatedOnTheGamesTopic(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);

        ($this->handler)(new RenamePlayer($player->id, 'Bob the Builder'));

        $this->assertSame(['player-updated'], $this->hub->eventNames());
        $this->assertSame(['empires/game/'.$game->id], $this->hub->topics());
    }

    /**
     * Mirrors SetStatHandler's guard: a request that asks for the name already stored writes
     * nothing and, above all, spares every board subscribed to the game a refresh it has no
     * reason to perform.
     */
    #[Test]
    public function renamingAPlayerToTheNameTheyAlreadyCarryIsANoOp(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);

        ($this->handler)(new RenamePlayer($player->id, 'Bob'));

        $this->assertSame([], $this->hub->eventNames());
        $this->assertSame('Bob', $player->name);
        $this->assertSame('bob', $player->slug);
    }

    /**
     * uniq_player_game_slug spans (game_id, slug), so this flush must reach the database rather
     * than bounce off a constraint — the per-game scoping is what the rename form's uniqueness
     * check is allowed to assume.
     */
    #[Test]
    public function twoPlayersOfDifferentGamesMayCarryTheSameName(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $otherGame = GameBuilder::create()->persist($this->entityManager);
        $bob = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($otherGame)->persist($this->entityManager);
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
