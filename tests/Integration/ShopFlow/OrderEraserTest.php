<?php

declare(strict_types=1);

namespace App\Tests\Integration\ShopFlow;

use App\Infrastructure\Repository\OrderRepository;
use App\Rules\Shop\ShopConnector;
use App\State\Order;
use App\Tests\Support\Fixture\OrderBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use App\Tests\Support\ShopFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Userforged\ShopEngine\BuyerProviderInterface;
use Userforged\ShopEngine\Command\EraseOrders;
use Userforged\ShopEngine\Command\SellDirect;
use Userforged\ShopEngine\Command\SubmitOrder;
use Userforged\ShopEngine\CommandHandler\EraseOrdersHandler;
use Userforged\ShopEngine\CommandHandler\SellDirectHandler;
use Userforged\ShopEngine\CommandHandler\SubmitOrderHandler;
use Userforged\ShopEngine\Dto\LineIntent;
use Userforged\ShopEngine\Event\ShopEventPublisher;
use Userforged\ShopEngine\FulfillmentInterface;
use Userforged\ShopEngine\OrderStatus;
use Userforged\ShopEngine\ProductInterface;
use Userforged\ShopEngine\ProductProviderInterface;
use Userforged\ShopEngine\Promotion\OptionCredits;
use Userforged\ShopEngine\Promotion\PromotionEngine;
use Userforged\ShopEngine\Service\LineQuoter;
use Userforged\ShopEngine\Service\OrderValidator;
use Userforged\ShopEngine\Service\PriceCalculator;
use Userforged\ShopEngine\TransactionInterface;

final class OrderEraserTest extends WebTestCase
{
    use GameFixtureTrait;
    use ShopFixtureTrait;

    private OrderRepository $orderRepository;
    private SellDirectHandler $sellDirectHandler;
    private EraseOrdersHandler $eraseOrdersHandler;
    private ShopConnector $shopConnector;

    protected function setUp(): void
    {
        $this->initEntityManager();

        $this->orderRepository = self::getContainer()->get(OrderRepository::class);
        $this->sellDirectHandler = self::getContainer()->get(SellDirectHandler::class);
        $this->eraseOrdersHandler = self::getContainer()->get(EraseOrdersHandler::class);
        $this->shopConnector = self::getContainer()->get(ShopConnector::class);
    }

    #[Test]
    public function erasingAPendingOrderDeletesItAndTouchesNothingElse(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $order = OrderBuilder::for($player)->onTurn(1)->withKeys('pottery')->persist($this->entityManager);

        ($this->eraseOrdersHandler)(new EraseOrders($player->id, [1]));

        $this->assertNotInstanceOf(Order::class, $this->orderRepository->find($order->id));
        $this->assertSame([], $this->reloadPlayer($player)->advances);
    }

    #[Test]
    public function erasingExplicitWindowsDisownsValidatedAdvancesButNotPendingOnesAndRemovesAll(): void
    {
        $player = PlayerBuilder::named('Alice')->withAdvances(['agriculture'])->persist($this->entityManager);

        $turnOneOrder = ($this->sellDirectHandler)(new SellDirect($player->id, $this->intents(['pottery']), 1));
        $turnTwoOrder = ($this->sellDirectHandler)(new SellDirect($player->id, $this->intents(['democracy']), 2));
        $turnThreeOrder = OrderBuilder::for($player)->onTurn(3)->withKeys('law')->persist($this->entityManager);

        ($this->eraseOrdersHandler)(new EraseOrders($player->id, [1, 2, 3]));

        $this->assertNotInstanceOf(Order::class, $this->orderRepository->find($turnOneOrder->id));
        $this->assertNotInstanceOf(Order::class, $this->orderRepository->find($turnTwoOrder->id));
        $this->assertNotInstanceOf(Order::class, $this->orderRepository->find($turnThreeOrder->id));

        $reloadedPlayer = $this->reloadPlayer($player);
        $this->assertSame(['agriculture'], $reloadedPlayer->advances);
    }

    #[Test]
    public function erasingOnlyTheRequestedWindowLeavesOtherOrdersIntact(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $turnOneOrder = ($this->sellDirectHandler)(new SellDirect($player->id, $this->intents(['pottery']), 1));
        $turnTwoOrder = ($this->sellDirectHandler)(new SellDirect($player->id, $this->intents(['democracy']), 2));

        ($this->eraseOrdersHandler)(new EraseOrders($player->id, [2]));

        $this->assertNotInstanceOf(Order::class, $this->orderRepository->find($turnTwoOrder->id));

        $reloadedTurnOneOrder = $this->orderRepository->find($turnOneOrder->id);
        $this->assertInstanceOf(Order::class, $reloadedTurnOneOrder);
        $this->assertSame(OrderStatus::Validated, $reloadedTurnOneOrder->status);

        $this->assertSame(['pottery'], $this->reloadPlayer($player)->advances);
    }

    #[Test]
    public function erasingAValidatedOrderWithAGiftLineDisownsTheGiftedAdvanceToo(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $order = ($this->sellDirectHandler)(new SellDirect($player->id, [new LineIntent('anatomy', gift: 'astronavigation')], 1));

        $this->assertSame(['anatomy', 'astronavigation'], $order->keys());
        $this->assertSame(['anatomy', 'astronavigation'], $this->reloadPlayer($player)->advances);

        ($this->eraseOrdersHandler)(new EraseOrders($player->id, [1]));

        $this->assertNotInstanceOf(Order::class, $this->orderRepository->find($order->id));
        $this->assertSame([], $this->reloadPlayer($player)->advances);
    }

    /**
     * PromotionEngine::applyGifts() drops a gift line silently when the catalog cannot resolve its
     * source, so freeze() ends with zero lines. Granting from the stale slugs while revoking the
     * frozen set leaks the grant for good.
     */
    #[Test]
    public function erasingAValidatedOrderWhoseGiftSourceVanishedFromTheCatalogBeforeValidationLeaksNothing(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $submitOrderHandler = self::getContainer()->get(SubmitOrderHandler::class);
        $orderValidator = $this->validatorAgainstACatalogWithout('anatomy');

        $order = ($submitOrderHandler)(new SubmitOrder($player->id, [new LineIntent('anatomy', gift: 'astronavigation')], 1));
        $this->assertSame(['anatomy', 'astronavigation'], $order->keys());

        $orderValidator->validate($order);

        $this->assertSame([], $order->keys());

        ($this->eraseOrdersHandler)(new EraseOrders($player->id, [1]));

        $this->assertSame([], $this->reloadPlayer($player)->advances);
    }

    #[Test]
    public function erasingAValidatedOrderWithAnOptionAllocationDropsItFromOptionCredits(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $order = ($this->sellDirectHandler)(new SellDirect(
            $player->id,
            [new LineIntent('monument', allocation: ['craft' => 10, 'science' => 10])],
            1,
        ));

        $this->assertSame(['craft' => 10, 'science' => 10], OptionCredits::aggregate($order->lines()));

        ($this->eraseOrdersHandler)(new EraseOrders($player->id, [1]));

        $this->assertNotInstanceOf(Order::class, $this->orderRepository->find($order->id));
        $this->assertSame([], $this->shopConnector->buyerFor($this->reloadPlayer($player))->entitlements);
    }

    #[Test]
    public function erasingWithNoWindowsIsANoOp(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $order = OrderBuilder::for($player)->onTurn(1)->withKeys('pottery')->persist($this->entityManager);

        ($this->eraseOrdersHandler)(new EraseOrders($player->id, []));

        $this->assertInstanceOf(Order::class, $this->orderRepository->find($order->id));
    }

    /** The container's OrderValidator always quotes against the real catalog; this one must fail to resolve a key. */
    private function validatorAgainstACatalogWithout(string $excludedKey): OrderValidator
    {
        $container = self::getContainer();
        $lineQuoter = new LineQuoter(
            $this->productProviderWithout($container->get(ProductProviderInterface::class), $excludedKey),
            $container->get(PriceCalculator::class),
            new PromotionEngine(),
            $this->shopConnector,
        );

        return new OrderValidator(
            $container->get(TransactionInterface::class),
            $lineQuoter,
            $container->get('state_machine.shop_order'),
            $container->get(BuyerProviderInterface::class),
            $container->get(ShopEventPublisher::class),
            $container->get(FulfillmentInterface::class),
        );
    }

    private function productProviderWithout(ProductProviderInterface $inner, string $excludedKey): ProductProviderInterface
    {
        return new readonly class($inner, $excludedKey) implements ProductProviderInterface {
            public function __construct(
                private ProductProviderInterface $inner,
                private string $excludedKey,
            ) {}

            public function products(): array
            {
                return $this->without($this->inner->products());
            }

            public function productsByKeys(array $keys): array
            {
                return $this->without($this->inner->productsByKeys($keys));
            }

            /**
             * @param list<ProductInterface> $products
             *
             * @return list<ProductInterface>
             */
            private function without(array $products): array
            {
                return array_values(array_filter($products, fn (ProductInterface $product): bool => $product->key !== $this->excludedKey));
            }
        };
    }
}
