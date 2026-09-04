<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Engine\Shop\AdvanceFulfillment;
use App\State\Player;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/** The three states are indistinguishable in the markup: read data-discount-state, never the text. */
final class DiscountsTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithTwigComponents;

    private const array EVERY_ADVANCE_CARRYING_THE_RELIGION_CATEGORY = [
        'deism', 'diaspora', 'enlightenment', 'fundamentalism', 'monument',
        'monotheism', 'mysticism', 'mythology', 'philosophy', 'theocracy',
        'theology', 'universal_doctrine',
    ];

    #[Test]
    public function aCreditIsEmptyUntilEarnedLiveWhileItStandsAndSpentOnceItsWholeCategoryIsOwned(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $emptyHanded = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);
        $collector = PlayerBuilder::named('Alice')->in($game)->withEmpire('hellas')->persist($this->entityManager);
        self::getContainer()->get(AdvanceFulfillment::class)->grant(
            $collector->id,
            [...self::EVERY_ADVANCE_CARRYING_THE_RELIGION_CATEGORY, 'agriculture'],
            $game->currentTurn,
        );
        $this->entityManager->flush();

        $earned = $this->statesByCategory($collector);

        $this->assertSame(['empty'], array_values(array_unique($this->statesByCategory($emptyHanded))));
        $this->assertSame('spent', $earned['religion']);
        $this->assertSame('live', $earned['craft']);
    }

    /** @return array<string, string> */
    private function statesByCategory(Player $player): array
    {
        $rows = $this->renderTwigComponent('molecules:Discounts', ['player' => $player])
            ->crawler()
            ->filter('tr[data-advance-category]')
        ;

        return array_combine(
            $rows->each(static fn (Crawler $row): string => (string) $row->attr('data-advance-category')),
            $rows->each(static fn (Crawler $row): string => (string) $row->attr('data-discount-state')),
        );
    }
}
