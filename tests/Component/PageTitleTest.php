<?php

declare(strict_types=1);

namespace App\Tests\Component;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class PageTitleTest extends WebTestCase
{
    use InteractsWithTwigComponents;

    #[Test]
    public function theHeadingAndItsQualifierReadAsOneTitledGroup(): void
    {
        $crawler = $this->renderPageTitle(['heading' => 'Empires', 'qualifier' => 'Games in progress']);

        $this->assertSame('Empires', trim($crawler->filter('#page-title hgroup h1')->text()));
        $this->assertSame('Games in progress', trim($crawler->filter('#page-title hgroup p')->text()));
    }

    #[Test]
    public function anAbsentQualifierLeavesNoParagraphBehind(): void
    {
        $crawler = $this->renderPageTitle(['heading' => 'Create a game']);

        $this->assertSame('Create a game', trim($crawler->filter('#page-title h1')->text()));
        $this->assertCount(0, $crawler->filter('#page-title p'));
    }

    /** Without it, a screen served the wrong variation still satisfies every heading assertion made elsewhere. */
    #[Test]
    public function thePlainVariationIsABareGroupCarryingNoCommand(): void
    {
        $crawler = $this->renderPageTitle(['heading' => 'Empires', 'qualifier' => 'Games in progress']);

        $this->assertCount(1, $crawler->filter('header#page-title[data-title="page"] > hgroup'));
        $this->assertCount(0, $crawler->filter('#page-title button'));
        $this->assertCount(0, $crawler->filter('#page-title a'));
    }

    #[Test]
    public function theCelebratedVariationIsMarkedAndStaysBare(): void
    {
        $crawler = $this->renderPageTitle([
            'variant' => 'celebration',
            'heading' => 'west-finale',
            'qualifier' => 'Expert version — Turn 20 — Finished',
        ]);

        $this->assertCount(1, $crawler->filter('header#page-title[data-title="celebration"] > hgroup'));
        $this->assertCount(0, $crawler->filter('#page-title button'));
    }

    #[Test]
    public function thePlayerVariationCarriesATriggerForTheDialogItNames(): void
    {
        $crawler = $this->renderPageTitle([
            'variant' => 'player',
            'heading' => 'Alice',
            'qualifier' => 'Minoa · Turn 4',
            'renameFor' => 'rename-player-42',
        ]);

        $this->assertCount(1, $crawler->filter('header#page-title[data-title="player"] > hgroup'));
        $this->assertCount(1, $crawler->filter('#page-title button[type="button"][command="show-modal"][commandfor="rename-player-42"]'));
        $this->assertCount(0, $crawler->filter('#page-title a'));
    }

    #[Test]
    public function theDrilldownVariationLeadsWithItsWayBack(): void
    {
        $crawler = $this->renderPageTitle([
            'variant' => 'drilldown',
            'heading' => 'Freelooser',
            'qualifier' => 'Turn 9',
            'back' => 'Orders',
            'backHref' => '/freelooser/operator',
        ]);

        $this->assertSame('Orders', trim($crawler->filter('#page-title > a')->text()));
        $this->assertSame('/freelooser/operator', $crawler->filter('#page-title > a')->attr('href'));
        $this->assertSame('a', $crawler->filter('header#page-title[data-title="drilldown"] > *')->first()->nodeName());
        $this->assertCount(0, $crawler->filter('#page-title button'));
    }

    /** @param array<string, string> $props */
    private function renderPageTitle(array $props): Crawler
    {
        return $this->renderTwigComponent('atoms:PageTitle', $props)->crawler();
    }
}
