<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GameSession;
use App\Entity\Player;
use App\Game\Shop\AdvanceFulfillment;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class PlayerBoardTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function getPlayerBoardReturnsTwoHundredWithLiveAndMercureWiring(): void
    {
        $player = $this->createPlayer();

        $client = self::getClient(self::getContainer()->get('test.client'));
        $client->request('GET', '/'.$player->game->slug.'/player/'.$player->slug);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-controller~="live"]');

        $html = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('empires/game/'.$player->game->id, $html);
    }

    #[Test]
    public function unknownPlayerSlugReturns404(): void
    {
        $player = $this->createPlayer();

        $client = self::getClient(self::getContainer()->get('test.client'));
        $client->request('GET', '/'.$player->game->slug.'/player/does-not-exist');

        $this->assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function allFiveStatPickerTriggerButtonsAreRendered(): void
    {
        $player = $this->createPlayer();

        $rendered = $this->createLiveComponent('PlayerBoard', ['player' => $player])->render();

        $labels = array_map(
            static fn (string $text): string => trim(preg_replace('/\s+/', ' ', $text) ?? ''),
            $rendered->crawler()->filter('button[command="show-modal"]')->each(
                static fn ($node) => $node->text(),
            ),
        );

        $this->assertSame(['Cities 0', 'Ships 0', 'Population 1', 'Treasury 0', 'Cards 0'], $labels);
    }

    #[Test]
    public function ownedAdvancesAreRenderedWithoutAPurchaseButton(): void
    {
        $player = $this->createPlayer();
        $player->ownAdvances(['pottery']);
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('PlayerBoard', ['player' => $player])->render()->toString();

        $this->assertStringContainsString('id="product-pottery"', $rendered);
        $this->assertStringContainsString('Pottery', $rendered);
        $this->assertStringNotContainsString('<button', $this->extractProductCard($rendered, 'pottery'));
    }

    #[Test]
    public function discountsAreRenderedForAPlayerOwningAgriculture(): void
    {
        $player = $this->createPlayer();
        self::getContainer()->get(AdvanceFulfillment::class)->grant($player->id, ['agriculture']);
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('PlayerBoard', ['player' => $player])->render()->toString();

        $this->assertStringContainsString('Craft', $rendered);
        $this->assertMatchesRegularExpression('/Craft<\/td>\s*<td><b>10</', $rendered);
        $this->assertMatchesRegularExpression('/Science<\/td>\s*<td><b>5</', $rendered);
        $this->assertMatchesRegularExpression('/Democracy<\/td>\s*<td><b>20</', $rendered);
    }

    #[Test]
    public function discountCategoryRowsCarryTheOfficialCategoryColor(): void
    {
        $player = $this->createPlayer();
        $player->ownAdvances(['agriculture']);
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('PlayerBoard', ['player' => $player])->render()->toString();

        // Agriculture provides credits for craft and science categories
        $this->assertStringContainsString('data-advance-category="craft"', $rendered);
        $this->assertStringContainsString('data-advance-category="science"', $rendered);
    }

    #[Test]
    public function mercureRefreshFiltersToPlayerUpdated(): void
    {
        $player = $this->createPlayer();

        $rendered = $this->createLiveComponent('PlayerBoard', ['player' => $player])->render()->toString();

        $this->assertStringContainsString('data-mercure-refresh-events-value', $rendered);
        $this->assertStringContainsString('player-updated', $rendered);
        $this->assertStringNotContainsString('order-updated', $rendered);
    }

    #[Test]
    public function bodyBackgroundIsColoredByThePlayersEmpire(): void
    {
        $withEmpire = $this->createPlayer();
        $withEmpire->empire = 'minoa';
        $this->entityManager->flush();

        $client = self::getClient(self::getContainer()->get('test.client'));

        $client->request('GET', '/'.$withEmpire->game->slug.'/player/'.$withEmpire->slug);
        $boardContent = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('data-empire="minoa"', $boardContent);
        $this->assertStringContainsString('--empire-minoa: #a4ce53', $boardContent);
        $this->assertMatchesRegularExpression('/<body[^>]*data-empire="minoa"/', $boardContent);

        $client->request('GET', '/'.$withEmpire->game->slug.'/player/'.$withEmpire->slug.'/shop');
        $this->assertStringContainsString('data-empire="minoa"', (string) $client->getResponse()->getContent());
    }

    private function createPlayer(string $name = 'Alice'): Player
    {
        $game = new GameSession();
        $player = new Player($game, $name);

        $this->entityManager->persist($game);
        $this->entityManager->persist($player);
        $this->entityManager->flush();

        return $player;
    }

    /**
     * Scopes assertions to a single product's <article>, as opposed to the
     * whole board (which renders other, unrelated <button> elements).
     */
    private function extractProductCard(string $html, string $key): string
    {
        $idPosition = strpos($html, 'id="product-'.$key.'"');
        $this->assertNotFalse($idPosition, "id=\"product-{$key}\" not found in rendered output.");

        $start = strrpos(substr($html, 0, $idPosition), '<article');
        $this->assertNotFalse($start, "<article> for product '{$key}' not found in rendered output.");

        $end = strpos($html, '</article>', $start);
        $this->assertNotFalse($end, '</article> not found in rendered output.');

        return substr($html, $start, $end - $start);
    }
}
