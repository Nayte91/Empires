<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GameSession;
use App\Entity\Order;
use App\Entity\Player;
use App\Repository\OrderRepository;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\OrderStatus;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * Empires' declared policy (App\Game\Shop\OrderWorkflowPolicy): the shop_order
 * workflow's `reject` transition is canon-complete in
 * packages/userforged/shop-engine/ but is always
 * blocked here — Mega Civilization has no such thing as a refused purchase.
 * These tests run against the real, container-wired shop_order workflow
 * service (unlike tests/Shop/RejectOrderTest.php, which builds a bare
 * StateMachine with no listeners to prove the library itself stays capable).
 */
final class OrderRejectionPolicyTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithLiveComponents;

    #[Test]
    public function rejectOrderIsBlockedByThePolicyAndTheOrderStaysPending(): void
    {
        [$game, , $bob] = $this->createGameWithAliceAndBob();
        $this->createPendingOrderFor($bob, ['pottery']);

        $component = $this->createLiveComponent('PlayerOrders', [
            'player' => $bob,
            'ordersStamp' => '',
        ]);
        $rendered = $component->call('rejectOrder', ['turn' => $game->currentTurn])->render()->toString();

        $order = $this->freshOrderRepository()->findOneByPlayerAndWindow($bob, $game->currentTurn);
        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertStringContainsString('role="alert"', $rendered);
        $this->assertStringContainsString('cannot be rejected', $rendered);
    }

    #[Test]
    public function theRejectButtonNeverRendersForAPendingOrder(): void
    {
        [$game, , $bob] = $this->createGameWithAliceAndBob();
        $this->createPendingOrderFor($bob, ['pottery']);

        $rendered = $this->createLiveComponent('PlayerOrders', [
            'player' => $bob,
            'ordersStamp' => '',
        ])->render()->toString();

        $this->assertStringNotContainsString('data-live-action-param="rejectOrder"', $rendered);
    }

    /** @return array{GameSession, Player, Player} */
    private function createGameWithAliceAndBob(): array
    {
        $game = GameBuilder::create()->build();

        return [
            $game,
            PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager),
            PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager),
        ];
    }

    /** @param list<string> $slugs */
    private function createPendingOrderFor(Player $player, array $slugs): Order
    {
        $order = new Order($player, $player->game->currentTurn);
        $order->replaceLines(array_map(static fn (string $slug): OrderLine => new OrderLine($slug, 0), $slugs));
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }

    private function freshOrderRepository(): OrderRepository
    {
        return self::getContainer()->get(OrderRepository::class);
    }
}
