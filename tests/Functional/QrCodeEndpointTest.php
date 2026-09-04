<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\Fixture\Tables;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;

final class QrCodeEndpointTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function eachTargetHasItsOwnFetchableCode(): void
    {
        $game = Tables::westTable($this->entityManager);
        $player = Tables::seat($game, 'Alice');

        foreach (['operator', $player->slug] as $key) {
            $this->client->request(Request::METHOD_GET, '/game/'.$game->slug.'/qr/'.$key);

            $this->assertResponseIsSuccessful($key);
            $this->assertResponseHeaderSame('Content-Type', 'image/svg+xml');
            $this->assertStringContainsString('<svg', (string) $this->client->getResponse()->getContent());
        }
    }

    #[Test]
    public function aKeyNamingNoTargetIsNotFound(): void
    {
        $game = Tables::westTable($this->entityManager);

        $this->client->request(Request::METHOD_GET, '/game/'.$game->slug.'/qr/nobody');

        $this->assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function theDashboardCarriesTheAddressesRatherThanTheCodes(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);

        $crawler = $this->client->request(Request::METHOD_GET, '/game/'.$game->slug);

        $images = $crawler->filter('nav dialog img');

        $this->assertSame(
            ['/game/'.$game->slug.'/qr/operator', '/game/'.$game->slug.'/qr/'.$player->slug],
            $images->each(static fn ($image): ?string => $image->attr('src')),
        );
    }
}
