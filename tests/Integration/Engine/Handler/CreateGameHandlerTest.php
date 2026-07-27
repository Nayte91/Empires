<?php

declare(strict_types=1);

namespace App\Tests\Integration\Engine\Handler;

use App\State\Player;
use App\Rules\Action\CreateGame;
use App\Engine\Handler\CreateGameHandler;
use App\State\CreditEntry;
use App\State\CreditSource;
use App\Infrastructure\Repository\PlayerRepository;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CreateGameHandlerTest extends WebTestCase
{
    private CreateGameHandler $handler;
    private PlayerRepository $playerRepository;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->handler = self::getContainer()->get(CreateGameHandler::class);
        $this->playerRepository = self::getContainer()->get(PlayerRepository::class);
    }

    /**
     * config/game/scenarios.yaml's 3.credits key is the same one
     * ShopConnector::buyerFor() used to derive from directly — it is now
     * frozen onto every player's ledger at creation time instead, immune to
     * a later edit of the scenario file re-pricing a game already in
     * progress.
     */
    #[Test]
    public function creatingAThreePlayerGamePostsTheScenariosStartingCreditsAtTurnZeroForEveryPlayer(): void
    {
        ($this->handler)($this->createGameCommand('ledger-three-players', 3, [
            ['name' => 'Alice', 'empire' => 'hatti'],
            ['name' => 'Bob', 'empire' => 'hellas'],
            ['name' => 'Carol', 'empire' => 'minoa'],
        ]));

        $alice = $this->findPlayer('ledger-three-players', 'alice');

        $this->assertEquals(
            [
                new CreditEntry(0, 'art', 10, CreditSource::Scenario, 'scenario:3'),
                new CreditEntry(0, 'civic', 10, CreditSource::Scenario, 'scenario:3'),
                new CreditEntry(0, 'craft', 10, CreditSource::Scenario, 'scenario:3'),
                new CreditEntry(0, 'religion', 10, CreditSource::Scenario, 'scenario:3'),
                new CreditEntry(0, 'science', 10, CreditSource::Scenario, 'scenario:3'),
            ],
            $alice->creditLedger,
        );
    }

    /**
     * Game::$playerCount defaults to 9, which config/game/scenarios.yaml
     * has no credits key for — the ledger must stay empty rather than posting
     * zero-value entries.
     */
    #[Test]
    public function creatingANinePlayerGamePostsNoStartingCreditsToTheLedger(): void
    {
        ($this->handler)($this->createGameCommand('ledger-nine-players', 9, [
            ['name' => 'Alice', 'empire' => 'hatti'],
            ['name' => 'Bob', 'empire' => 'rome'],
            ['name' => 'Carol', 'empire' => 'assyria'],
            ['name' => 'Dave', 'empire' => 'carthage'],
            ['name' => 'Eve', 'empire' => 'celt'],
            ['name' => 'Frank', 'empire' => 'egypt'],
            ['name' => 'Grace', 'empire' => 'hellas'],
            ['name' => 'Heidi', 'empire' => 'iberia'],
            ['name' => 'Ivan', 'empire' => 'minoa'],
        ]));

        $alice = $this->findPlayer('ledger-nine-players', 'alice');

        $this->assertSame([], $alice->creditLedger);
    }

    /** @param list<array{name: string, empire: string}> $players */
    private function createGameCommand(string $slug, int $playerCount, array $players): CreateGame
    {
        $command = new CreateGame();
        $command->slug = $slug;
        $command->playerCount = $playerCount;
        $command->players = $players;

        return $command;
    }

    private function findPlayer(string $gameSlug, string $playerSlug): Player
    {
        return $this->playerRepository->findOneByGameSlugAndSlug($gameSlug, $playerSlug)
            ?? throw new \RuntimeException(sprintf('Player "%s" not found in game "%s".', $playerSlug, $gameSlug));
    }
}
