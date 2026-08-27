<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
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
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);

        foreach (['operator', $player->slug] as $key) {
            $this->client->request(Request::METHOD_GET, '/'.$game->slug.'/qr/'.$key);

            $this->assertResponseIsSuccessful($key);
            $this->assertResponseHeaderSame('Content-Type', 'image/svg+xml');
            $this->assertStringContainsString('<svg', (string) $this->client->getResponse()->getContent());
        }
    }

    #[Test]
    public function aKeyNamingNoTargetIsNotFound(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        $this->client->request(Request::METHOD_GET, '/'.$game->slug.'/qr/nobody');

        $this->assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function theDashboardCarriesTheAddressesRatherThanTheCodes(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);

        $crawler = $this->client->request(Request::METHOD_GET, '/'.$game->slug);

        $images = $crawler->filter('nav.navigation dialog img');

        $this->assertCount(2, $images, 'One code per target: the operator console and Alice.');
        $this->assertSame(
            ['/'.$game->slug.'/qr/operator', '/'.$game->slug.'/qr/'.$player->slug],
            $images->each(static fn ($image): ?string => $image->attr('src')),
        );
        $this->assertCount(
            0,
            $crawler->filter('nav.navigation dialog svg'),
            'The codes are fetched, never inlined — the small trigger icons outside the dialog stay.',
        );
    }
}
