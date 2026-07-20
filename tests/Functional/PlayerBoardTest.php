<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GameSession;
use App\Entity\Player;
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

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller~="live"]');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('empires/game/'.$player->game->id, $html);
    }

    #[Test]
    public function unknownPlayerSlugReturns404(): void
    {
        $player = $this->createPlayer();

        $client = self::getClient(self::getContainer()->get('test.client'));
        $client->request('GET', '/'.$player->game->slug.'/player/does-not-exist');

        self::assertResponseStatusCodeSame(404);
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

        self::assertSame(
            ['Cities 0', 'Ships 0', 'Census 1', 'Treasury 0', 'Cards 0'],
            $labels,
        );
    }

    #[Test]
    public function ownedAdvancesAreRenderedWithoutAPurchaseButton(): void
    {
        $player = $this->createPlayer();
        $player->ownAdvances(['pottery']);
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('PlayerBoard', ['player' => $player])->render()->toString();

        self::assertStringContainsString('id="product-pottery"', $rendered);
        self::assertStringContainsString('Pottery', $rendered);
        self::assertStringNotContainsString('<button', $this->extractProductCard($rendered, 'pottery'));
    }

    #[Test]
    public function discountsAreRenderedForAPlayerOwningAgriculture(): void
    {
        $player = $this->createPlayer();
        $player->ownAdvances(['agriculture']);
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('PlayerBoard', ['player' => $player])->render()->toString();

        self::assertStringContainsString('Craft', $rendered);
        self::assertMatchesRegularExpression('/Craft<\/td>\s*<td><b>10</', $rendered);
        self::assertMatchesRegularExpression('/Science<\/td>\s*<td><b>5</', $rendered);
        self::assertMatchesRegularExpression('/Democracy<\/td>\s*<td><b>20</', $rendered);
    }

    #[Test]
    public function discountCategoryRowsCarryTheOfficialCategoryColor(): void
    {
        $player = $this->createPlayer();
        $player->ownAdvances(['advanced_military']);
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('PlayerBoard', ['player' => $player])->render()->toString();

        self::assertStringContainsString('--category-color: #F04E56', $rendered);
        self::assertStringContainsString('--category-color: #39B54A', $rendered);
    }

    #[Test]
    public function mercureRefreshFiltersToPlayerUpdated(): void
    {
        $player = $this->createPlayer();

        $rendered = $this->createLiveComponent('PlayerBoard', ['player' => $player])->render()->toString();

        self::assertStringContainsString('data-mercure-refresh-events-value', $rendered);
        self::assertStringContainsString('player-updated', $rendered);
        self::assertStringNotContainsString('order-updated', $rendered);
    }

    #[Test]
    public function bodyBackgroundIsColoredForAPlayerWithAnEmpireAndNotOtherwise(): void
    {
        $withEmpire = $this->createPlayer();
        $withEmpire->empire = 'minoa';
        $withoutEmpire = $this->createPlayer('Bob');
        $this->entityManager->flush();

        $client = self::getClient(self::getContainer()->get('test.client'));

        $client->request('GET', '/'.$withEmpire->game->slug.'/player/'.$withEmpire->slug);
        $boardContent = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('background-color: var(--empire-minoa, dimgray)', $boardContent);
        self::assertStringContainsString('--empire-minoa: #a4ce53', $boardContent);

        $client->request('GET', '/'.$withEmpire->game->slug.'/player/'.$withEmpire->slug.'/shop');
        self::assertStringContainsString('background-color: var(--empire-minoa, dimgray)', (string) $client->getResponse()->getContent());

        $client->request('GET', '/'.$withoutEmpire->game->slug.'/player/'.$withoutEmpire->slug);
        self::assertStringNotContainsString('background-color', (string) $client->getResponse()->getContent());
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
        self::assertNotFalse($idPosition, "id=\"product-{$key}\" not found in rendered output.");

        $start = strrpos(substr($html, 0, $idPosition), '<article');
        self::assertNotFalse($start, "<article> for product '{$key}' not found in rendered output.");

        $end = strpos($html, '</article>', $start);
        self::assertNotFalse($end, '</article> not found in rendered output.');

        return substr($html, $start, $end - $start);
    }
}
