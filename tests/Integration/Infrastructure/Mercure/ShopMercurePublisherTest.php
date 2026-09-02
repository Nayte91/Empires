<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Mercure;

use App\State\Player;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use App\Tests\Support\Mercure\RecordingHub;
use App\Tests\Support\ShopFixtureTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Userforged\ShopEngine\Command\EraseOrders;
use Userforged\ShopEngine\Command\SellDirect;
use Userforged\ShopEngine\Command\SubmitOrder;
use Userforged\ShopEngine\CommandHandler\EraseOrdersHandler;
use Userforged\ShopEngine\CommandHandler\SellDirectHandler;
use Userforged\ShopEngine\CommandHandler\SubmitOrderHandler;
use Userforged\ShopEngine\OrderInterface;
use Userforged\ShopEngine\Service\OrderValidator;

final class ShopMercurePublisherTest extends WebTestCase
{
    use GameFixtureTrait;
    use ShopFixtureTrait;

    /**
     * @param \Closure(self, Player): void $mutation
     * @param \Closure(Player): list<string> $expectedRegions
     */
    #[Test]
    #[DataProvider('provideEveryShopMutationWakesTheRegionsItTouchesCases')]
    public function everyShopMutationWakesTheRegionsItTouches(\Closure $mutation, \Closure $expectedRegions): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $mutation($this, $player);

        $this->assertSame($expectedRegions($player), $this->hub()->regions());
    }

    public static function provideEveryShopMutationWakesTheRegionsItTouchesCases(): iterable
    {
        $purchase = self::purchaseRegions(...);

        yield 'submitting an order' => [
            static fn (self $test, Player $player): OrderInterface => $test->submit($player),
            static fn (Player $player): array => ['operator', 'player/'.$player->id.'/shop'],
        ];

        yield 'validating a submitted order' => [
            static function (self $test, Player $player): void {
                $order = $test->submit($player);
                $test->hub()->clear();

                self::getContainer()->get(OrderValidator::class)->validate($order);
            },
            $purchase,
        ];

        yield 'selling direct at the till' => [
            static fn (self $test, Player $player): OrderInterface => $test->sellDirect($player),
            $purchase,
        ];

        yield 'erasing a validated order' => [
            static function (self $test, Player $player): void {
                $test->sellDirect($player);
                $test->hub()->clear();

                self::getContainer()->get(EraseOrdersHandler::class)(new EraseOrders($player->id, [1]));
            },
            $purchase,
        ];
    }

    /**
     * The sale is the positive control: without it, an unchanged hub passes on a hub that never
     * listened.
     *
     * @param list<int> $windows
     */
    #[Test]
    #[DataProvider('provideAnEraseThatFindsNothingPublishesNothingCases')]
    public function anEraseThatFindsNothingPublishesNothing(array $windows): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $this->sellDirect($player);
        $wokenBySale = $this->hub()->regions();

        self::getContainer()->get(EraseOrdersHandler::class)(new EraseOrders($player->id, $windows));

        $this->assertSame(self::purchaseRegions($player), $wokenBySale);
        $this->assertSame($wokenBySale, $this->hub()->regions());
    }

    /** @return iterable<string, array{list<int>}> */
    public static function provideAnEraseThatFindsNothingPublishesNothingCases(): iterable
    {
        yield 'no windows at all' => [[]];

        yield 'windows without a matching order' => [[2, 3]];
    }

    /** @return list<string> */
    private static function purchaseRegions(Player $player): array
    {
        return ['roster', 'ast', 'operator', 'player/'.$player->id, 'player/'.$player->id.'/shop'];
    }

    private function submit(Player $player): OrderInterface
    {
        return self::getContainer()->get(SubmitOrderHandler::class)(new SubmitOrder($player->id, $this->intents(['pottery']), 1));
    }

    private function sellDirect(Player $player): OrderInterface
    {
        return self::getContainer()->get(SellDirectHandler::class)(new SellDirect($player->id, $this->intents(['pottery']), 1));
    }

    /** Fetched per call: the test container refuses to replace an already initialized service. */
    private function hub(): RecordingHub
    {
        return self::getContainer()->get(RecordingHub::class);
    }
}
