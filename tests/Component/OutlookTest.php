<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Entity\Player;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Canary for the Outlook molecule: proves the advisories the container's rule set produces reach
 * the markup, one <li> each, carrying their urgency level.
 *
 * The wording of every individual line is pinned by the rule that owns it, in
 * tests/Game/Advisory/ — asserting those sentences again here only doubled the cost of rewording
 * one. What is genuinely observable at this level, and nowhere else, is that the full registered
 * rule set (not the hand-wired subset PlayerAdvisorTest uses) renders in urgency order.
 */
final class OutlookTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithTwigComponents;

    /** Sorted by urgency: what is broken first, then what threatens, then facts, then good news. */
    #[Test]
    public function linesAreSortedByUrgency(): void
    {
        $player = PlayerBuilder::named('Bob')->persist($this->entityManager);
        $player->cities = 8;
        $player->census = 30;
        $player->treasury = 15;
        $this->entityManager->flush();

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
