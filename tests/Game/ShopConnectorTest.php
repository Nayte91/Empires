<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Entity\GameSession;
use App\Entity\Order;
use App\Entity\Player;
use App\Game\Shop\ShopConnector;
use App\Repository\OrderRepository;
use App\Shop\Dto\OrderLine;
use App\Shop\OrderStatus;
use App\Shop\Promotion\AppliedPromotion;
use App\Shop\Promotion\PromotionType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ShopConnectorTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private ShopConnector $shopConnector;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $orderRepository = self::getContainer()->get(OrderRepository::class);
        $this->shopConnector = new ShopConnector($orderRepository);
    }

    #[Test]
    public function windowsToEraseReturnsEmptyWhenNoOrderExistsForTheTurn(): void
    {
        $player = $this->createPlayer();

        self::assertSame([], $this->shopConnector->windowsToErase($player, 1));
    }

    #[Test]
    public function windowsToEraseReturnsOnlyThatTurnWhenTheOrderIsPending(): void
    {
        $player = $this->createPlayer();
        $this->createOrder($player, 1, ['pottery'], validated: false);

        self::assertSame([1], $this->shopConnector->windowsToErase($player, 1));
    }

    #[Test]
    public function windowsToEraseCascadesToLaterTurnsWhenTheOrderIsValidated(): void
    {
        $player = $this->createPlayer();
        $this->createOrder($player, 1, ['pottery'], validated: true);
        $this->createOrder($player, 2, ['democracy'], validated: true);
        $this->createOrder($player, 3, ['law'], validated: false);

        self::assertSame([1, 2, 3], $this->shopConnector->windowsToErase($player, 1));
    }

    #[Test]
    public function buyerForSumsOptionAllocationsFromValidatedOrdersByFacet(): void
    {
        $player = $this->createPlayer();
        $this->createValidatedOptionOrder($player, 1, ['craft' => 10, 'science' => 10]);

        self::assertSame(['craft' => 10, 'science' => 10], $this->shopConnector->buyerFor($player)->electiveCredits);
    }

    #[Test]
    public function buyerForCumulatesOptionAllocationsAcrossSeveralValidatedOrders(): void
    {
        $player = $this->createPlayer();
        $this->createValidatedOptionOrder($player, 1, ['craft' => 10, 'science' => 10]);
        $this->createValidatedOptionOrder($player, 2, ['craft' => 5]);

        self::assertSame(['craft' => 15, 'science' => 10], $this->shopConnector->buyerFor($player)->electiveCredits);
    }

    /**
     * The load-bearing no-self-crediting guarantee, now enforced by the
     * Validated filter in ShopConnector::buyerFor() rather than by
     * OptionCredits itself (see App\Shop\Promotion\OptionCredits, now a pure
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

        self::assertSame([], $this->shopConnector->buyerFor($player)->electiveCredits);
    }

    #[Test]
    public function buyerForWithNoOrdersHasEmptyElectiveCreditsAndOwnedKeys(): void
    {
        $player = $this->createPlayer();

        $buyer = $this->shopConnector->buyerFor($player);

        self::assertSame([], $buyer->electiveCredits);
        self::assertSame([], $buyer->ownedKeys);
        self::assertSame($player->id, $buyer->id);
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

    private function createPlayer(): Player
    {
        $game = new GameSession();
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
