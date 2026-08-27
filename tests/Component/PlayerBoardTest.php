<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Engine\Shop\AdvanceFulfillment;
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

    /**
     * Scoped to the pickers' own dialog ids rather than to every show-modal trigger on the board:
     * the board hosts unrelated dialogs (rename), and a census of all of them turns this red for
     * reasons that have nothing to do with stat pickers. The exact labels and values, in order,
     * are still what is asserted, so a missing or mis-wired picker cannot slip through.
     */
    #[Test]
    public function allFiveStatPickerTriggerButtonsAreRendered(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $rendered = $this->createLiveComponent('PlayerBoard', ['player' => $player])->render();

        $labels = array_map(
            static fn (string $text): string => trim(preg_replace('/\s+/', ' ', $text) ?? ''),
            $rendered->crawler()->filter('button[command="show-modal"][commandfor^="stat-picker-"]')->each(
                static fn ($node) => $node->text(),
            ),
        );

        $this->assertSame(['Cities 0', 'Ships 0', 'Population 1', 'Treasury 0', 'Cards 0'], $labels);
    }

    /**
     * The heading quotes the advances term of the score, so it can never drift from what the score
     * itself counts. Zero is printed rather than hidden: a heading that keeps its shape is easier
     * to read turn over turn, and a zero in a named source is itself information.
     */
    #[Test]
    public function theAdvancesHeadingCarriesWhatThoseAdvancesAreWorth(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $player->ownAdvances(['pottery', 'agriculture']);
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('PlayerBoard', ['player' => $player])->render()->crawler();

        $this->assertSame('Advances (4 Victory Points)', trim($rendered->filter('section[aria-label="Owned advances"] h2')->text()));
    }

    #[Test]
    public function theAdvancesHeadingSaysOneVictoryPointInTheSingular(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $player->ownAdvances(['pottery']);
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('PlayerBoard', ['player' => $player])->render()->crawler();

        $this->assertSame('Advances (1 Victory Point)', trim($rendered->filter('section[aria-label="Owned advances"] h2')->text()));
    }

    #[Test]
    public function theAdvancesHeadingStillCountsZeroForAPlayerOwningNothing(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $rendered = $this->createLiveComponent('PlayerBoard', ['player' => $player])->render()->crawler();

        $this->assertSame('Advances (0 Victory Points)', trim($rendered->filter('section[aria-label="Owned advances"] h2')->text()));
    }

    #[Test]
    public function ownedAdvancesAreRenderedWithoutAPurchaseButton(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $player->ownAdvances(['pottery']);
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('PlayerBoard', ['player' => $player])->render()->toString();

        $this->assertStringContainsString('id="product-pottery"', $rendered);
        $this->assertStringContainsString('alt="pottery card"', $rendered);
        $this->assertStringNotContainsString('<button', $this->extractOwnedAdvancesSection($rendered));
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
    public function theBoardListensOnItsOwnPlayersTopicAndNoOther(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $rendered = $this->createLiveComponent('PlayerBoard', ['player' => $player])->render()->crawler();

        $this->assertSame(
            'empires/game/'.$player->game->id.'/player/'.$player->id,
            $rendered->filter('[data-mercure-refresh-topic-value]')->attr('data-mercure-refresh-topic-value'),
        );
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
     * Scopes assertions to the owned-advances section, as opposed to the
     * whole board (which renders other, unrelated <button> elements).
     */
    private function extractOwnedAdvancesSection(string $html): string
    {
        $start = strpos($html, '<section aria-label="Owned advances">');
        $this->assertNotFalse($start, 'Owned advances section not found in rendered output.');

        $end = strpos($html, '</section>', $start);
        $this->assertNotFalse($end, 'Owned advances section not closed in rendered output.');

        return substr($html, $start, $end - $start);
    }
}
