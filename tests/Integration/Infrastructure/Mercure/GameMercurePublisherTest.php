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
     */
    #[Test]
    #[DataProvider('provideEveryGameMutationPublishesItsEventOnTheGamesTopicCases')]
    public function everyGameMutationPublishesItsEventOnTheGamesTopic(\Closure $command, string $expectedEvent): void
    {
        $game = GameBuilder::create()->withCurrentTurn(2)->persist($this->entityManager);
        $player = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);
        $player->treasury = 7;
        $this->entityManager->flush();

        $this->bus->dispatch($command($player));

        $this->assertSame([$expectedEvent], $this->hub()->eventNames());
        $this->assertSame(['empires/game/'.$game->id], $this->hub()->topics());
    }

    public static function provideEveryGameMutationPublishesItsEventOnTheGamesTopicCases(): iterable
    {
        yield 'advancing the turn' => [static fn (Player $player): object => new NextTurn($player->game->id), 'game-updated'];

        yield 'rewinding the turn' => [static fn (Player $player): object => new PreviousTurn($player->game->id), 'game-updated'];

        yield 'finishing the game' => [static fn (Player $player): object => new FinishGame($player->game->id), 'game-updated'];

        yield 'writing a stat' => [static fn (Player $player): object => new SetStat($player->id, Stat::Cities, 7), 'player-updated'];

        yield 'applying a stat action' => [static fn (Player $player): object => new ApplyStatAction($player->id, StatAction::BuildShip), 'player-updated'];
    }

    /**
     * The Stimulus controller parses the payload by hand, so its exact shape is the contract —
     * asserted as a literal, since eventNames() would only prove the JSON decodes.
     */
    #[Test]
    public function theUpdateCarriesTheBareEventNameEnvelopeTheStimulusControllerParses(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        $this->bus->dispatch(new NextTurn($game->id));

        $this->assertSame('{"event":"game-updated"}', $this->hub()->updates()[0]->getData());
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
