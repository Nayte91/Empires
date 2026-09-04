<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\State\Player;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Userforged\ShopEngine\Cart;
use Userforged\ShopEngine\CartStorageInterface;
use Userforged\ShopEngine\Dto\LineIntent;

trait ShopFixtureTrait
{
    /**
     * @param list<string> $keys
     *
     * @return list<LineIntent>
     */
    private function intents(array $keys): array
    {
        return array_map(static fn (string $key): LineIntent => new LineIntent($key), $keys);
    }

    /**
     * 'test.client' is registered share(false), so $client must be the exact instance later handed
     * to the component factory. Callers spell the key themselves, because that spelling is
     * production's.
     */
    private function seedCart(KernelBrowser $client, string $storageKey, Cart $cart): void
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        $request = new Request();
        $request->setSession($session);
        $requestStack = self::getContainer()->get(RequestStack::class);
        $requestStack->push($request);
        self::getContainer()->get(CartStorageInterface::class)->save($storageKey, $cart);
        $requestStack->pop();
        $session->save();

        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));
    }

    /**
     * A component handed back by component() keeps the services of the container that built it, and
     * the browser rebuilds that container on every request unless the reboot is disabled: without
     * this, a session-backed read made from the test body reaches a request stack nobody filled.
     */
    private function browser(): KernelBrowser
    {
        $client = self::getContainer()->get('test.client');
        $client->disableReboot();

        return $client;
    }

    /**
     * The cart lives in the browser's session and the request stack is empty once a Live request is
     * over, so a session-backed read made from the test body has to put that request back first.
     *
     * @template TRead
     *
     * @param callable(): TRead $read
     *
     * @return TRead
     */
    private function reopening(KernelBrowser $client, callable $read): mixed
    {
        $requestStack = self::getContainer()->get(RequestStack::class);
        $requestStack->push($client->getRequest());

        try {
            return $read();
        } finally {
            $requestStack->pop();
        }
    }

    /**
     * Clears the identity map first: without it Doctrine hands back the in-memory instance.
     */
    private function reloadPlayer(Player $player): Player
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $reloaded = $entityManager->find(Player::class, $player->id);
        $this->assertInstanceOf(Player::class, $reloaded);

        return $reloaded;
    }
}
