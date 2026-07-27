<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Game\Shop\AdvanceFulfillment;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class PlayerBoardTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithLiveComponents;

    #[Test]
    public function getPlayerBoardReturnsTwoHundredWithLiveAndMercureWiring(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

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
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $client = self::getClient(self::getContainer()->get('test.client'));
        $client->request('GET', '/'.$player->game->slug.'/player/does-not-exist');

        $this->assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function allFiveStatPickerTriggerButtonsAreRendered(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

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
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
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
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
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
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
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
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $rendered = $this->createLiveComponent('PlayerBoard', ['player' => $player])->render()->toString();

        $this->assertStringContainsString('data-mercure-refresh-events-value', $rendered);
        $this->assertStringContainsString('player-updated', $rendered);
        $this->assertStringNotContainsString('order-updated', $rendered);
    }

    #[Test]
    public function bodyBackgroundIsColoredByThePlayersEmpire(): void
    {
        $withEmpire = PlayerBuilder::named('Alice')->persist($this->entityManager);
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
