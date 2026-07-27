<?php

declare(strict_types=1);

namespace App\Tests\Integration\Engine\Handler;

use App\Engine\Handler\NextTurnHandler;
use App\Rules\Action\NextTurn;
use App\Tests\Support\Fixture\GameBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class NextTurnHandlerTest extends WebTestCase
{
    private NextTurnHandler $handler;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->handler = self::getContainer()->get(NextTurnHandler::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function advancesTheGameByOneTurn(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        ($this->handler)(new NextTurn($game->id));

        $this->assertSame(2, $game->currentTurn);
    }

    #[Test]
    public function refusesToAdvanceBeyondTheLastTurn(): void
    {
        $game = GameBuilder::create()->withCurrentTurn(20)->persist($this->entityManager);

        ($this->handler)(new NextTurn($game->id));

        $this->assertSame(20, $game->currentTurn);
    }

    #[Test]
    public function refusesToAdvanceAFinishedGame(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $game->finishedAt = new \DateTimeImmutable();
        $this->entityManager->flush();

        ($this->handler)(new NextTurn($game->id));

        $this->assertSame(1, $game->currentTurn);
    }
}
