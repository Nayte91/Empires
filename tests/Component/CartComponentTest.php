<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Presentation\Shop\CartKey;
use App\State\Order;
use App\State\Player;
use App\Infrastructure\Repository\OrderRepository;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use App\Tests\Support\ShopFixtureTrait;
use Userforged\ShopEngine\Cart;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * Both storage-key spellings live in CartKey — never retype one here: a self-consistent test keeps
 * passing long after production has moved on.
 */
final class CartComponentTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithLiveComponents;
    use ShopFixtureTrait;

    #[Test]
    public function removeRemovesTheGivenKey(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $client = self::getContainer()->get('test.client');
        $this->seedCart($client, (string) $player->id, Cart::fromKeys(['pottery', 'democracy']));

        $component = $this->createCart($player, client: $client);
        $rendered = $component->call('remove', ['key' => 'pottery'])->render()->toString();

        $cartLines = $this->extractCartLinesSection($rendered);
        $this->assertSame(1, substr_count($cartLines, 'class="line"'));
        $this->assertStringNotContainsString('Pottery', $cartLines);
        $this->assertStringContainsString('Democracy', $cartLines);
    }

    #[Test]
    public function cartShowsTheDiscountBadgeAndStruckThroughOriginalPriceWhenLibraryIsAdded(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $client = self::getContainer()->get('test.client');
        $this->seedCart($client, (string) $player->id, Cart::fromKeys(['library', 'democracy']));

        $rendered = $this->createCart($player, client: $client)->render()->toString();

        $this->assertStringContainsString('badge', $rendered);
        $this->assertStringContainsString('−40 library', $rendered);
        $this->assertStringContainsString('original', $rendered);
        $this->assertStringContainsString('Total: 400', $rendered);
    }

    #[Test]
    public function addingAnatomyShowsTheGiftPickerForAnEligibleScienceCandidate(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $client = self::getContainer()->get('test.client');
        $this->seedCart($client, (string) $player->id, Cart::fromKeys(['anatomy']));

        $rendered = $this->createCart($player, client: $client)->render()->toString();

        $this->assertStringContainsString('gift-picker', $rendered);
        $this->assertStringContainsString('Astronavigation', $rendered);
    }

    #[Test]
    public function addingMonumentShowsTheAllocationPickerWithTheFullRemainingPool(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $client = self::getContainer()->get('test.client');
        $this->seedCart($client, (string) $player->id, Cart::fromKeys(['monument']));

        $crawler = $this->createCart($player, client: $client)->render()->crawler();

        $this->assertStringContainsString('Remaining: 20', $crawler->filter('.allocation-picker')->text());
    }

    #[Test]
    public function anIncompleteOptionAllocationDisablesCheckout(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $client = self::getContainer()->get('test.client');
        $this->seedCart($client, (string) $player->id, Cart::fromKeys(['monument']));

        $crawler = $this->createCart($player, client: $client)->render()->crawler();

        $this->assertTrue($crawler->filter('[data-live-action-param="checkout"]')->getNode(0)->hasAttribute('disabled'));
    }

    /**
     * The cross-component re-render is not observable in a single-component harness (the browser
     * check covers it); the emitUp('orderPlaced') it relies on is.
     */
    #[Test]
    public function checkoutWithACompleteCartCreatesTheOrderAndEmitsOrderPlaced(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $client = self::getContainer()->get('test.client');
        $this->seedCart($client, (string) $player->id, Cart::fromKeys(['pottery']));

        $component = $this->createCart($player, client: $client);
        $component->call('checkout');

        $order = self::getContainer()->get(OrderRepository::class)
            ->findOneByPlayerAndWindow($player, $player->game->currentTurn);
        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame(['pottery'], $order->keys());

        $this->assertComponentEmitEvent($component, 'orderPlaced');
    }

    #[Test]
    public function checkoutWithAnIncompleteAllocationIsBlockedAndShowsTheGuardMessage(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $client = self::getContainer()->get('test.client');
        $cart = Cart::fromKeys(['monument']);
        $cart->withAllocation('monument', 'science', 5);
        $this->seedCart($client, (string) $player->id, $cart);

        $component = $this->createCart($player, client: $client);
        $rendered = $component->call('checkout')->render()->toString();

        $this->assertNull(self::getContainer()->get(OrderRepository::class)
            ->findOneByPlayerAndWindow($player, $player->game->currentTurn));
        $this->assertStringContainsString('Finish allocating the bonus for', $rendered);
        $this->assertStringContainsString('monument', $rendered);
    }

    private function createCart(Player $player, ?string $storageKey = null, ?KernelBrowser $client = null): TestLiveComponent
    {
        return $this->createLiveComponent('Cart', [
            'player' => $player,
            'storageKey' => $storageKey ?? CartKey::shop($player),
        ], $client);
    }

    /** The catalog repeats advance names, so assertions must be scoped to the cart's lines. */
    private function extractCartLinesSection(string $html): string
    {
        $start = strpos($html, '<ul class="lines">');
        $this->assertNotFalse($start, 'lines not found in rendered output.');

        $end = strpos($html, '</ul>', $start);
        $this->assertNotFalse($end, 'Closing </ul> for lines not found in rendered output.');

        return substr($html, $start, $end - $start);
    }
}
