<?php

declare(strict_types=1);

namespace App\Tests\Integration\Engine\Handler;

use App\Engine\Handler\SetStatHandler;
use App\Rules\Action\SetStat;
use App\Rules\Action\Stat;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SetStatHandlerTest extends WebTestCase
{
    private SetStatHandler $handler;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->handler = self::getContainer()->get(SetStatHandler::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function writesTheRequestedValue(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);

        ($this->handler)(new SetStat($player->id, Stat::Cities, 7));

        $this->assertSame(7, $player->cities);
    }

    #[Test]
    public function clampsAValueBeyondTheStatsCeiling(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);

        ($this->handler)(new SetStat($player->id, Stat::Cities, 42));

        $this->assertSame(9, $player->cities);
    }

    #[Test]
    public function requestingTheAlreadyStoredValueIsANoOp(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->withCities(5)->persist($this->entityManager);

        ($this->handler)(new SetStat($player->id, Stat::Cities, 5));

        $this->assertSame(5, $player->cities);
    }
}
