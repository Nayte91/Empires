<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use App\Entity\GameSession;
use App\Entity\Order;
use App\Entity\Player;
use App\Repository\OrderRepository;
use App\Shop\Dto\OrderLine;
use App\Shop\OrderStatus;
use App\Shop\Promotion\AppliedPromotion;
use App\Shop\Promotion\OptionCredits;
use App\Shop\Promotion\PromotionType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OptionCreditsTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private OrderRepository $orderRepository;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->orderRepository = self::getContainer()->get(OrderRepository::class);
    }

    #[Test]
    public function forPlayerSumsAllocationsFromValidatedOrdersByCategory(): void
    {
        $player = $this->createPlayer();
        $this->createValidatedOrder($player, 1, ['craft' => 10, 'science' => 10]);

        $credits = new OptionCredits($this->orderRepository)->forPlayer($player);

        self::assertSame(['craft' => 10, 'science' => 10], $credits);
    }

    #[Test]
    public function forPlayerCumulatesAcrossSeveralValidatedOrders(): void
    {
        $player = $this->createPlayer();
        $this->createValidatedOrder($player, 1, ['craft' => 10, 'science' => 10]);
        $this->createValidatedOrder($player, 2, ['craft' => 5]);

        $credits = new OptionCredits($this->orderRepository)->forPlayer($player);

        self::assertSame(['craft' => 15, 'science' => 10], $credits);
    }

    #[Test]
    public function forPlayerIgnoresAPendingOrdersOwnAllocation(): void
    {
        $player = $this->createPlayer();
        $order = new Order($player, 1);
        $order->replaceLines([
            new OrderLine('monument', 180, new AppliedPromotion(PromotionType::Option, 'monument', allocation: ['craft' => 10, 'science' => 10])),
        ]);
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        $credits = new OptionCredits($this->orderRepository)->forPlayer($player);

        self::assertSame([], $credits);
    }

    #[Test]
    public function forPlayerWithNoOrdersReturnsEmptyArray(): void
    {
        $player = $this->createPlayer();

        $credits = new OptionCredits($this->orderRepository)->forPlayer($player);

        self::assertSame([], $credits);
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

    /** @param array<string, int> $allocation */
    private function createValidatedOrder(Player $player, int $turn, array $allocation): Order
    {
        $order = new Order($player, $turn);
        $line = new OrderLine('monument', 180, new AppliedPromotion(PromotionType::Option, 'monument', allocation: $allocation));
        $order->replaceLines([$line]);
        $order->freeze([$line], 180);
        $order->setMarking(OrderStatus::Validated->value);
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }
}
