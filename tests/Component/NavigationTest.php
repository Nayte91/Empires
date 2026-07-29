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

    /** The sheet shows one seat, so each trigger must name the panel it opens. */
    #[Test]
    public function eachTriggerNamesThePanelItOpens(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);

        $crawler = $this->render($game);

        $this->assertSame(
            ['operator', 'alice'],
            $crawler->filter('details li button[data-modal-key-param]')->each(static fn (Crawler $button): ?string => $button->attr('data-modal-key-param')),
        );
        $this->assertSame(
            ['modal#open', 'modal#open'],
            $crawler->filter('details li button[data-modal-key-param]')->each(static fn (Crawler $button): ?string => $button->attr('data-action')),
        );
    }

    /**
     * The phone's form of the list. Both forms are rendered — the breakpoint picks one — so the
     * QR panels must not be: fifteen inline SVGs are the expensive part of this component.
     */
    #[Test]
    public function theSeatListRepeatsTheTargetsWithoutRepeatingTheirQrPanels(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);

        $crawler = $this->render($game);

        $this->assertCount(2, $crawler->filter('.seat-list li'));
        $this->assertCount(1, $crawler->filter('dialog'));
        $this->assertCount(2, $crawler->filter('dialog figure'));
        $this->assertSame(
            ['operator', 'alice'],
            $crawler->filter('.seat-list li button[data-modal-key-param]')->each(static fn (Crawler $button): ?string => $button->attr('data-modal-key-param')),
        );
    }

    /** The filter runs client-side, so every row carries what it can be matched on. */
    #[Test]
    public function eachSeatCarriesItsNameAndEmpireFoldedForSearching(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);

        $row = $this->render($game)->filter('.seat-list li')->eq(1);

        $this->assertStringContainsString('alice', (string) $row->attr('data-search'));
        $this->assertStringContainsString('minoa', (string) $row->attr('data-search'));
        $this->assertSame('minoan', trim($row->filter('small')->text()));
    }

    /** The operator is not an empire, so its row says what it is instead of naming one. */
    #[Test]
    public function theOperatorRowNamesTheConsoleRatherThanAnEmpire(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);

        $rows = $this->render($game)->filter('.seat-list li');

        $this->assertSame('console · this device', trim($rows->eq(0)->filter('small')->text()));
        $this->assertNotNull($rows->eq(0)->attr('data-operator'));
        $this->assertSame('minoan', trim($rows->eq(1)->filter('small')->text()));
    }

    /**
     * A card states whose view it opens, what they are, and the address the code carries — for
     * anyone who would rather type it than scan it. The address stays text: tapping it here would
     * open the seat's board on the operator's own phone, which is the one thing it must not do.
     */
    #[Test]
    public function aCardCarriesTheNameTheRoleTheCodeAndTheAddressItStandsFor(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        $panel = $this->render($game)->filter('dialog figure[data-key="operator"]');

        $this->assertSame('Operator', $panel->filter('h2')->text());
        $this->assertSame('console', trim($panel->filter('small')->text()));
        $this->assertCount(1, $panel->filter('svg'));
        $this->assertStringEndsWith('/'.$game->slug.'/operator', trim($panel->filter('figcaption')->text()));
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
