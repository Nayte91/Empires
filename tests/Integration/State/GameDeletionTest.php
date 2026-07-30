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

/**
 * A game's players — and the orders those players placed — do not outlive it.
 *
 * The rule is carried at two levels on purpose, because neither alone covers the graph:
 * the ORM cascade on Game::$players reaches the players and keeps the UnitOfWork in step,
 * but cannot reach the orders (Player deliberately has no $orders inverse collection, so
 * there is no association for Doctrine to traverse). The orders are swept by the database
 * cascade on orders.player_id, which in turn only fires because the EnableForeignKeys
 * middleware issues `PRAGMA foreign_keys=ON` — SQLite otherwise ignores the constraint.
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

        $aliceOrder = new Order($alice, $game->currentTurn);
        $bobOrder = new Order($bob, $game->currentTurn);
        $this->entityManager->persist($aliceOrder);
        $this->entityManager->persist($bobOrder);
        $this->entityManager->flush();

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

    /**
     * The database-level half of the rule, exercised without the ORM: a raw DELETE — the shape
     * of a hand-run `dbal:run-sql` fix, which is how the dev database grew its orphan players —
     * must sweep the same rows. The ORM cascade never runs on this path.
     */
    #[Test]
    public function deletingAGameRowDirectlyAlsoSweepsPlayersAndOrders(): void
    {
        $game = GameBuilder::create()->withSlug('doomed-by-sql')->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);

        $order = new Order($player, $game->currentTurn);
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        $connection = $this->entityManager->getConnection();
        $connection->executeStatement(
            'DELETE FROM game WHERE id = ?',
            [$game->id->toBinary()],
        );

        $this->assertSame(0, $this->countRows('player'));
        $this->assertSame(0, $this->countRows('orders'));
    }

    /**
     * Removing a single player must not drag the game down with it, nor spare that player's
     * orders — the cascade is one-directional, and orphanRemoval was deliberately not used.
     */
    #[Test]
    public function deletingAPlayerLeavesTheGameStandingAndSweepsOnlyItsOwnOrders(): void
    {
        $game = GameBuilder::create()->withSlug('survivor')->persist($this->entityManager);
        $alice = PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);
        $bob = PlayerBuilder::named('Bob')->in($game)->withEmpire('egypt')->persist($this->entityManager);

        $aliceOrder = new Order($alice, $game->currentTurn);
        $bobOrder = new Order($bob, $game->currentTurn);
        $this->entityManager->persist($aliceOrder);
        $this->entityManager->persist($bobOrder);
        $this->entityManager->flush();

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
