<?php

declare(strict_types=1);

namespace App\Tests\Integration\Engine\Handler;

use App\Engine\Handler\ApplyStatActionHandler;
use App\Rules\Action\ApplyStatAction;
use App\Rules\Action\StatAction;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApplyStatActionHandlerTest extends WebTestCase
{
    private ApplyStatActionHandler $handler;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->handler = self::getContainer()->get(ApplyStatActionHandler::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function buildingAShipSpendsItsCostAndGrowsTheFleet(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);
        $player->treasury = 7;
        $this->entityManager->flush();

        ($this->handler)(new ApplyStatAction($player->id, StatAction::BuildShip));

        $this->assertSame(1, $player->ships);
        $this->assertSame(5, $player->treasury);
    }

    /**
     * The bug this handler exists to close: StatPicker::runAction() used to check only
     * isOffered() (via getActions()), so an offered-but-unaffordable action still applied,
     * clamped down to the treasury floor instead of being refused outright.
     */
    #[Test]
    public function refusesAnActionThatIsOfferedButNotAffordable(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);
        $player->treasury = 1;
        $this->entityManager->flush();

        ($this->handler)(new ApplyStatAction($player->id, StatAction::BuildShip));

        $this->assertSame(0, $player->ships);
        $this->assertSame(1, $player->treasury);
    }

    #[Test]
    public function refusesATaxRateTheirAdvancesDidNotUnlock(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);
        $player->treasury = 10;
        $this->entityManager->flush();

        ($this->handler)(new ApplyStatAction($player->id, StatAction::PayTaxes4));

        $this->assertSame(10, $player->treasury);
    }
}
