<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\State\Game;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use App\Tests\Support\Fixture\Tables;

final class QrCodeSchemeTest extends WebTestCase
{
    #[Test]
    public function absoluteUrlsFollowTheForwardedProtocol(): void
    {
        $client = self::createClient();
        $game = $this->persistGame();

        $client->request(Request::METHOD_GET, '/game/'.$game->slug, server: ['HTTP_X_FORWARDED_PROTO' => 'https']);

        $content = (string) $client->getResponse()->getContent();

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('https://localhost/game/'.$game->slug.'/operator/board', $content);
        $this->assertStringNotContainsString('http://localhost/game/'.$game->slug, $content);
    }

    #[Test]
    public function absoluteUrlsStayHttpWhenNoProtocolIsForwarded(): void
    {
        $client = self::createClient();
        $game = $this->persistGame();

        $client->request(Request::METHOD_GET, '/game/'.$game->slug);

        $content = (string) $client->getResponse()->getContent();

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('http://localhost/game/'.$game->slug.'/operator/board', $content);
        $this->assertStringNotContainsString('https://localhost/game/'.$game->slug, $content);
    }

    private function persistGame(): Game
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return Tables::westTable($entityManager);
    }
}
