<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GameSession;
use App\Entity\Player;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class OperatorConsoleTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithLiveComponents;

    #[Test]
    public function mercureRefreshHasNoEventFilterSoItReceivesEveryEvent(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        $rendered = $this->createLiveComponent('OperatorConsole', ['game' => $game])->render()->toString();

        $this->assertStringNotContainsString('data-mercure-refresh-events-value', $rendered);
    }

    #[Test]
    public function rendersTheCurrentTurn(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        $rendered = $this->createLiveComponent('OperatorConsole', ['game' => $game])->render();

        $this->assertStringContainsString('Turn 1', $rendered->toString());
    }

    #[Test]
    public function previousTurnButtonIsDisabledOnTurnOne(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        $rendered = $this->createLiveComponent('OperatorConsole', ['game' => $game])->render();

        $button = $rendered->crawler()->filter('button')->reduce(
            static fn ($node): bool => str_contains((string) $node->text(), 'Previous turn'),
        );

        $this->assertNotNull($button->attr('disabled'));
    }

    #[Test]
    public function rendersOneDetailsTabPerPlayerPlusGeneralSharingTheSameGroupWithGeneralOpenByDefault(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);
        PlayerBuilder::named('Bob')->in($game)->withEmpire('minoa')->persist($this->entityManager);

        $rendered = $this->createLiveComponent('OperatorConsole', ['game' => $game])->render();

        $details = $rendered->crawler()->filter('details');

        $this->assertCount(3, $details);

        $names = [];
        foreach ($details as $node) {
            $names[] = $node->getAttribute('name');
        }
        $this->assertSame(['operator-tabs'], array_unique($names));

        $generalDetails = $details->reduce(
            static fn ($node): bool => 'General' === trim((string) $node->filter('summary')->text()),
        );
        $this->assertNotNull($generalDetails->attr('open'));
    }

    #[Test]
    public function generalPanelContainsTheTurnButtons(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        $rendered = $this->createLiveComponent('OperatorConsole', ['game' => $game])->render();

        $generalDetails = $rendered->crawler()->filter('details')->reduce(
            static fn ($node): bool => 'General' === trim((string) $node->filter('summary')->text()),
        );

        $buttonTexts = $generalDetails->filter('button')->each(
            static fn ($node): string => trim($node->text()),
        );

        $this->assertContains('« Previous turn', $buttonTexts);
        $this->assertContains('Next turn »', $buttonTexts);
        $this->assertContains('Finish game', $buttonTexts);
    }

    #[Test]
    public function aPlayerTabPanelContainsTheSixStatTriggerButtons(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);

        $rendered = $this->createLiveComponent('OperatorConsole', ['game' => $game])->render();

        $playerDetails = $rendered->crawler()->filter("details[data-tab-id=\"{$player->id}\"]");

        $this->assertCount(6, $playerDetails->filter('button[command="show-modal"]'));
    }

    /**
     * The console shares the player board's ControlBoard, but the operator drives every player
     * from one screen — a link into a single player's shop belongs to that player's own board.
     */
    #[Test]
    public function aPlayerTabPanelOffersNoShopLink(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);

        $rendered = $this->createLiveComponent('OperatorConsole', ['game' => $game])->render();

        $playerDetails = $rendered->crawler()->filter("details[data-tab-id=\"{$player->id}\"]");

        $this->assertCount(0, $playerDetails->filter('a[href$="/shop"]'));
    }

    /**
     * Advisories left the control board for the Outlook block, which the console does not carry:
     * the operator screen is deliberately operational only, counters and commands.
     */
    #[Test]
    public function aPlayerTabPanelCarriesNoAdvisory(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);
        $player->cities = 3;
        $player->census = 2;
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('OperatorConsole', ['game' => $game])->render();

        $playerDetails = $rendered->crawler()->filter("details[data-tab-id=\"{$player->id}\"]");

        $this->assertSame([], array_filter(
            $playerDetails->filter('li')->each(static fn ($node): string => trim($node->text())),
            static fn (string $text): bool => str_contains($text, "You can't"),
        ));
    }

    #[Test]
    public function previousTurnButtonIsEnabledOnTurnTwo(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $game->currentTurn = 2;
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('OperatorConsole', ['game' => $game])->render();

        $button = $rendered->crawler()->filter('button')->reduce(
            static fn ($node): bool => str_contains((string) $node->text(), 'Previous turn'),
        );

        $this->assertNull($button->attr('disabled'));
    }

    #[Test]
    public function nextTurnIncrementsTheCurrentTurn(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        $this->createLiveComponent('OperatorConsole', ['game' => $game])->call('nextTurn');

        $this->assertSame(2, $this->reloadGame($game)->currentTurn);
    }

    #[Test]
    public function nextTurnClampsAtTwenty(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $game->currentTurn = 20;
        $this->entityManager->flush();

        $this->createLiveComponent('OperatorConsole', ['game' => $game])->call('nextTurn');

        $this->assertSame(20, $this->reloadGame($game)->currentTurn);
    }

    #[Test]
    public function finishGameFillsInFinishedAt(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        $this->createLiveComponent('OperatorConsole', ['game' => $game])->call('finishGame');

        $this->assertInstanceOf(\DateTimeImmutable::class, $this->reloadGame($game)->finishedAt);
    }

    #[Test]
    public function nextTurnOnAFinishedGameDoesNotChangeTheCurrentTurn(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $game->finishedAt = new \DateTimeImmutable();
        $this->entityManager->flush();

        $this->createLiveComponent('OperatorConsole', ['game' => $game])->call('nextTurn');

        $this->assertSame(1, $this->reloadGame($game)->currentTurn);
    }

    #[Test]
    public function aPlayerTabPanelContainsItsOrderCardsRegionWithAnEmptyCardForTheCurrentTurn(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);

        $rendered = $this->createLiveComponent('OperatorConsole', ['game' => $game])->render();

        $playerDetails = $rendered->crawler()->filter("details[data-tab-id=\"{$player->id}\"]");

        $card = $playerDetails->filter('article')->reduce(
            static fn ($node): bool => str_contains((string) $node->text(), 'Turn 1'),
        );

        $this->assertStringContainsString('Empty', $card->text());
    }

    #[Test]
    public function ordersStampForChangesWhenTheCurrentTurnChangesEvenWithZeroOrders(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);

        $console = $this->createLiveComponent('OperatorConsole', ['game' => $game])->component();
        $stampAtTurnOne = $console->ordersStampFor($player);

        $game->currentTurn = 2;
        $this->entityManager->flush();

        $stampAtTurnTwo = $console->ordersStampFor($this->reloadPlayer($player));

        $this->assertNotSame($stampAtTurnOne, $stampAtTurnTwo);
    }

    #[Test]
    public function advancingTurnsRendersOneOrderCardPerElapsedTurnInThePlayerTab(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);

        $console = $this->createLiveComponent('OperatorConsole', ['game' => $game]);
        $console->call('nextTurn');
        $rendered = $console->call('nextTurn')->render();

        $this->assertSame(3, $this->reloadGame($game)->currentTurn);

        $playerDetails = $rendered->crawler()->filter("details[data-tab-id=\"{$player->id}\"]");

        $cardTexts = $playerDetails->filter('article')->each(
            static fn ($node): string => trim($node->text()),
        );

        $this->assertCount(3, $cardTexts);
        $this->assertTrue((bool) preg_grep('/Turn 1\b/', $cardTexts));
        $this->assertTrue((bool) preg_grep('/Turn 2\b/', $cardTexts));
        $this->assertTrue((bool) preg_grep('/Turn 3\b/', $cardTexts));
    }

    private function freshEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function reloadGame(GameSession $game): GameSession
    {
        $reloaded = $this->freshEntityManager()->find(GameSession::class, $game->id);
        $this->assertInstanceOf(GameSession::class, $reloaded);

        return $reloaded;
    }

    private function reloadPlayer(Player $player): Player
    {
        $reloaded = $this->freshEntityManager()->find(Player::class, $player->id);
        $this->assertInstanceOf(Player::class, $reloaded);

        return $reloaded;
    }
}
