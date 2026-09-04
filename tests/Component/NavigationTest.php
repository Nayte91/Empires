<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Presentation\Component\Navigation;
use App\State\Game;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\Fixture\Tables;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class NavigationTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithTwigComponents;

    #[Test]
    public function theOperatorBoardLeadsTheWaysInAndThePlayerBoardsFollowInSeatOrder(): void
    {
        $game = Tables::westTable($this->entityManager);

        $targets = $this->mount($game)->getTargets();

        $this->assertSame(
            ['operator', 'alice', 'bob', 'carol', 'dave', 'eve'],
            array_column($targets, 'key'),
        );
        $this->assertStringEndsWith('/'.$game->slug.'/operator/board', $targets[0]['url']);
        $this->assertStringEndsWith('/'.$game->slug.'/player/alice', $targets[1]['url']);
    }

    #[Test]
    public function eachTargetGetsADialogOfItsOwnHoldingOneCode(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);
        PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);

        $dialogs = $this->render($game)->filter('dialog');

        $this->assertSame(
            ['qr-operator', 'qr-alice', 'qr-bob'],
            $dialogs->each(static fn (Crawler $dialog): ?string => $dialog->attr('id')),
        );
        $this->assertCount(3, $dialogs->filter('img'));
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
        $this->assertCount(0, $crawler->filter('[data-controller]'));
    }

    private function mount(Game $game): Navigation
    {
        $component = $this->mountTwigComponent('Navigation', ['game' => $game]);
        $this->assertInstanceOf(Navigation::class, $component);

        return $component;
    }

    private function render(Game $game): Crawler
    {
        return new Crawler($this->renderTwigComponent('Navigation', ['game' => $game])->toString());
    }
}
