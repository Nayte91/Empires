<?php

declare(strict_types=1);

namespace App\Tests\Integration\Rules\Shop;

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
use Userforged\ShopEngine\Promotion\AppliedPromotion;
use Userforged\ShopEngine\Promotion\PromotionType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use App\Tests\Support\Fixture\OrderBuilder;
use App\State\Region;

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
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $this->assertSame([], $this->shopConnector->windowsToErase($player, 1));
    }

    #[Test]
    public function windowsToEraseReturnsOnlyThatTurnWhenTheOrderIsPending(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        OrderBuilder::for($player)->onTurn(1)->withKeys('pottery')->persist($this->entityManager);

        $this->assertSame([1], $this->shopConnector->windowsToErase($player, 1));
    }

    #[Test]
    public function windowsToEraseCascadesToLaterTurnsWhenTheOrderIsValidated(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        OrderBuilder::for($player)->onTurn(1)->withKeys('pottery')->validated()->persist($this->entityManager);
        OrderBuilder::for($player)->onTurn(2)->withKeys('democracy')->validated()->persist($this->entityManager);
        OrderBuilder::for($player)->onTurn(3)->withKeys('law')->persist($this->entityManager);

        $this->assertSame([1, 2, 3], $this->shopConnector->windowsToErase($player, 1));
    }

    #[Test]
    public function buyerForSumsOptionAllocationsFromValidatedOrdersByFacet(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        OrderBuilder::for($player)->onTurn(1)->withLine(new OrderLine('monument', 180, new AppliedPromotion(PromotionType::Option, 'monument', allocation: ['craft' => 10, 'science' => 10])))->validated(180)->persist($this->entityManager);

        $this->assertSame(['craft' => 10, 'science' => 10], $this->creditsByScope($this->shopConnector->buyerFor($player)->entitlements));
    }

    #[Test]
    public function buyerForCumulatesOptionAllocationsAcrossSeveralValidatedOrders(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        OrderBuilder::for($player)->onTurn(1)->withLine(new OrderLine('monument', 180, new AppliedPromotion(PromotionType::Option, 'monument', allocation: ['craft' => 10, 'science' => 10])))->validated(180)->persist($this->entityManager);
        OrderBuilder::for($player)->onTurn(2)->withLine(new OrderLine('monument', 180, new AppliedPromotion(PromotionType::Option, 'monument', allocation: ['craft' => 5])))->validated(180)->persist($this->entityManager);

        $this->assertSame(['craft' => 15, 'science' => 10], $this->creditsByScope($this->shopConnector->buyerFor($player)->entitlements));
    }

    #[Test]
    public function buyerForIgnoresAPendingOrdersOwnAllocation(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        OrderBuilder::for($player)
            ->onTurn(1)
            ->withLine(new OrderLine('monument', 180, new AppliedPromotion(PromotionType::Option, 'monument', allocation: ['craft' => 10, 'science' => 10])))
            ->persist($this->entityManager)
        ;

        $this->assertSame([], $this->creditsByScope($this->shopConnector->buyerFor($player)->entitlements));
    }

    #[Test]
    public function buyerForWithNoOrdersHasNoEntitlementsAndNoOwnedKeys(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $buyer = $this->shopConnector->buyerFor($player);

        $this->assertSame([], $buyer->entitlements);
        $this->assertSame([], $buyer->ownedKeys);
        $this->assertSame($player->id, $buyer->id);
    }

    /** Game::$playerCount defaults to 9, which the scenario file has no credits for — set it explicitly. */
    #[Test]
    public function buyerForGrantsStartingCreditsEntitlementsSourcedFromTheScenarioForAThreePlayerGame(): void
    {
        $player = PlayerBuilder::named('Alice')
            ->in(GameBuilder::create()->withPlayerCount(3)->build())
            ->withCredits(...$this->startingCreditsOf(3))
            ->persist($this->entityManager)
        ;

        $buyer = $this->shopConnector->buyerFor($player);

        $this->assertSame(
            ['art' => 10, 'civic' => 10, 'craft' => 10, 'religion' => 10, 'science' => 10],
            $this->creditsByScope($buyer->entitlements),
        );
    }

    #[Test]
    public function theChronologicalWalkCapsEachWithdrawalAtItsOwnStepRatherThanTheRunningTotal(): void
    {
        $player = PlayerBuilder::named('Alice')->withCredits(
            new CreditEntry(1, 'craft', 5, CreditSource::Shop, 'advance:pottery'),
            new CreditEntry(2, 'craft', -10, CreditSource::Shop, 'real-loss'),
            new CreditEntry(3, 'craft', 5, CreditSource::Shop, 'later-gain'),
        )->persist($this->entityManager);

        $this->assertSame(['craft' => 5], $this->creditsByScope($this->shopConnector->buyerFor($player)->entitlements));
    }

    #[Test]
    public function aWithdrawalLargerThanTheAvailableBalanceNeverDrivesTheScopeBelowZero(): void
    {
        $player = PlayerBuilder::named('Alice')->withCredits(
            new CreditEntry(1, 'craft', 5, CreditSource::Shop, 'advance:pottery'),
            new CreditEntry(2, 'craft', -100, CreditSource::Shop, 'real-loss'),
        )->persist($this->entityManager);

        $this->assertSame(['craft' => 0], $this->creditsByScope($this->shopConnector->buyerFor($player)->entitlements));
    }

    #[Test]
    public function resolvingAnAdvancesPriceForAThreePlayerGameDiscountsItByTheScenariosStartingCredits(): void
    {
        $player = PlayerBuilder::named('Alice')
            ->in(GameBuilder::create()->withPlayerCount(3)->build())
            ->withCredits(...$this->startingCreditsOf(3))
            ->persist($this->entityManager)
        ;
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

    /**
     * Read off the same yaml the handler would, so these tests answer to scenarios.yaml rather than
     * to a number copied out of it.
     *
     * @return list<CreditEntry>
     */
    private function startingCreditsOf(int $playerCount): array
    {
        $entries = [];

        foreach ($this->scenarioRegistry->find($playerCount, Region::West)->startingCredits ?? [] as $scope => $value) {
            $entries[] = new CreditEntry(0, $scope, $value, CreditSource::Scenario, 'scenario:'.$playerCount);
        }

        return $entries;
    }
}
