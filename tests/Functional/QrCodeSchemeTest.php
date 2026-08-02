<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\State\Game;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Behind Traefik the app is reached over HTTPS but served plain HTTP, so the request's own scheme
 * reads http. The navigation panel hands out absolute URLs — as links and as the QR codes players
 * scan — and generated from that scheme they point at port 80, which production does not listen
 * on: the scan fails to connect outright, unless the reader happens to upgrade on its own.
 */
final class QrCodeSchemeTest extends WebTestCase
{
    #[Test]
    public function absoluteUrlsFollowTheForwardedProtocol(): void
    {
        $client = self::createClient();
        $game = $this->persistGame();

        $client->request(Request::METHOD_GET, '/'.$game->slug, server: ['HTTP_X_FORWARDED_PROTO' => 'https']);

        $content = (string) $client->getResponse()->getContent();

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('https://localhost/'.$game->slug.'/operator', $content);
        $this->assertStringNotContainsString('http://localhost/'.$game->slug, $content);
    }

    /**
     * The other half of the guard: served directly, as in local dev on port 8020, the URLs must
     * stay http. A fix that hardcoded the scheme would pass the test above and fail this one.
     */
    #[Test]
    public function absoluteUrlsStayHttpWhenNoProtocolIsForwarded(): void
    {
        $client = self::createClient();
        $game = $this->persistGame();

        $client->request(Request::METHOD_GET, '/'.$game->slug);

        $content = (string) $client->getResponse()->getContent();

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('http://localhost/'.$game->slug.'/operator', $content);
        $this->assertStringNotContainsString('https://localhost/'.$game->slug, $content);
    }

    private function persistGame(): Game
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $game = new Game();
        $entityManager->persist($game);
        $entityManager->flush();

        return $game;
    }
}
