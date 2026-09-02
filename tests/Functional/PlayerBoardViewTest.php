<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\State\Player;
use App\Tests\Support\Fixture\Tables;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

final class PlayerBoardViewTest extends WebTestCase
{
    private KernelBrowser $client; // @phpstan-ignore property.uninitialized (initialized in setUp)
    private EntityManagerInterface $entityManager; // @phpstan-ignore property.uninitialized (initialized in setUp)

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function getPlayerBoardReturnsTwoHundredWithLiveAndMercureWiring(): void
    {
        $player = Tables::seat(Tables::westTable($this->entityManager), 'Alice');

        $this->visit($player);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-controller~="live"]');

        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('empires/game/'.$player->game->id, $html);
    }

    #[Test]
    public function unknownPlayerSlugReturns404(): void
    {
        $game = Tables::westTable($this->entityManager);

        $this->client->request(Request::METHOD_GET, '/'.$game->slug.'/player/does-not-exist');

        $this->assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function bodyBackgroundIsColoredByThePlayersEmpire(): void
    {
        $withEmpire = Tables::seat(Tables::westTable($this->entityManager), 'Alice');

        $this->visit($withEmpire);
        $boardContent = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('data-empire="minoa"', $boardContent);
        $this->assertStringContainsString('--empire-minoa: #a4ce53', $boardContent);
        $this->assertMatchesRegularExpression('/<body[^>]*data-empire="minoa"/', $boardContent);

        $this->client->request(Request::METHOD_GET, $this->pathOf($withEmpire).'/shop');
        $this->assertStringContainsString('data-empire="minoa"', (string) $this->client->getResponse()->getContent());
    }

    private function visit(Player $player): Crawler
    {
        return $this->client->request(Request::METHOD_GET, $this->pathOf($player));
    }

    private function pathOf(Player $player): string
    {
        return '/'.$player->game->slug.'/player/'.$player->slug;
    }
}
