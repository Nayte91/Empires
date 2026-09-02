<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\State\Player;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * The wording of each line belongs to the rule that owns it; restating those sentences here doubles
 * the cost of rewording one.
 */
final class OutlookTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithTwigComponents;

    #[Test]
    public function linesAreSortedByUrgency(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(8)->withCensus(30)->withTreasury(15)->persist($this->entityManager);

        $this->assertSame(['danger', 'caution', 'neutral', 'neutral', 'good', 'good'], $this->levelsOf($player));
    }

    /** @return list<string> */
    private function levelsOf(Player $player): array
    {
        return $this->render($player)->filter('li')->each(
            static fn (Crawler $node): string => (string) $node->attr('data-level'),
        );
    }

    private function render(Player $player): Crawler
    {
        return $this->renderTwigComponent('molecules:Outlook', ['player' => $player])->crawler();
    }
}
