<?php

declare(strict_types=1);

namespace App\Tests\Integration\Engine\Shop;

use App\State\Player;
use App\Rules\Ruleset\AdvanceRegistry;
use App\Engine\Shop\AdvanceFulfillment;
use App\State\CreditEntry;
use App\State\CreditSource;
use App\Rules\Shop\ShopConnector;
use App\Infrastructure\Repository\OrderRepository;
use App\Infrastructure\Repository\PlayerRepository;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdvanceFulfillmentTest extends WebTestCase
{
    use GameFixtureTrait;

    private AdvanceFulfillment $fulfillment;
    private ShopConnector $shopConnector;

    protected function setUp(): void
    {
        $this->initEntityManager();

        $playerRepository = self::getContainer()->get(PlayerRepository::class);
        $advanceRegistry = self::getContainer()->get(AdvanceRegistry::class);
        $orderRepository = self::getContainer()->get(OrderRepository::class);
        $this->fulfillment = new AdvanceFulfillment($playerRepository, $advanceRegistry);
        $this->shopConnector = new ShopConnector($orderRepository);
    }

    #[Test]
    public function grantingAnAdvancePostsEachOfItsCreditsAtTheWindowItWasBoughtInWithATraceableReason(): void
    {
        $player = PlayerBuilder::named('Alice')->in(GameBuilder::create()->withCurrentTurn(4)->build())->persist($this->entityManager);

        $this->fulfillment->grant($player->id, ['pottery'], 2);

        $this->assertEquals(
            [
                new CreditEntry(2, 'art', 5, CreditSource::Shop, 'advance:pottery'),
                new CreditEntry(2, 'craft', 10, CreditSource::Shop, 'advance:pottery'),
                new CreditEntry(2, 'agriculture', 10, CreditSource::Shop, 'advance:pottery'),
            ],
            $player->creditLedger,
        );
        $this->assertContains('pottery', $player->advances);
    }

    #[Test]
    public function revokingAnAdvanceThatWasJustGrantedRemovesEveryEntryItPostedRatherThanCompensatingThem(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $this->fulfillment->grant($player->id, ['pottery'], $player->game->currentTurn);
        $this->fulfillment->revoke($player->id, ['pottery']);

        $this->assertSame([], $player->creditLedger);
        $this->assertSame([], $player->advances);
    }

    #[Test]
    public function revokingAnAdvanceOnlyRemovesEntriesReasonedByThatAdvanceLeavingOtherReasonsUntouched(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $player->postCredit(new CreditEntry(1, 'craft', 5, CreditSource::Shop, 'test-fixture'));

        $this->fulfillment->revoke($player->id, ['pottery']);

        $this->assertEquals(
            [new CreditEntry(1, 'craft', 5, CreditSource::Shop, 'test-fixture')],
            $player->creditLedger,
        );
    }

    #[Test]
    public function revokingAGrantAfterARealLossInTheSameScopeNeverDrivesTheWalkedBalanceNegative(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $this->fulfillment->grant($player->id, ['pottery'], $player->game->currentTurn);
        $player->postCredit(new CreditEntry(2, 'craft', -10, CreditSource::Shop, 'real-loss'));

        $this->fulfillment->revoke($player->id, ['pottery']);

        $this->assertSame(0, $this->walkedBalanceFor($player, 'craft'));
    }

    #[Test]
    public function revokingAnAdvanceThatWasNeverGrantedLeavesTheLedgerUntouched(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $this->fulfillment->revoke($player->id, ['pottery']);

        $this->assertSame([], $player->creditLedger);
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
