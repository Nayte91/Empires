<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\State\Game;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use App\Tests\Support\Fixture\Tables;

/**
 * Behind Traefik the app is reached over HTTPS but served plain HTTP, so absolute URLs generated
 * from the request scheme point at port 80 and the QR scan fails to connect.
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
        $this->assertStringContainsString('https://localhost/'.$game->slug.'/operator/board', $content);
        $this->assertStringNotContainsString('http://localhost/'.$game->slug, $content);
    }

    /** A fix that hardcoded the scheme would pass the test above and fail this one. */
    #[Test]
    public function absoluteUrlsStayHttpWhenNoProtocolIsForwarded(): void
    {
        $client = self::createClient();
        $game = $this->persistGame();

        $client->request(Request::METHOD_GET, '/'.$game->slug);

        $content = (string) $client->getResponse()->getContent();

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('http://localhost/'.$game->slug.'/operator/board', $content);
        $this->assertStringNotContainsString('https://localhost/'.$game->slug, $content);
    }

    private function persistGame(): Game
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return Tables::westTable($entityManager);
    }
}
