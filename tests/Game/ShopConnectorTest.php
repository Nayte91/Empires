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
