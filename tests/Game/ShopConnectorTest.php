<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Entity\GameSession;
use App\Entity\Order;
use App\Entity\Player;
use App\Game\AdvanceCatalog;
use App\Game\ScenarioCatalog;
use App\Game\Shop\AdvancePriceResolver;
use App\Game\Shop\Entitlement;
use App\Game\Shop\ShopConnector;
use App\Repository\OrderRepository;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\OrderStatus;
use Userforged\ShopEngine\Promotion\AppliedPromotion;
use Userforged\ShopEngine\Promotion\PromotionType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ShopConnectorTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private AdvanceCatalog $advanceCatalog;
    private ShopConnector $shopConnector;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $orderRepository = self::getContainer()->get(OrderRepository::class);
        $this->advanceCatalog = self::getContainer()->get(AdvanceCatalog::class);
        $scenarioCatalog = self::getContainer()->get(ScenarioCatalog::class);
        $this->shopConnector = new ShopConnector($orderRepository, $this->advanceCatalog, $scenarioCatalog);
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

        $this->assertSame(['craft' => 10, 'science' => 10], $this->creditsFromSource($this->shopConnector->buyerFor($player)->entitlements, 'elective'));
    }

    #[Test]
    public function buyerForCumulatesOptionAllocationsAcrossSeveralValidatedOrders(): void
    {
        $player = $this->createPlayer();
        $this->createValidatedOptionOrder($player, 1, ['craft' => 10, 'science' => 10]);
        $this->createValidatedOptionOrder($player, 2, ['craft' => 5]);

        $this->assertSame(['craft' => 15, 'science' => 10], $this->creditsFromSource($this->shopConnector->buyerFor($player)->entitlements, 'elective'));
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

        $this->assertSame([], $this->creditsFromSource($this->shopConnector->buyerFor($player)->entitlements, 'elective'));
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
     * production). GameSession::$playerCount defaults to 9, which the
     * scenario file has no credits for, so this must set it explicitly.
     */
    #[Test]
    public function buyerForGrantsStartingCreditsEntitlementsSourcedFromTheScenarioForAThreePlayerGame(): void
    {
        $player = $this->createPlayer(playerCount: 3);

        $buyer = $this->shopConnector->buyerFor($player);

        $this->assertSame(
            ['art' => 10, 'civic' => 10, 'craft' => 10, 'religion' => 10, 'science' => 10],
            $this->creditsFromSource($buyer->entitlements, 'scenario'),
        );
    }

    /**
     * Mandatory coverage for the behaviour ShopConnector::buyerFor() newly
     * unlocks: a 3-player scenario grants 10 starting credits per facet
     * (config/game/scenarios.yaml), so pottery (cost 60, craft-faceted) nets
     * 10 cheaper for a player who owns nothing and has no elective credits.
     * GameSession::$playerCount defaults to 9, which has no scenario
     * credits, so no pre-existing 9-player test is affected by this wiring.
     */
    #[Test]
    public function resolvingAnAdvancesPriceForAThreePlayerGameDiscountsItByTheScenariosStartingCredits(): void
    {
        $player = $this->createPlayer(playerCount: 3);
        $pottery = $this->advanceCatalog->getAdvanceByName('pottery') ?? throw new \RuntimeException('Advance "pottery" not found in the real catalog.');
        $resolver = new AdvancePriceResolver();

        $net = $resolver->resolve($pottery, $this->shopConnector->buyerFor($player));

        $this->assertSame(50, $net);
    }

    /**
     * @param list<Entitlement> $entitlements
     *
     * @return array<string, int>
     */
    private function creditsFromSource(array $entitlements, string $source): array
    {
        $credits = [];

        foreach ($entitlements as $entitlement) {
            if ($source === $entitlement->source) {
                $credits[$entitlement->scope] = $entitlement->value;
            }
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
        $game = new GameSession();
        $game->playerCount = $playerCount;
        $player = new Player($game, 'Alice');

        $this->entityManager->persist($game);
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
