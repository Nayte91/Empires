<?php

declare(strict_types=1);

namespace App\Tests\Integration\State;

use App\State\Game;
use App\State\Order;
use App\State\Player;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use App\Tests\Support\Fixture\OrderBuilder;

/**
 * Player deliberately has no $orders inverse collection, so the ORM cascade cannot reach the
 * orders; the database cascade on orders.player_id sweeps them, and only fires because the
 * EnableForeignKeys middleware issues `PRAGMA foreign_keys=ON`.
 */
final class GameDeletionTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function theConnectionEnforcesForeignKeys(): void
    {
        $enforced = $this->entityManager->getConnection()
            ->executeQuery('SELECT * FROM pragma_foreign_keys')
            ->fetchOne();

        $this->assertSame(1, (int) $enforced);
    }

    #[Test]
    public function deletingAGameDeletesItsPlayersAndTheirOrders(): void
    {
        $game = GameBuilder::create()->withSlug('doomed')->persist($this->entityManager);
        $alice = PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);
        $bob = PlayerBuilder::named('Bob')->in($game)->withEmpire('egypt')->persist($this->entityManager);

        $aliceOrder = OrderBuilder::for($alice)->persist($this->entityManager);
        $bobOrder = OrderBuilder::for($bob)->persist($this->entityManager);

        $gameId = $game->id;
        $playerIds = [$alice->id, $bob->id];
        $orderIds = [$aliceOrder->id, $bobOrder->id];

        $this->entityManager->remove($game);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->assertNotInstanceOf(Game::class, $this->entityManager->find(Game::class, $gameId));

        foreach ($playerIds as $playerId) {
            $this->assertNotInstanceOf(Player::class, $this->entityManager->find(Player::class, $playerId));
        }

        foreach ($orderIds as $orderId) {
            $this->assertNotInstanceOf(Order::class, $this->entityManager->find(Order::class, $orderId));
        }
    }

    #[Test]
    public function deletingAGameRowDirectlyAlsoSweepsPlayersAndOrders(): void
    {
        $game = GameBuilder::create()->withSlug('doomed-by-sql')->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);

        OrderBuilder::for($player)->persist($this->entityManager);

        $connection = $this->entityManager->getConnection();
        $connection->executeStatement(
            'DELETE FROM game WHERE id = ?',
            [$game->id->toBinary()],
        );

        $this->assertSame(0, $this->countRows('player'));
        $this->assertSame(0, $this->countRows('orders'));
    }

    #[Test]
    public function deletingAPlayerLeavesTheGameStandingAndSweepsOnlyItsOwnOrders(): void
    {
        $game = GameBuilder::create()->withSlug('survivor')->persist($this->entityManager);
        $alice = PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);
        $bob = PlayerBuilder::named('Bob')->in($game)->withEmpire('egypt')->persist($this->entityManager);

        $aliceOrder = OrderBuilder::for($alice)->persist($this->entityManager);
        $bobOrder = OrderBuilder::for($bob)->persist($this->entityManager);

        $gameId = $game->id;
        $bobId = $bob->id;
        $bobOrderId = $bobOrder->id;
        $aliceOrderId = $aliceOrder->id;

        $this->entityManager->remove($alice);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->assertInstanceOf(Game::class, $this->entityManager->find(Game::class, $gameId));
        $this->assertInstanceOf(Player::class, $this->entityManager->find(Player::class, $bobId));
        $this->assertInstanceOf(Order::class, $this->entityManager->find(Order::class, $bobOrderId));
        $this->assertNotInstanceOf(Order::class, $this->entityManager->find(Order::class, $aliceOrderId));
    }

    private function countRows(string $table): int
    {
        return (int) $this->entityManager->getConnection()
            ->executeQuery(\sprintf('SELECT COUNT(*) FROM %s', $table))
            ->fetchOne();
    }
}
