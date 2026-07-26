<?php

declare(strict_types=1);

namespace App\Tests\Game\Shop;

use App\Entity\GameSession;
use App\Entity\Player;
use App\Game\AdvanceCatalog;
use App\Game\Shop\AdvanceFulfillment;
use App\Game\Shop\CreditEntry;
use App\Game\Shop\CreditSource;
use App\Game\Shop\ShopConnector;
use App\Repository\OrderRepository;
use App\Repository\PlayerRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdvanceFulfillmentTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private AdvanceFulfillment $fulfillment;
    private ShopConnector $shopConnector;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $playerRepository = self::getContainer()->get(PlayerRepository::class);
        $advanceCatalog = self::getContainer()->get(AdvanceCatalog::class);
        $orderRepository = self::getContainer()->get(OrderRepository::class);
        $this->fulfillment = new AdvanceFulfillment($playerRepository, $advanceCatalog);
        $this->shopConnector = new ShopConnector($orderRepository);
    }

    /**
     * Pottery's credits block (config/game/advances.yaml) is { art: 5, craft:
     * 10, agriculture: 10 } — one ledger entry per entry of that block, at
     * the player's current turn, reasoned by the advance's own key so the
     * grant stays traceable back to its source.
     */
    #[Test]
    public function grantingAnAdvancePostsEachOfItsCreditsAtThePlayersCurrentTurnWithATraceableReason(): void
    {
        $player = $this->createPlayer(currentTurn: 4);

        $this->fulfillment->grant($player->id, ['pottery']);

        $this->assertEquals(
            [
                new CreditEntry(4, 'art', 5, CreditSource::Shop, 'advance:pottery'),
                new CreditEntry(4, 'craft', 10, CreditSource::Shop, 'advance:pottery'),
                new CreditEntry(4, 'agriculture', 10, CreditSource::Shop, 'advance:pottery'),
            ],
            $player->creditLedger,
        );
        $this->assertContains('pottery', $player->advances);
    }

    /**
     * The non-negotiable grant()/revoke() symmetry: revoking an order must
     * bring every scope the advance credited back to exactly what it was
     * before the grant. revoke() achieves this by removing the entries
     * outright rather than compensating them, so the ledger itself must end
     * up empty — not merely balanced back to zero.
     */
    #[Test]
    public function revokingAnAdvanceThatWasJustGrantedRemovesEveryEntryItPostedRatherThanCompensatingThem(): void
    {
        $player = $this->createPlayer();

        $this->fulfillment->grant($player->id, ['pottery']);
        $this->fulfillment->revoke($player->id, ['pottery']);

        $this->assertSame([], $player->creditLedger);
        $this->assertSame([], $player->advances);
    }

    /**
     * Discriminating on the reason alone (see AdvanceFulfillment::revoke())
     * is deliberate and must stay scoped to it: an entry posted under a
     * different reason, even for the same scope pottery would have credited,
     * is untouched by revoking pottery.
     */
    #[Test]
    public function revokingAnAdvanceOnlyRemovesEntriesReasonedByThatAdvanceLeavingOtherReasonsUntouched(): void
    {
        $player = $this->createPlayer();
        $player->postCredit(new CreditEntry(1, 'craft', 5, CreditSource::Shop, 'test-fixture'));

        $this->fulfillment->revoke($player->id, ['pottery']);

        $this->assertEquals(
            [new CreditEntry(1, 'craft', 5, CreditSource::Shop, 'test-fixture')],
            $player->creditLedger,
        );
    }

    /**
     * The scenario that breaks if capping ever moves back to write time: a
     * real loss can only ever spend what a grant made available. Once that
     * grant is later revoked — removed outright, not offset — nothing
     * re-validates the loss that relied on it: a straight sum of what
     * remains (-10) would be negative. Only ShopConnector::ledgerEntitlements()'s
     * chronological replay, which caps each withdrawal against what is still
     * available at that point in the walk, keeps the balance at 0.
     */
    #[Test]
    public function revokingAGrantAfterARealLossInTheSameScopeNeverDrivesTheWalkedBalanceNegative(): void
    {
        $player = $this->createPlayer();
        $this->fulfillment->grant($player->id, ['pottery']);
        $player->postCredit(new CreditEntry(2, 'craft', -10, CreditSource::Shop, 'real-loss'));

        $this->fulfillment->revoke($player->id, ['pottery']);

        $this->assertSame(0, $this->walkedBalanceFor($player, 'craft'));
    }

    /**
     * Revoking an advance the player never owned finds no entry reasoned by
     * it, so the ledger is left exactly as it was.
     */
    #[Test]
    public function revokingAnAdvanceThatWasNeverGrantedLeavesTheLedgerUntouched(): void
    {
        $player = $this->createPlayer();

        $this->fulfillment->revoke($player->id, ['pottery']);

        $this->assertSame([], $player->creditLedger);
    }

    private function createPlayer(int $currentTurn = 1): Player
    {
        $game = new GameSession();
        $game->currentTurn = $currentTurn;
        $player = new Player($game, 'Alice');

        $this->entityManager->persist($game);
        $this->entityManager->persist($player);
        $this->entityManager->flush();

        return $player;
    }

    private function walkedBalanceFor(Player $player, string $scope): int
    {
        foreach ($this->shopConnector->buyerFor($player)->entitlements as $entitlement) {
            if ($scope === $entitlement->scope) {
                return $entitlement->value;
            }
        }

        return 0;
    }
}
