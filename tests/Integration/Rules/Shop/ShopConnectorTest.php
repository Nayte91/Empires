<?php

declare(strict_types=1);

namespace App\Tests\Integration\Rules\Shop;

use App\State\Order;
use App\State\Player;
use App\Rules\Ruleset\AdvanceRegistry;
use App\Rules\Ruleset\ScenarioRegistry;
use App\Rules\Shop\AdvancePriceResolver;
use App\State\CreditEntry;
use App\State\CreditSource;
use App\Rules\Shop\Entitlement;
use App\Rules\Shop\ShopConnector;
use App\Infrastructure\Repository\OrderRepository;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\OrderStatus;
use Userforged\ShopEngine\Promotion\AppliedPromotion;
use Userforged\ShopEngine\Promotion\PromotionType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ShopConnectorTest extends WebTestCase
{
    use GameFixtureTrait;

    private AdvanceRegistry $advanceRegistry;
    private ScenarioRegistry $scenarioRegistry;
    private ShopConnector $shopConnector;

    protected function setUp(): void
    {
        $this->initEntityManager();

        $orderRepository = self::getContainer()->get(OrderRepository::class);
        $this->advanceRegistry = self::getContainer()->get(AdvanceRegistry::class);
        $this->scenarioRegistry = self::getContainer()->get(ScenarioRegistry::class);
        $this->shopConnector = new ShopConnector($orderRepository);
    }

    #[Test]
    public function windowsToEraseReturnsEmptyWhenNoOrderExistsForTheTurn(): void
    {
        $player = $this->createPlayer();

        $this->assertSame([], $this->shopConnector->windowsToErase($player, 1));
    }

    #[Test]
    public function windowsToEraseReturnsOnlyThatTurnWhenTheOrderIsPending(): void
    {
        $player = $this->createPlayer();
        $this->createOrder($player, 1, ['pottery'], validated: false);

        $this->assertSame([1], $this->shopConnector->windowsToErase($player, 1));
    }

    #[Test]
    public function windowsToEraseCascadesToLaterTurnsWhenTheOrderIsValidated(): void
    {
        $player = $this->createPlayer();
        $this->createOrder($player, 1, ['pottery'], validated: true);
        $this->createOrder($player, 2, ['democracy'], validated: true);
        $this->createOrder($player, 3, ['law'], validated: false);

        $this->assertSame([1, 2, 3], $this->shopConnector->windowsToErase($player, 1));
    }

    #[Test]
    public function buyerForSumsOptionAllocationsFromValidatedOrdersByFacet(): void
    {
        $player = $this->createPlayer();
        $this->createValidatedOptionOrder($player, 1, ['craft' => 10, 'science' => 10]);

        $this->assertSame(['craft' => 10, 'science' => 10], $this->creditsByScope($this->shopConnector->buyerFor($player)->entitlements));
    }

    #[Test]
    public function buyerForCumulatesOptionAllocationsAcrossSeveralValidatedOrders(): void
    {
        $player = $this->createPlayer();
        $this->createValidatedOptionOrder($player, 1, ['craft' => 10, 'science' => 10]);
        $this->createValidatedOptionOrder($player, 2, ['craft' => 5]);

        $this->assertSame(['craft' => 15, 'science' => 10], $this->creditsByScope($this->shopConnector->buyerFor($player)->entitlements));
    }

    /**
     * The load-bearing no-self-crediting guarantee, now enforced by the
     * Validated filter in ShopConnector::buyerFor() rather than by
     * OptionCredits itself (see Userforged\ShopEngine\Promotion\OptionCredits, now a pure
     * aggregate() over already-filtered lines).
     */
    #[Test]
    public function buyerForIgnoresAPendingOrdersOwnAllocation(): void
    {
        $player = $this->createPlayer();
        $order = new Order($player, 1);
        $order->replaceLines([
            new OrderLine('monument', 180, new AppliedPromotion(PromotionType::Option, 'monument', allocation: ['craft' => 10, 'science' => 10])),
        ]);
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        $this->assertSame([], $this->creditsByScope($this->shopConnector->buyerFor($player)->entitlements));
    }

    #[Test]
    public function buyerForWithNoOrdersHasNoEntitlementsAndNoOwnedKeys(): void
    {
        $player = $this->createPlayer();

        $buyer = $this->shopConnector->buyerFor($player);

        $this->assertSame([], $buyer->entitlements);
        $this->assertSame([], $buyer->ownedKeys);
        $this->assertSame($player->id, $buyer->id);
    }

    /**
     * The scenario's starting credits are the third Entitlement source
     * ShopConnector::buyerFor() composes, alongside owned advances and
     * elective allocations — wired here for the first time (config/game/
     * scenarios.yaml's `3.credits` key, previously read by nothing in
     * production). Game::$playerCount defaults to 9, which the
     * scenario file has no credits for, so this must set it explicitly.
     */
    #[Test]
    public function buyerForGrantsStartingCreditsEntitlementsSourcedFromTheScenarioForAThreePlayerGame(): void
    {
        $player = $this->createPlayer(playerCount: 3);

        $buyer = $this->shopConnector->buyerFor($player);

        $this->assertSame(
            ['art' => 10, 'civic' => 10, 'craft' => 10, 'religion' => 10, 'science' => 10],
            $this->creditsByScope($buyer->entitlements),
        );
    }

    /**
     * The negative example the spec warns against: capping the running SUM at
     * zero would let the intervening -10 permanently erase 5 of credit —
     * 5-10+5=0, floored, stays 0. Capping each withdrawal at its own step
     * instead means the -10 only ever takes what is available (5) at that
     * point, so the later +5 lands on top of what remains: 5.
     */
    #[Test]
    public function theChronologicalWalkCapsEachWithdrawalAtItsOwnStepRatherThanTheRunningTotal(): void
    {
        $player = $this->createPlayer();
        $player->postCredit(new CreditEntry(1, 'craft', 5, CreditSource::Shop, 'advance:pottery'));
        $player->postCredit(new CreditEntry(2, 'craft', -10, CreditSource::Shop, 'real-loss'));
        $player->postCredit(new CreditEntry(3, 'craft', 5, CreditSource::Shop, 'later-gain'));

        $this->assertSame(['craft' => 5], $this->creditsByScope($this->shopConnector->buyerFor($player)->entitlements));
    }

    #[Test]
    public function aWithdrawalLargerThanTheAvailableBalanceNeverDrivesTheScopeBelowZero(): void
    {
        $player = $this->createPlayer();
        $player->postCredit(new CreditEntry(1, 'craft', 5, CreditSource::Shop, 'advance:pottery'));
        $player->postCredit(new CreditEntry(2, 'craft', -100, CreditSource::Shop, 'real-loss'));

        $this->assertSame(['craft' => 0], $this->creditsByScope($this->shopConnector->buyerFor($player)->entitlements));
    }

    /**
     * Mandatory coverage for the behaviour ShopConnector::buyerFor() newly
     * unlocks: a 3-player scenario grants 10 starting credits per facet
     * (config/game/scenarios.yaml), so pottery (cost 60, craft-faceted) nets
     * 10 cheaper for a player who owns nothing and has no elective credits.
     * Game::$playerCount defaults to 9, which has no scenario
     * credits, so no pre-existing 9-player test is affected by this wiring.
     */
    #[Test]
    public function resolvingAnAdvancesPriceForAThreePlayerGameDiscountsItByTheScenariosStartingCredits(): void
    {
        $player = $this->createPlayer(playerCount: 3);
        $pottery = $this->advanceRegistry->getAdvanceByName('pottery') ?? throw new \RuntimeException('Advance "pottery" not found in the real catalog.');
        $resolver = new AdvancePriceResolver();

        $net = $resolver->resolve($pottery, $this->shopConnector->buyerFor($player));

        $this->assertSame(50, $net);
    }

    /**
     * @param list<Entitlement> $entitlements
     *
     * @return array<string, int>
     */
    private function creditsByScope(array $entitlements): array
    {
        $credits = [];

        foreach ($entitlements as $entitlement) {
            $credits[$entitlement->scope] = ($credits[$entitlement->scope] ?? 0) + $entitlement->value;
        }

        return $credits;
    }

    /** @param array<string, int> $allocation */
    private function createValidatedOptionOrder(Player $player, int $turn, array $allocation): void
    {
        $line = new OrderLine('monument', 180, new AppliedPromotion(PromotionType::Option, 'monument', allocation: $allocation));

        $order = new Order($player, $turn);
        $order->replaceLines([$line]);
        $order->freeze([$line], 180);
        $order->setMarking(OrderStatus::Validated->value);

        $this->entityManager->persist($order);
        $this->entityManager->flush();
    }

    private function createPlayer(int $playerCount = 9): Player
    {
        // build() rather than persist(): the ledger below has to be posted before the flush,
        // so this fixture owns its own write.
        $player = PlayerBuilder::named('Alice')
            ->in(GameBuilder::create()->withPlayerCount($playerCount)->build())
            ->build()
        ;

        // Mirrors CreateGameHandler's own write (never dispatched here — this class has
        // no bus dependency of its own) so the ledger carries what a real game creation
        // would have posted; a no-op for the default 9-player count, which has no
        // scenario credits.
        foreach ($this->scenarioRegistry->startingCreditsFor($playerCount) as $scope => $value) {
            $player->postCredit(new CreditEntry(0, $scope, $value, CreditSource::Scenario, 'scenario:'.$playerCount));
        }

        $this->entityManager->persist($player->game);
        $this->entityManager->persist($player);
        $this->entityManager->flush();

        return $player;
    }

    /** @param list<string> $slugs */
    private function createOrder(Player $player, int $turn, array $slugs, bool $validated): void
    {
        $lines = array_map(static fn (string $slug): OrderLine => new OrderLine($slug, 0), $slugs);

        $order = new Order($player, $turn);
        $order->replaceLines($lines);

        if ($validated) {
            $order->freeze($lines, 0);
            $order->setMarking(OrderStatus::Validated->value);
        }

        $this->entityManager->persist($order);
        $this->entityManager->flush();
    }
}
