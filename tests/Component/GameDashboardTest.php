<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class GameDashboardTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithTwigComponents;

    #[Test]
    public function headingDisplaysTheGameSlugWithoutTurn(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        $html = $this->renderTwigComponent('GameDashboard', ['game' => $game])->toString();

        $this->assertSame(1, preg_match('/<h1>(.*?)<\/h1>/s', $html, $matches), '<h1> not found in rendered output.');
        $this->assertSame($game->slug, trim($matches[1]));
    }

    /** Every route out of the dashboard now lives in one place, above the roster. */
    #[Test]
    public function navigationIsTheFirstBlockUnderTheTitle(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);

        $html = $this->renderTwigComponent('GameDashboard', ['game' => $game])->toString();

        $this->assertLessThan(strpos($html, '<table'), strpos($html, '<nav'), 'Navigation comes before the roster.');
        $this->assertGreaterThan(strpos($html, '</h1>'), strpos($html, '<nav'), 'Navigation comes after the title.');
        $this->assertSame(
            2,
            substr_count($html, '<dialog'),
            'The only dialogs the dashboard carries are Navigation’s, one QR code per target — here the operator console and Alice.',
        );
        $this->assertStringContainsString('/'.$game->slug.'/operator', $html);
    }

    #[Test]
    public function dashboardRootCarriesNoMercureRefreshController(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        $html = $this->renderTwigComponent('GameDashboard', ['game' => $game])->toString();
        $rootTag = substr($html, 0, (int) strpos($html, '>') + 1);
        $beforeFirstTable = substr($html, 0, (int) strpos($html, '<table'));

        $this->assertStringNotContainsString('data-controller', $rootTag);
        $this->assertStringNotContainsString('mercure-refresh', $beforeFirstTable, 'mercure-refresh must only appear on the embedded Roster and Ast tables.');
    }
}
