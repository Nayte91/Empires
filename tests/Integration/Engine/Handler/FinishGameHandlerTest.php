<?php

declare(strict_types=1);

namespace App\Tests\Integration\Engine\Handler;

use App\Engine\Handler\FinishGameHandler;
use App\Rules\Action\FinishGame;
use App\Tests\Support\Fixture\GameBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FinishGameHandlerTest extends WebTestCase
{
    private FinishGameHandler $handler;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->handler = self::getContainer()->get(FinishGameHandler::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function marksTheGameFinished(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        ($this->handler)(new FinishGame($game->id));

        $this->assertInstanceOf(\DateTimeImmutable::class, $game->finishedAt);
    }

    #[Test]
    public function refusesToRefinishAnAlreadyFinishedGame(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $game->finishedAt = new \DateTimeImmutable('2020-01-01');
        $this->entityManager->flush();

        ($this->handler)(new FinishGame($game->id));

        $this->assertSame('2020-01-01', $game->finishedAt->format('Y-m-d'));
    }
}
