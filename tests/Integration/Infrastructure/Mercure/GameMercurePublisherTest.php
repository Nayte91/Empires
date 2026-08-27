<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Mercure;

use App\Engine\Event\PlayerUpdated;
use App\Rules\Action\ApplyStatAction;
use App\Rules\Action\CreateGame;
use App\Rules\Action\FinishGame;
use App\Rules\Action\NextTurn;
use App\Rules\Action\PreviousTurn;
use App\Rules\Action\SetStat;
use App\Rules\Action\Stat;
use App\Rules\Action\StatAction;
use App\State\Player;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\Mercure\RecordingHub;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The real-time chain driven from the bus, not from the publisher: every assertion is on what the
 * browser would receive, the topic and event name being a contract shared with the
 * `mercure-refresh` Stimulus controller and with nothing else.
 */
final class GameMercurePublisherTest extends WebTestCase
{
    private MessageBusInterface $bus;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->bus = self::getContainer()->get(MessageBusInterface::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Turn 2 and a funded player are what make all five commands legal at once: these handlers
     * publish only after a real mutation, so a refused command would empty the hub and pass a
     * weaker test for the wrong reason.
     *
     * @param \Closure(Player): object $command
     * @param \Closure(Player): list<string> $expectedRegions
     */
    #[Test]
    #[DataProvider('provideEveryGameMutationWakesTheRegionsItTouchesCases')]
    public function everyGameMutationWakesTheRegionsItTouches(\Closure $command, \Closure $expectedRegions): void
    {
        $game = GameBuilder::create()->withCurrentTurn(2)->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);
        $player->treasury = 7;
        $this->entityManager->flush();

        $this->bus->dispatch($command($player));

        $this->assertSame($expectedRegions($player), $this->hub()->regions());
    }

    public static function provideEveryGameMutationWakesTheRegionsItTouchesCases(): iterable
    {
        $turnChange = static fn (Player $player): array => ['roster', 'ast', 'operator', 'player/'.$player->id.'/shop'];
        $playerChange = static fn (Player $player): array => ['roster', 'ast', 'operator', 'player/'.$player->id];

        yield 'advancing the turn' => [static fn (Player $player): object => new NextTurn($player->game->id), $turnChange];

        yield 'rewinding the turn' => [static fn (Player $player): object => new PreviousTurn($player->game->id), $turnChange];

        yield 'finishing the game' => [static fn (Player $player): object => new FinishGame($player->game->id), $turnChange];

        yield 'writing a stat' => [static fn (Player $player): object => new SetStat($player->id, Stat::Cities, 7), $playerChange];

        yield 'applying a stat action' => [static fn (Player $player): object => new ApplyStatAction($player->id, StatAction::BuildShip), $playerChange];
    }

    /**
     * A board only wakes for its own player: the whole point of splitting the game topic, and the
     * thing a single shared topic could not express.
     */
    #[Test]
    public function aStatWriteLeavesEveryOtherPlayersBoardAsleep(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $bob = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);
        $alice = PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);

        $this->bus->dispatch(new SetStat($bob->id, Stat::Cities, 7));

        $this->assertContains('player/'.$bob->id, $this->hub()->regions());
        $this->assertNotContains('player/'.$alice->id, $this->hub()->regions());
    }

    /**
     * Two payload shapes, and which is which is the whole design: the dashboard regions travel
     * already rendered because nothing on that screen holds state a replace could destroy, and
     * every other region travels as a signal its own component answers by re-rendering itself.
     */
    #[Test]
    public function theDashboardRegionsCarryMarkupAndEveryOtherRegionCarriesNothing(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);
        $this->hub()->clear();

        $this->bus->dispatch(new NextTurn($game->id));

        $payloads = array_combine($this->hub()->regions(), array_map(
            static fn (\Symfony\Component\Mercure\Update $update): string => $update->getData(),
            $this->hub()->updates(),
        ));

        $this->assertStringContainsString('<turbo-stream action="replace"', $payloads['roster']);
        $this->assertStringContainsString('<turbo-stream action="replace"', $payloads['ast']);
        $this->assertSame('{}', $payloads['operator']);
    }

    /**
     * The one deliberate silence in the chain, pinned because the omission reads as a forgotten
     * line and the next reader would "fix" it.
     */
    #[Test]
    public function creatingAGamePublishesNothingAtAll(): void
    {
        $command = new CreateGame();
        $command->slug = 'mercure-silent-creation';
        $command->playerCount = 3;
        $command->players = [
            ['name' => 'Alice', 'empire' => 'hatti'],
            ['name' => 'Bob', 'empire' => 'hellas'],
            ['name' => 'Carol', 'empire' => 'minoa'],
        ];

        $this->bus->dispatch($command);

        $this->assertSame([], $this->hub()->updates());
    }

    /**
     * The last way this class could break a committed mutation: an unresolvable player is logged
     * and dropped, never raised.
     *
     * Dispatches a domain event rather than a command, because no command reaches this path —
     * SetStatHandler and ApplyStatActionHandler resolve the player and throw first.
     */
    #[Test]
    public function anEventNamingAPlayerThatNoLongerExistsNeitherThrowsNorPublishes(): void
    {
        $vanishedPlayerId = Uuid::v7();

        $this->bus->dispatch(new PlayerUpdated($vanishedPlayerId));

        $this->assertSame([], $this->hub()->updates());
    }

    /** Fetched per call: the test container refuses to replace an already initialized service. */
    private function hub(): RecordingHub
    {
        return self::getContainer()->get(RecordingHub::class);
    }
}
