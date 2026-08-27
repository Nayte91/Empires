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

    /**
     * It used to be a disclosure, costing one line until someone asked for it. The phone gave it a
     * screen of its own, where folding the list away would hide the only thing that screen is for —
     * so it is a flat list of ways in, and the element says so.
     */
    #[Test]
    public function theSeatsAreAFlatListOfLinksRatherThanADisclosure(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        $crawler = $this->render($game);

        $this->assertCount(0, $crawler->filter('details'));
        $this->assertCount(1, $crawler->filter('nav > ul'));
        $this->assertSame('nav', $crawler->filter('nav')->nodeName());
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

    #[Test]
    public function eachTargetGetsADialogOfItsOwnHoldingOneCode(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);
        PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);

        $crawler = $this->render($game);
        $dialogs = $crawler->filter('dialog');

        $this->assertCount(3, $dialogs);
        $this->assertSame(
            ['qr-operator', 'qr-alice', 'qr-bob'],
            $dialogs->each(static fn (Crawler $dialog): ?string => $dialog->attr('id')),
        );

        foreach ($dialogs as $dialog) {
            $this->assertCount(1, new Crawler($dialog)->filter('img[loading="lazy"]'), 'One code per dialog, and it waits to be asked for.');
        }
    }

    #[Test]
    public function eachTriggerCommandsItsOwnDialogWithoutJavaScript(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);

        $crawler = $this->render($game);
        $triggers = $crawler->filter('li button[commandfor]');

        $this->assertSame(
            ['qr-operator', 'qr-alice'],
            $triggers->each(static fn (Crawler $button): ?string => $button->attr('commandfor')),
        );
        $this->assertSame(
            ['show-modal', 'show-modal'],
            $triggers->each(static fn (Crawler $button): ?string => $button->attr('command')),
        );
        $this->assertCount(0, $crawler->filter('[data-controller]'), 'The panel drives no Stimulus controller any more.');
    }

    /** A panel states whose view it opens, and nothing else: the URL is the QR code's job. */
    #[Test]
    public function aPanelCarriesItsNameAndItsQrCodeWithoutSpellingTheUrlOut(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        $panel = $this->render($game)->filter('dialog figure[data-key="operator"]');

        $this->assertSame('Operator', $panel->filter('h2')->text());
        $this->assertCount(1, $panel->filter('img[loading="lazy"]'));
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
