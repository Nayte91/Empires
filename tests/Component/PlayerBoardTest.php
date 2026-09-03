<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Engine\Shop\AdvanceFulfillment;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class PlayerBoardTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithLiveComponents;

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

    /** @param list<string> $advances */
    #[Test]
    #[DataProvider('provideTheAdvancesHeadingCarriesWhatThoseAdvancesAreWorthCases')]
    public function theAdvancesHeadingCarriesWhatThoseAdvancesAreWorth(array $advances, string $expectedHeading): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $player->ownAdvances($advances);
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('PlayerBoard', ['player' => $player])->render()->crawler();

        $this->assertSame($expectedHeading, trim($rendered->filter('section[aria-label="Owned advances"] h2')->text()));
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function provideTheAdvancesHeadingCarriesWhatThoseAdvancesAreWorthCases(): iterable
    {
        yield 'two advances worth four points between them' => [['pottery', 'agriculture'], 'Advances (4 Victory Points)'];

        yield 'a single point, spelled in the singular' => [['pottery'], 'Advances (1 Victory Point)'];

        yield 'a player owning nothing still counts to zero' => [[], 'Advances (0 Victory Points)'];
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
        self::getContainer()->get(AdvanceFulfillment::class)->grant($player->id, ['agriculture'], $player->game->currentTurn);
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

    /** The board renders unrelated buttons, so assertions must be scoped to the owned-advances section. */
    private function extractOwnedAdvancesSection(string $html): string
    {
        $start = strpos($html, '<section aria-label="Owned advances">');
        $this->assertNotFalse($start, 'Owned advances section not found in rendered output.');

        $end = strpos($html, '</section>', $start);
        $this->assertNotFalse($end, 'Owned advances section not closed in rendered output.');

        return substr($html, $start, $end - $start);
    }
}
