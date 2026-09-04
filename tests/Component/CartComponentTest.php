<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Infrastructure\Repository\OrderRepository;
use App\Presentation\Component\Cart as CartComponent;
use App\Presentation\Shop\CartKey;
use App\Rules\Ruleset\Advance;
use App\State\Order;
use App\State\Player;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use App\Tests\Support\ShopFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;
use Userforged\ShopEngine\Cart;
use Userforged\ShopEngine\CartStorageInterface;

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
        $client = $this->browser();
        $this->seedCart($client, CartKey::shop($player), Cart::fromKeys(['pottery', 'democracy']));

        $this->createCart($player, $client)->call('remove', ['key' => 'pottery']);

        $this->assertSame(['democracy'], $this->cartKeysOf($client, $player));
    }

    #[Test]
    public function anOwnedFacetCreditIsSpentOnTheCartTotal(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $client = $this->browser();
        $this->seedCart($client, CartKey::shop($player), Cart::fromKeys(['library', 'democracy']));

        $cart = $this->createCart($player, $client);

        $this->assertSame(400, $this->reading($client, $cart, static fn (CartComponent $mounted): int => $mounted->getTotal()));
    }

    #[Test]
    public function addingAnatomyShowsTheGiftPickerForAnEligibleScienceCandidate(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $client = $this->browser();
        $this->seedCart($client, CartKey::shop($player), Cart::fromKeys(['anatomy']));

        $cart = $this->createCart($player, $client);
        $candidates = $this->reading($client, $cart, static fn (CartComponent $mounted): array => $mounted->getGiftCandidates('anatomy'));

        $this->assertContains('astronavigation', array_map(static fn (Advance $advance): string => $advance->key, $candidates));
    }

    #[Test]
    public function addingMonumentOffersItsFullPoolStillToAllocate(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $client = $this->browser();
        $this->seedCart($client, CartKey::shop($player), Cart::fromKeys(['monument']));

        $cart = $this->createCart($player, $client);

        $this->assertSame(20, $this->reading($client, $cart, static fn (CartComponent $mounted): int => $mounted->getAllocationRemaining('monument')));
    }

    #[Test]
    public function anIncompleteOptionAllocationDisablesCheckout(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $client = $this->browser();
        $this->seedCart($client, CartKey::shop($player), Cart::fromKeys(['monument']));

        $crawler = $this->createCart($player, $client)->render()->crawler();

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
        $client = $this->browser();
        $this->seedCart($client, CartKey::shop($player), Cart::fromKeys(['pottery']));

        $component = $this->createCart($player, $client);
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
        $client = $this->browser();
        $cart = Cart::fromKeys(['monument']);
        $cart->withAllocation('monument', 'science', 5);
        $this->seedCart($client, CartKey::shop($player), $cart);

        $rendered = $this->createCart($player, $client)->call('checkout')->render()->toString();

        $this->assertNull(self::getContainer()->get(OrderRepository::class)
            ->findOneByPlayerAndWindow($player, $player->game->currentTurn));
        $this->assertStringContainsString('Finish allocating the bonus for', $rendered);
    }

    private function createCart(Player $player, KernelBrowser $client): TestLiveComponent
    {
        return $this->createLiveComponent('Cart', [
            'player' => $player,
            'storageKey' => CartKey::shop($player),
        ], $client);
    }

    /**
     * @template TRead
     *
     * @param callable(CartComponent): TRead $read
     *
     * @return TRead
     */
    private function reading(KernelBrowser $client, TestLiveComponent $component, callable $read): mixed
    {
        $mounted = $component->component();
        $this->assertInstanceOf(CartComponent::class, $mounted);

        return $this->reopening($client, static fn (): mixed => $read($mounted));
    }

    /** @return list<string> */
    private function cartKeysOf(KernelBrowser $client, Player $player): array
    {
        return $this->reopening($client, fn (): array => self::getContainer()
            ->get(CartStorageInterface::class)
            ->load(CartKey::shop($player))
            ->keys());
    }
}
