<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GameSession;
use App\Entity\Player;
use App\Game\Service\TaxCalculator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class OutlookTest extends WebTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $entityManager; // @phpstan-ignore property.uninitialized (initialized in setUp)

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** Sorted by urgency: what is broken first, then what threatens, then facts, then good news. */
    #[Test]
    public function linesAreSortedByUrgency(): void
    {
        $player = $this->createPlayer();
        $player->cities = 8;
        $player->census = 30;
        $player->treasury = 15;
        $this->entityManager->flush();

        $this->assertSame(['danger', 'caution', 'neutral', 'neutral', 'good', 'good'], $this->levelsOf($player));
    }

    #[Test]
    public function aComfortablePlayerIsNeverAlarmed(): void
    {
        $player = $this->createPlayer();
        $player->cities = 2;
        $player->census = 10;
        $this->entityManager->flush();

        $this->assertSame(['neutral', 'neutral', 'neutral', 'good', 'good'], $this->levelsOf($player));
        $this->assertSame([
            'You are 6 population over your city count',
            'You cannot build any city',
            'Your stock covers your taxes',
            'You lead the game',
            'You play last this turn',
        ], $this->messagesOf($player));
    }

    #[Test]
    public function aPlayerShortOfStockIsToldHowMuchToRecover(): void
    {
        $player = $this->createPlayer();
        $player->cities = 8;
        $player->census = 30;
        $player->treasury = 15;
        $this->entityManager->flush();

        $this->assertContains('You must recover 6 stock to pay your taxes', $this->messagesOf($player));
    }

    #[Test]
    public function aDemocracyOwnerIsToldTheirCitiesNeverRevolt(): void
    {
        $player = $this->createPlayer();
        $player->cities = 8;
        $player->census = 30;
        $player->treasury = 15;
        $player->ownAdvances([TaxCalculator::IMMUNITY_ADVANCE]);
        $this->entityManager->flush();

        $messages = $this->messagesOf($player);

        $this->assertContains('Your cities never revolt over taxes', $messages);
        $this->assertNotContains("You can't pay your taxes!", $messages);
    }

    /** A broken rule replaces its own margin line: there is no margin left to report. */
    #[Test]
    public function anUnderSupportedPlayerIsWarnedAndLosesTheMarginLine(): void
    {
        $player = $this->createPlayer();
        $player->cities = 3;
        $player->census = 2;
        $this->entityManager->flush();

        $messages = $this->messagesOf($player);

        $this->assertContains("You can't support your cities!", $messages);
        $this->assertSame([], array_filter(
            $messages,
            static fn (string $message): bool => str_contains($message, 'over your city count'),
        ));
    }

    #[Test]
    public function aPlayerOverTheHandLimitIsWarned(): void
    {
        $player = $this->createPlayer();
        $player->cities = 2;
        $player->census = 10;
        $player->cards = 9;
        $this->entityManager->flush();

        $this->assertContains('You must discard a card!', $this->messagesOf($player));
    }

    /** @return list<string> */
    private function messagesOf(Player $player): array
    {
        return $this->render($player)->filter('li')->each(
            static fn (Crawler $node): string => trim($node->text()),
        );
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

    private function createPlayer(): Player
    {
        $game = new GameSession();
        $this->entityManager->persist($game);

        $player = new Player($game, 'Bob');
        $this->entityManager->persist($player);
        $this->entityManager->flush();

        return $player;
    }
}
