<?php

declare(strict_types=1);

namespace App\Tests\Game\Shop;

use App\Entity\GameSession;
use App\Entity\Player;
use App\Game\AdvanceCatalog;
use App\Game\Shop\AdvanceFulfillment;
use App\Repository\PlayerRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdvanceFulfillmentTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private AdvanceFulfillment $fulfillment;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $playerRepository = self::getContainer()->get(PlayerRepository::class);
        $advanceCatalog = self::getContainer()->get(AdvanceCatalog::class);
        $this->fulfillment = new AdvanceFulfillment($playerRepository, $advanceCatalog);
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

        $this->assertSame(
            [
                ['turn' => 4, 'scope' => 'art', 'value' => 5, 'reason' => 'advance:pottery'],
                ['turn' => 4, 'scope' => 'craft', 'value' => 10, 'reason' => 'advance:pottery'],
                ['turn' => 4, 'scope' => 'agriculture', 'value' => 10, 'reason' => 'advance:pottery'],
            ],
            $player->creditLedger,
        );
        $this->assertContains('pottery', $player->advances);
    }

    /**
     * The non-negotiable grant()/revoke() symmetry: erasing an order must
     * bring every scope the advance credited back to exactly what it was
     * before the grant, never leaving a residual balance.
     */
    #[Test]
    public function revokingAnAdvanceThatWasJustGrantedBringsEveryScopeBackToItsPriorBalance(): void
    {
        $player = $this->createPlayer();

        $this->fulfillment->grant($player->id, ['pottery']);
        $this->fulfillment->revoke($player->id, ['pottery']);

        $this->assertSame(0, $this->balanceFor($player->creditLedger, 'art'));
        $this->assertSame(0, $this->balanceFor($player->creditLedger, 'craft'));
        $this->assertSame(0, $this->balanceFor($player->creditLedger, 'agriculture'));
        $this->assertSame([], $player->advances);
    }

    /**
     * The plafonnage rule: a scope worth 5 can only ever lose 5, never the
     * full 10 pottery's craft entry would otherwise take away.
     */
    #[Test]
    public function revokingCapsTheNegativeEntryAtTheScopesAvailableBalanceRatherThanTakingTheFullAmount(): void
    {
        $player = $this->createPlayer();
        $player->postCredit(1, 'craft', 5, 'test-fixture');

        $this->fulfillment->revoke($player->id, ['pottery']);

        $this->assertSame(-5, $this->lastValueFor($player->creditLedger, 'craft'));
        $this->assertSame(0, $this->balanceFor($player->creditLedger, 'craft'));
    }

    /**
     * The case that breaks if the negative entry is ever written uncapped
     * (-10 instead of -5): a hidden -5 would still be sitting in the ledger,
     * so a later +5 gain would only bring the scope back to 0 instead of 5 —
     * silently swallowing credits the player has genuinely earned since.
     */
    #[Test]
    public function aLaterGainAfterACappedRevokeIsNotSwallowedByAHiddenNegativeBalance(): void
    {
        $player = $this->createPlayer();
        $player->postCredit(1, 'craft', 5, 'test-fixture');
        $this->fulfillment->revoke($player->id, ['pottery']);

        $player->postCredit(2, 'craft', 5, 'later-gain');

        $this->assertSame(5, $this->balanceFor($player->creditLedger, 'craft'));
    }

    /**
     * Revoking credits a player never earned in the first place must not
     * drive any scope's balance below zero.
     */
    #[Test]
    public function aScopesBalanceIsNeverNegativeEvenWhenRevokingCreditsThatWereNeverGranted(): void
    {
        $player = $this->createPlayer();

        $this->fulfillment->revoke($player->id, ['pottery']);

        $this->assertGreaterThanOrEqual(0, $this->balanceFor($player->creditLedger, 'art'));
        $this->assertGreaterThanOrEqual(0, $this->balanceFor($player->creditLedger, 'craft'));
        $this->assertGreaterThanOrEqual(0, $this->balanceFor($player->creditLedger, 'agriculture'));
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

    /** @param list<array{turn: int, scope: string, value: int, reason: string}> $creditLedger */
    private function balanceFor(array $creditLedger, string $scope): int
    {
        $balance = 0;

        foreach ($creditLedger as $entry) {
            if ($scope === $entry['scope']) {
                $balance += $entry['value'];
            }
        }

        return $balance;
    }

    /** @param list<array{turn: int, scope: string, value: int, reason: string}> $creditLedger */
    private function lastValueFor(array $creditLedger, string $scope): int
    {
        $matching = array_values(array_filter($creditLedger, static fn (array $entry): bool => $scope === $entry['scope']));

        return $matching[array_key_last($matching)]['value'] ?? throw new \RuntimeException(sprintf('No ledger entry for scope "%s".', $scope));
    }
}
