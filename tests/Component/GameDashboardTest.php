<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\Fixture\Tables;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class GameDashboardTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithTwigComponents;

    #[Test]
    public function navigationIsTheLastBlockUnderTheBoards(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);

        $html = $this->renderTwigComponent('GameDashboard', ['game' => $game])->toString();

        $this->assertGreaterThan(strrpos($html, '<table'), strpos($html, '<nav'), 'Navigation comes after the roster and the A.S.T.');
        $this->assertLessThan(strrpos($html, '<nav'), strpos($html, '<nav'), 'The help block closes the page, after the navigation.');
        $this->assertSame(
            2,
            substr_count($html, '<dialog'),
            'The only dialogs the dashboard carries are Navigation’s, one QR code per target — here the operator board and Alice.',
        );
        $this->assertStringContainsString('/'.$game->slug.'/operator/board', $html);
    }

    #[Test]
    public function dashboardRootCarriesNoMercureRefreshController(): void
    {
        $game = Tables::westTable($this->entityManager);

        $html = $this->renderTwigComponent('GameDashboard', ['game' => $game])->toString();
        $rootTag = substr($html, 0, (int) strpos($html, '>') + 1);
        $beforeFirstTable = substr($html, 0, (int) strpos($html, '<table'));

        $this->assertStringNotContainsString('data-controller', $rootTag);
        $this->assertStringNotContainsString('mercure-refresh', $beforeFirstTable, 'mercure-refresh must only appear on the embedded Roster and Ast tables.');
    }
}
