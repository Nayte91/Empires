<?php

declare(strict_types=1);

namespace App\Tests\Integration\Engine\Handler;

use App\Engine\Handler\PreviousTurnHandler;
use App\Rules\Action\PreviousTurn;
use App\Tests\Support\Fixture\GameBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PreviousTurnHandlerTest extends WebTestCase
{
    private PreviousTurnHandler $handler;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->handler = self::getContainer()->get(PreviousTurnHandler::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function rewindsTheGameByOneTurn(): void
    {
        $game = GameBuilder::create()->withCurrentTurn(2)->persist($this->entityManager);

        ($this->handler)(new PreviousTurn($game->id));

        $this->assertSame(1, $game->currentTurn);
    }

    #[Test]
    public function refusesToGoBelowTheFirstTurn(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        ($this->handler)(new PreviousTurn($game->id));

        $this->assertSame(1, $game->currentTurn);
    }

    #[Test]
    public function refusesToRewindAFinishedGame(): void
    {
        $game = GameBuilder::create()->withCurrentTurn(2)->persist($this->entityManager);
        $game->finishedAt = new \DateTimeImmutable();
        $this->entityManager->flush();

        ($this->handler)(new PreviousTurn($game->id));

        $this->assertSame(2, $game->currentTurn);
    }
}
