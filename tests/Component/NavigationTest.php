<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\State\Game;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class NavigationTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithTwigComponents;

    /** It sits above the roster, so it must cost one line until someone asks for it. */
    #[Test]
    public function thePanelIsCollapsedUntilItIsOpened(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        $crawler = $this->render($game);

        $this->assertCount(1, $crawler->filter('details'));
        $this->assertNull($crawler->filter('details')->attr('open'));
    }

    #[Test]
    public function theOperatorConsoleLeadsTheList(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);

        $crawler = $this->render($game);

        $this->assertStringEndsWith('/'.$game->slug.'/operator', (string) $crawler->filter('li a')->eq(0)->attr('href'));
    }

    /** The board is the player's home; the kiosk is reached from there, not from the dashboard. */
    #[Test]
    public function aPlayerRowLinksToTheirBoardRatherThanTheirShop(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);

        $link = $this->render($game)->filter('li a')->eq(1);

        $this->assertStringEndsWith('/'.$game->slug.'/player/alice', (string) $link->attr('href'));
        $this->assertStringContainsString('Alice', $link->text());
    }

    #[Test]
    public function eachRowNamesItsEmpireSoTheListCanBeColoured(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);

        $rows = $this->render($game)->filter('li');

        $this->assertNull($rows->eq(0)->attr('data-empire'), 'The operator plays no empire.');
        $this->assertSame('minoa', $rows->eq(1)->attr('data-empire'));
        $this->assertStringContainsString('minoan', $rows->eq(1)->text());
    }

    /** The point of the component: nineteen targets, nineteen QR codes, one single dialog. */
    #[Test]
    public function everyTargetGetsItsOwnQrPanelInsideOneSharedDialog(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);
        PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);

        $crawler = $this->render($game);
        $panels = $crawler->filter('dialog figure[data-key]');

        $this->assertCount(1, $crawler->filter('dialog'));
        $this->assertCount(3, $panels);
        $this->assertSame(['operator', 'alice', 'bob'], $panels->each(static fn (Crawler $panel): ?string => $panel->attr('data-key')));
        $this->assertCount(3, $panels->filter('svg'));
    }

    /** The panels are stacked and scrolled through, so each trigger must name the one it jumps to. */
    #[Test]
    public function eachTriggerNamesThePanelItScrollsTo(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);

        $crawler = $this->render($game);

        $this->assertSame(
            ['operator', 'alice'],
            $crawler->filter('li button[data-modal-key-param]')->each(static fn (Crawler $button): ?string => $button->attr('data-modal-key-param')),
        );
        $this->assertSame(
            ['modal#open', 'modal#open'],
            $crawler->filter('li button[data-modal-key-param]')->each(static fn (Crawler $button): ?string => $button->attr('data-action')),
        );
    }

    /** A panel states whose view it opens, and nothing else: the URL is the QR code's job. */
    #[Test]
    public function aPanelCarriesItsNameAndItsQrCodeWithoutSpellingTheUrlOut(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        $panel = $this->render($game)->filter('dialog figure[data-key="operator"]');

        $this->assertSame('Operator', $panel->filter('h2')->text());
        $this->assertCount(1, $panel->filter('svg'));
        $this->assertCount(0, $panel->filter('a'));
    }

    /** The panel wears the same empire colour as its row, so a scan lands on a recognisable card. */
    #[Test]
    public function aPlayerPanelNamesItsEmpire(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);

        $panels = $this->render($game)->filter('dialog figure');

        $this->assertNull($panels->eq(0)->attr('data-empire'), 'The operator plays no empire.');
        $this->assertSame('minoa', $panels->eq(1)->attr('data-empire'));
    }

    private function render(Game $game): Crawler
    {
        return new Crawler($this->renderTwigComponent('Navigation', ['game' => $game])->toString());
    }
}
