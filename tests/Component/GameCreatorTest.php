<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Rules\Action\CreateGame;
use App\Rules\Ruleset\ScenarioRegistry;
use App\State\ASTVersion;
use App\State\Game;
use App\State\Player;
use App\State\Region;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\GameFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

final class GameCreatorTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithLiveComponents;

    private const array WEST_NINE_EMPIRES = ['assyria', 'carthage', 'celt', 'egypt', 'hatti', 'hellas', 'iberia', 'minoa', 'rome'];

    private const string TRUNCATED_HAN_SLUG = 'han-han-han-han-han-';

    #[Test]
    public function mountProposesAUuidAsTheDefaultGameSlug(): void
    {
        $component = $this->createLiveComponent('GameCreator');

        $this->assertTrue(Uuid::isValid($component->component()->game->slug));
    }

    #[Test]
    public function thePlayerCountBoundsComeFromTheScenariosThemselves(): void
    {
        $counts = self::getContainer()->get(ScenarioRegistry::class)->playerCounts();

        $component = $this->createLiveComponent('GameCreator')->component();

        $this->assertSame($counts[0], $component->getMinPlayers());
        $this->assertSame($counts[\count($counts) - 1], $component->getMaxPlayers());
    }

    #[Test]
    public function theScenarioSummaryFollowsThePlayerCount(): void
    {
        $summary = $this->createLiveComponent('GameCreator')
            ->set('game.playerCount', 12)
            ->component()
            ->getScenarioSummary()
        ;

        $this->assertSame(['Card limit: 9'], $summary);
    }

    #[Test]
    public function settingTheSlugSlugifiesItAndLeavesItAvailable(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('game.slug', 'Super Game de Nayte')
            ->component()
        ;

        $this->assertSame('super-game-de-nayte', $component->game->slug);
        $this->assertTrue($component->isSlugAvailable());
    }

    #[Test]
    public function theSlugOfAnExistingGameIsReportedAsUnavailable(): void
    {
        GameBuilder::create()->withSlug('taken-slug')->persist($this->entityManager);

        $component = $this->createLiveComponent('GameCreator')
            ->set('game.slug', 'Taken Slug')
            ->component()
        ;

        $this->assertSame('taken-slug', $component->game->slug);
        $this->assertFalse($component->isSlugAvailable());
        $this->assertSame('Slug "taken-slug" is not available.', (string) $component->getError('game.slug')?->getMessage());
    }

    #[Test]
    public function aReservedSlugIsReportedAsUnavailable(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('game.slug', 'create')
            ->component()
        ;

        $this->assertFalse($component->isSlugAvailable());
        $this->assertSame('This name is reserved.', (string) $component->getError('game.slug')?->getMessage());
    }

    #[Test]
    public function aPlayerCountAboveNineOffersTheTwoBoxScenarioAndNoRegionOfItsOwn(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('game.playerCount', 10)
            ->component()
        ;

        $this->assertSame([['value' => '', 'label' => 'West + East']], $component->getRegionChoices());
        $this->assertNull($component->game->region);
    }

    #[Test]
    public function returningBelowTenAfterBeingAboveRestoresWestAsTheDefaultRegion(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('game.playerCount', 10)
        ;

        $component->set('game.playerCount', 9);

        $this->assertSame('west', $component->component()->game->region);
    }

    #[Test]
    public function aRegionNoScenarioOffersSnapsBackToTheDefault(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('game.playerCount', 9)
            ->set('game.region', 'banane')
        ;

        $this->assertSame('west', $component->component()->game->region);
    }

    #[Test]
    public function belowTenBothBoxesAreOfferedAndTheChosenOneStands(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('game.playerCount', 9)
            ->set('game.region', 'west')
            ->component()
        ;

        $this->assertSame(
            [['value' => 'west', 'label' => 'West'], ['value' => 'east', 'label' => 'East']],
            $component->getRegionChoices(),
        );
        $this->assertSame('west', $component->game->region);
    }

    #[Test]
    public function addingPlayersPersistsNothing(): void
    {
        $gamesBefore = $this->entityManager->getRepository(Game::class)->count([]);
        $playersBefore = $this->entityManager->getRepository(Player::class)->count([]);

        $component = $this->createLiveComponent('GameCreator');
        $this->addPlayer($component, 'Alice', 'hatti');
        $this->addPlayer($component, 'Bob', 'rome');

        $this->assertSame([
            ['name' => 'Alice', 'empire' => 'hatti'],
            ['name' => 'Bob', 'empire' => 'rome'],
        ], $component->component()->players);

        $freshEntityManager = $this->freshEntityManager();
        $this->assertSame($gamesBefore, $freshEntityManager->getRepository(Game::class)->count([]));
        $this->assertSame($playersBefore, $freshEntityManager->getRepository(Player::class)->count([]));
    }

    #[Test]
    public function addingAPlayerWithADuplicateNameSlugIsRefused(): void
    {
        $component = $this->createLiveComponent('GameCreator');
        $this->addPlayer($component, 'Alice', 'hatti');
        $this->addPlayer($component, 'alice', 'rome');

        $this->assertSame(['Name already taken.'], $this->fieldErrorsOf($component, 'newPlayerName'));
        $this->assertSame([['name' => 'Alice', 'empire' => 'hatti']], $component->component()->players);
    }

    #[Test]
    public function addingAPlayerWhoseNameOnlyDiffersBySurroundingWhitespaceIsRefused(): void
    {
        $component = $this->createLiveComponent('GameCreator');
        $this->addPlayer($component, '  toto  ', 'hatti');
        $this->addPlayer($component, '  toto  ', 'rome');

        $this->assertSame(['Name already taken.'], $this->fieldErrorsOf($component, 'newPlayerName'));
        $this->assertSame([['name' => 'Toto', 'empire' => 'hatti']], $component->component()->players);
    }

    #[Test]
    public function addingAPlayerWithAnEmpireOutsideTheCurrentScenarioIsRefused(): void
    {
        $component = $this->createLiveComponent('GameCreator')->set('game.playerCount', 5);
        $this->addPlayer($component, 'Alice', 'celt');

        $this->assertSame([], $component->component()->players);
    }

    #[Test]
    #[DataProvider('provideAddingAPlayerWithABlankNameShowsAFieldErrorCases')]
    public function addingAPlayerWithABlankNameShowsAFieldError(string $unusableName): void
    {
        $component = $this->createLiveComponent('GameCreator')->set('newPlayerName', $unusableName);
        $component->call('addPlayer');

        $this->assertSame(['Player name is required.'], $this->fieldErrorsOf($component, 'newPlayerName'));
    }

    public static function provideAddingAPlayerWithABlankNameShowsAFieldErrorCases(): iterable
    {
        yield 'nothing at all' => [''];

        yield 'whitespace only' => ['   '];

        yield 'punctuation only' => ['!!!'];

        yield 'a row of hyphens' => ['---'];
    }

    #[Test]
    public function newPlayerNameFieldErrorDoesNotPersistAfterASuccessfulAdd(): void
    {
        $component = $this->createLiveComponent('GameCreator')->set('newPlayerName', '');
        $component->call('addPlayer');

        $this->assertNotSame([], $this->fieldErrorsOf($component, 'newPlayerName'));

        $this->addPlayer($component, 'Alice', 'hatti');

        $this->assertSame([], $this->fieldErrorsOf($component, 'newPlayerName'));
    }

    #[Test]
    public function launchCreatesTheGameAndItsPlayersAndRedirectsToTheGameDashboard(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('game.slug', 'Launch Game')
            ->set('game.playerCount', 9)
            ->set('game.region', 'west')
            ->set('game.astVersion', 'expert')
        ;
        foreach ([
            ['Alice', 'hatti'],
            ['Bob', 'rome'],
            ['Carol', 'assyria'],
            ['Dave', 'carthage'],
            ['Eve', 'celt'],
            ['Frank', 'egypt'],
            ['Grace', 'hellas'],
            ['Heidi', 'iberia'],
            ['Ivan', 'minoa'],
        ] as [$name, $empire]) {
            $this->addPlayer($component, $name, $empire);
        }

        $component->call('launch');

        $response = $component->response();
        $this->assertSame(Response::HTTP_FOUND, $response->getStatusCode(), (string) $response->getContent());
        $this->assertStringEndsWith('/launch-game', (string) $response->headers->get('Location'));

        $freshEntityManager = $this->freshEntityManager();
        $game = $freshEntityManager->getRepository(Game::class)->findOneBy(['slug' => 'launch-game']);

        $this->assertInstanceOf(Game::class, $game);
        $this->assertSame(9, $game->playerCount);
        $this->assertSame(Region::West, $game->region);
        $this->assertSame(ASTVersion::EXPERT, $game->astVersion);

        $players = $freshEntityManager->getRepository(Player::class)->findBy(['game' => $game->id]);
        $this->assertCount(9, $players);

        $alice = null;
        foreach ($players as $player) {
            if ('Alice' === $player->name) {
                $alice = $player;
            }
        }

        $this->assertInstanceOf(Player::class, $alice);
        $this->assertSame('alice', $alice->slug);
        $this->assertSame('hatti', $alice->empire);
    }

    #[Test]
    #[DataProvider('provideAConformingRosterLaunchesCases')]
    public function aConformingRosterLaunches(int $playerCount, ?string $region, string $slug, ?string $firstPlayerName): void
    {
        $players = array_map(
            static fn (string $empire): array => ['name' => ucfirst($empire).' player', 'empire' => $empire],
            self::getContainer()->get(ScenarioRegistry::class)->find($playerCount, null === $region ? null : Region::from($region))->empires,
        );

        if (null !== $firstPlayerName) {
            $players[0]['name'] = $firstPlayerName;
        }

        $component = $this->creatorWith($players, $playerCount, $region, $slug);
        $component->call('launch');

        $this->assertSame(Response::HTTP_FOUND, $component->response()->getStatusCode(), (string) $component->response()->getContent());

        $freshEntityManager = $this->freshEntityManager();
        $createdGame = $freshEntityManager->getRepository(Game::class)->findOneBy(['slug' => $slug]);

        $this->assertInstanceOf(Game::class, $createdGame);
        $this->assertCount($playerCount, $freshEntityManager->getRepository(Player::class)->findBy(['game' => $createdGame->id]));
    }

    public static function provideAConformingRosterLaunchesCases(): iterable
    {
        yield 'a three-player roster of plain names' => [3, 'west', 'usable-names-launch', null];

        yield 'a full eighteen-player roster' => [18, null, 'eighteen-player-launch', null];

        yield 'a player name of twenty ascii characters' => [3, 'west', 'ascii-limit-name-launch', str_repeat('a', Player::MAX_NAME_LENGTH)];

        yield 'a player name of twenty accented characters, forty bytes' => [3, 'west', 'accented-limit-name-launch', str_repeat('é', Player::MAX_NAME_LENGTH)];

        yield 'a game name exactly at the length limit' => [3, 'west', str_repeat('a', Game::MAX_SLUG_LENGTH), null];
    }

    /** @param list<array{name: string, empire: string}> $players */
    #[Test]
    #[DataProvider('provideLaunchIsRefusedServerSideAndNothingIsCreatedCases')]
    public function launchIsRefusedServerSideAndNothingIsCreated(array $players, int $playerCount, string $slug, ?string $expectedIssue): void
    {
        $gamesBefore = $this->entityManager->getRepository(Game::class)->count([]);
        $playersBefore = $this->entityManager->getRepository(Player::class)->count([]);

        $component = $this->creatorWith($players, $playerCount, 'west', $slug);
        $component->call('launch');

        $this->assertFalse($component->component()->canLaunch());

        if (null !== $expectedIssue) {
            $this->assertContains($expectedIssue, $component->component()->getConformityIssues());
        }

        $freshEntityManager = $this->freshEntityManager();
        $this->assertSame($gamesBefore, $freshEntityManager->getRepository(Game::class)->count([]));
        $this->assertSame($playersBefore, $freshEntityManager->getRepository(Player::class)->count([]));
    }

    public static function provideLaunchIsRefusedServerSideAndNothingIsCreatedCases(): iterable
    {
        yield 'two names folding to the same slug' => [self::westRosterNamed('Bob', 'BOB', 'Carol'), 3, 'colliding-names-launch', 'Bob and BOB share the name "bob".'];

        yield 'a blank player name' => [self::westRosterNamed('', 'Bob', 'Carol'), 3, 'blank-name-launch', '1 player has no usable name.'];

        yield 'a player count no roster matches' => [[], 9, 'mismatch-launch', 'Add 9 more players.'];

        yield 'a player count of zero' => [[], 0, 'empty-roster-launch', null];

        yield 'a player without an empire' => [[
            ['name' => 'Alice', 'empire' => 'hatti'],
            ['name' => 'Bob', 'empire' => 'hellas'],
            ['name' => 'Carol', 'empire' => ''],
        ], 3, 'no-empire-launch', '1 player still needs an empire.'];

        yield 'an injected player name over the length limit' => [self::westRosterNamed(str_repeat('a', Player::MAX_NAME_LENGTH + 1), 'Bob', 'Carol'), 3, 'overlong-name-launch', null];

        yield 'an injected game name over the length limit' => [self::westRosterNamed('Alice', 'Bob', 'Carol'), 3, str_repeat('a', Game::MAX_SLUG_LENGTH + 1), null];
    }

    #[Test]
    public function aSlugTakenAtLaunchTimeIsReportedAndCreatesNothing(): void
    {
        GameBuilder::create()->withSlug('race-slug')->persist($this->entityManager);

        $gamesBefore = $this->entityManager->getRepository(Game::class)->count([]);

        $component = $this->creatorWith(self::westRosterNamed('Alice', 'Bob', 'Carol'), slug: 'race-slug');
        $component->call('launch');

        $this->assertSame('Slug "race-slug" is not available.', (string) $component->component()->getError('game.slug')?->getMessage());

        $this->assertSame($gamesBefore, $this->freshEntityManager()->getRepository(Game::class)->count([]));
    }

    #[Test]
    public function launchingWithTheReservedCreateSlugIsReportedAndCreatesNothing(): void
    {
        $gamesBefore = $this->entityManager->getRepository(Game::class)->count([]);

        $component = $this->creatorWith(self::westRosterNamed('Alice', 'Bob', 'Carol'), slug: 'create');
        $component->call('launch');

        $this->assertSame('This name is reserved.', (string) $component->component()->getError('game.slug')?->getMessage());

        $this->assertSame($gamesBefore, $this->freshEntityManager()->getRepository(Game::class)->count([]));
    }

    #[Test]
    public function addPlayerIsRefusedWhenThePlayerLimitIsReached(): void
    {
        $component = $this->creatorWith(self::westRosterNamed('Alice', 'Bob', 'Carol'));

        $this->assertTrue($component->component()->isPlayerLimitReached());

        $this->addPlayer($component, 'Dave', 'egypt');

        $this->assertCount(3, $component->component()->players);
    }

    #[Test]
    public function loweringThePlayerCountBelowTheAlreadyAddedPlayersReachesTheLimit(): void
    {
        $component = $this->creatorWith(self::westRosterNamed('Alice', 'Bob', 'Carol'), 5)
            ->set('game.playerCount', 3)
        ;

        $this->assertTrue($component->component()->isPlayerLimitReached());
    }

    #[Test]
    public function launchIsRefusedWithALowerAlternativeWhenNotEnoughPlayersButAboveTheMinimum(): void
    {
        $component = $this->creatorWith([
            ['name' => 'Alice', 'empire' => 'hatti'],
            ['name' => 'Bob', 'empire' => 'hellas'],
            ['name' => 'Carol', 'empire' => 'minoa'],
            ['name' => 'Dave', 'empire' => 'egypt'],
            ['name' => 'Eve', 'empire' => 'carthage'],
        ], 8)->component();

        $this->assertFalse($component->canLaunch());
        $this->assertSame(['Add 3 more players, or lower the player count to 5.'], $component->getConformityIssues());
    }

    #[Test]
    public function launchIsRefusedWithoutALowerAlternativeWhenNotEnoughPlayersAndBelowTheMinimum(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('game.playerCount', 9)
            ->component()
        ;

        $this->assertFalse($component->canLaunch());
        $this->assertSame(['Add 9 more players.'], $component->getConformityIssues());
    }

    #[Test]
    public function launchIsRefusedWithARaiseAlternativeWhenTooManyPlayers(): void
    {
        $component = $this->creatorWith([
            ['name' => 'Alice', 'empire' => 'hatti'],
            ['name' => 'Bob', 'empire' => 'hellas'],
            ['name' => 'Carol', 'empire' => 'minoa'],
            ['name' => 'Dave', 'empire' => 'egypt'],
            ['name' => 'Eve', 'empire' => 'assyria'],
        ], 5)->set('game.playerCount', 3)->component();

        $this->assertFalse($component->canLaunch());
        $this->assertContains('Remove 2 players, or raise the player count to 5.', $component->getConformityIssues());
    }

    #[Test]
    public function launchIsAllowedWhenThePlayerCountMatchesTheTarget(): void
    {
        $component = $this->creatorWith(self::westRosterNamed('Alice', 'Bob', 'Carol'))->component();

        $this->assertTrue($component->canLaunch());
        $this->assertSame([], $component->getConformityIssues());
    }

    #[Test]
    public function launchIsRefusedWhenTheSlugIsAlreadyTaken(): void
    {
        GameBuilder::create()->withSlug('taken-slug')->persist($this->entityManager);

        $component = $this->creatorWith(self::westRosterNamed('Alice', 'Bob', 'Carol'), slug: 'taken-slug')->component();

        $this->assertFalse($component->canLaunch());
    }

    #[Test]
    public function launchIsRefusedWhenTheSlugIsReserved(): void
    {
        $component = $this->creatorWith(self::westRosterNamed('Alice', 'Bob', 'Carol'), slug: 'create')->component();

        $this->assertFalse($component->canLaunch());
    }

    #[Test]
    public function launchIsAllowedWhenTheEntireFormIsValid(): void
    {
        $component = $this->creatorWith(self::westRosterNamed('Alice', 'Bob', 'Carol'), slug: 'valid-new-game')->component();

        $this->assertTrue($component->canLaunch());
    }

    #[Test]
    public function addingAPlayerWithoutAnEmpireIsAcceptedWithoutError(): void
    {
        $component = $this->createLiveComponent('GameCreator');
        $this->addPlayer($component, 'Alice', '');

        $this->assertSame([], $this->fieldErrorsOf($component, 'newPlayerName'));
        $this->assertSame([['name' => 'Alice', 'empire' => '']], $component->component()->players);
    }

    #[Test]
    public function assigningAnEmpireToARowThatHadNoneStoresIt(): void
    {
        $component = $this->creatorWith([['name' => 'Alice', 'empire' => '']], 9);

        $component
            ->set('players.0.empire', 'hatti')
            ->call('setEmpire', ['index' => 0])
        ;

        $this->assertSame('hatti', $component->component()->players[0]['empire']);
    }

    #[Test]
    public function assigningADifferentEmpireToARowThatAlreadyHadOneReplacesIt(): void
    {
        $component = $this->creatorWith([['name' => 'Alice', 'empire' => 'hatti']], 9);

        $component
            ->set('players.0.empire', 'rome')
            ->call('setEmpire', ['index' => 0])
        ;

        $this->assertSame('rome', $component->component()->players[0]['empire']);
        $this->assertSame([], $this->takenEmpiresOf($component, 0));
    }

    #[Test]
    public function selectingTheEmptyOptionUnassignsTheRowsEmpire(): void
    {
        $component = $this->creatorWith([['name' => 'Alice', 'empire' => 'hatti']], 9);

        $component
            ->set('players.0.empire', '')
            ->call('setEmpire', ['index' => 0])
        ;

        $this->assertSame('', $component->component()->players[0]['empire']);
        $this->assertContains('1 player still needs an empire.', $component->component()->getConformityIssues());
    }

    #[Test]
    public function settingARowsEmpireToOneOutsideTheCurrentScenarioIsRefused(): void
    {
        $component = $this->creatorWith([['name' => 'Alice', 'empire' => '']], 5);

        $component
            ->set('players.0.empire', 'celt')
            ->call('setEmpire', ['index' => 0])
        ;

        $this->assertSame('', $component->component()->players[0]['empire']);
    }

    #[Test]
    public function settingARowsEmpireToOneAlreadyHeldByAnotherRowIsRefused(): void
    {
        $component = $this->creatorWith([
            ['name' => 'Alice', 'empire' => 'hatti'],
            ['name' => 'Bob', 'empire' => ''],
        ], 9);

        $component
            ->set('players.1.empire', 'hatti')
            ->call('setEmpire', ['index' => 1])
        ;

        $this->assertSame('', $component->component()->players[1]['empire']);
        $this->assertSame('hatti', $component->component()->players[0]['empire']);
    }

    #[Test]
    public function anAssignedEmpireIsFlaggedTakenOnTheOtherRowsAndLeftFreeOnItsOwn(): void
    {
        $component = $this->creatorWith([
            ['name' => 'Alice', 'empire' => ''],
            ['name' => 'Bob', 'empire' => ''],
        ], 9);

        $component
            ->set('players.0.empire', 'hatti')
            ->call('setEmpire', ['index' => 0])
        ;

        $this->assertSame(['hatti'], $this->takenEmpiresOf($component, 1));
        $this->assertSame([], $this->takenEmpiresOf($component, 0));
    }

    #[Test]
    public function everyRowKeepsTheScenariosOwnEmpireSequenceWhateverIsAssigned(): void
    {
        $component = $this->creatorWith([
            ['name' => 'Alice', 'empire' => ''],
            ['name' => 'Bob', 'empire' => ''],
        ], 9);

        $component
            ->set('players.0.empire', 'hatti')
            ->call('setEmpire', ['index' => 0])
        ;

        $this->assertSame(self::WEST_NINE_EMPIRES, $this->empireChoicesOf($component, 0));
        $this->assertSame(self::WEST_NINE_EMPIRES, $this->empireChoicesOf($component, 1));
    }

    #[Test]
    public function settingTheEmpireOfARowThatDoesNotExistChangesNothing(): void
    {
        $component = $this->creatorWith([['name' => 'Alice', 'empire' => 'hatti']], 9);

        $component->call('setEmpire', ['index' => 5]);

        $this->assertSame([['name' => 'Alice', 'empire' => 'hatti']], $component->component()->players);
    }

    #[Test]
    public function assigningARandomEmpireOnAnEmptyRowPicksAScenarioEmpireNotAlreadyTaken(): void
    {
        $component = $this->creatorWith([
            ['name' => 'Alice', 'empire' => 'hatti'],
            ['name' => 'Bob', 'empire' => ''],
        ], 9);

        $component->call('assignRandomEmpire', ['index' => 1]);

        $scenarioEmpires = self::getContainer()->get(ScenarioRegistry::class)->find(9, Region::West)->empires;
        $players = $component->component()->players;

        $this->assertContains($players[1]['empire'], $scenarioEmpires);
        $this->assertNotSame('hatti', $players[1]['empire']);
    }

    #[Test]
    public function assigningARandomEmpireIsDeterministicWhenOnlyOneEmpireRemains(): void
    {
        $component = $this->creatorWith([
            ['name' => 'Alice', 'empire' => 'assyria'],
            ['name' => 'Bob', 'empire' => 'carthage'],
            ['name' => 'Carol', 'empire' => 'celt'],
            ['name' => 'Dave', 'empire' => 'egypt'],
            ['name' => 'Eve', 'empire' => 'hatti'],
            ['name' => 'Frank', 'empire' => 'hellas'],
            ['name' => 'Grace', 'empire' => 'iberia'],
            ['name' => 'Heidi', 'empire' => 'rome'],
            ['name' => 'Ivan', 'empire' => ''],
        ], 9);

        $component->call('assignRandomEmpire', ['index' => 8]);

        $this->assertSame('minoa', $component->component()->players[8]['empire']);
    }

    #[Test]
    public function assigningRandomEmpiresFillsAllEmptyRowsFromTheScenarioWithoutDuplicates(): void
    {
        $component = $this->creatorWith([
            ['name' => 'Alice', 'empire' => ''],
            ['name' => 'Bob', 'empire' => ''],
            ['name' => 'Carol', 'empire' => ''],
        ]);

        $component->call('assignRandomEmpires');

        $scenarioEmpires = self::getContainer()->get(ScenarioRegistry::class)->find(3, Region::West)->empires;
        $assignedEmpires = array_column($component->component()->players, 'empire');

        $this->assertNotContains('', $assignedEmpires);
        $this->assertCount(3, array_intersect($assignedEmpires, $scenarioEmpires));
        $this->assertCount(3, array_unique($assignedEmpires));
    }

    #[Test]
    public function changingRegionAfterAssignmentInvalidatesTheEmpireAndRefusesLaunch(): void
    {
        $component = $this->creatorWith([['name' => 'Alice', 'empire' => 'kushan']], 9, 'east')
            ->set('game.region', 'west')
            ->component()
        ;

        $this->assertFalse($component->canLaunch());
        $this->assertContains('Alice\'s empire "kushan" is not part of the current scenario.', $component->getConformityIssues());
    }

    #[Test]
    public function duplicateEmpiresAcrossPlayersAreReportedAsAConformityIssue(): void
    {
        $component = $this->creatorWith([
            ['name' => 'Alice', 'empire' => 'hatti'],
            ['name' => 'Bob', 'empire' => 'hatti'],
        ], 9)->component();

        $this->assertFalse($component->canLaunch());
        $this->assertContains('Alice and Bob share the empire "hatti".', $component->getConformityIssues());
    }

    #[Test]
    #[DataProvider('provideNamesFoldingToTheSameSlugAreReportedAsAConformityIssueCases')]
    public function namesFoldingToTheSameSlugAreReportedAsAConformityIssue(string $first, string $second, string $sharedSlug): void
    {
        $component = $this->creatorWith(self::westRosterNamed($first, $second, 'Carol'))->component();

        $this->assertFalse($component->canLaunch());
        $this->assertContains(sprintf('%s and %s share the name "%s".', $first, $second, $sharedSlug), $component->getConformityIssues());
    }

    public static function provideNamesFoldingToTheSameSlugAreReportedAsAConformityIssueCases(): iterable
    {
        yield 'case alone separates them' => ['Bob', 'BOB', 'bob'];

        yield 'a space where the other has a hyphen' => ['Jean-Luc', 'Jean Luc', 'jean-luc'];

        yield 'an accent the slugger folds away' => ['René', 'Rene', 'rene'];
    }

    #[Test]
    public function namesFoldingToDistinctSlugsAreNotReportedAsAConformityIssue(): void
    {
        $component = $this->creatorWith(self::westRosterNamed('Bob', 'Bobby', 'Carol'), slug: 'distinct-names-game')->component();

        $this->assertTrue($component->canLaunch());
        $this->assertSame([], $component->getConformityIssues());
    }

    #[Test]
    public function theSlugTheCreatorsGateComparesIsTheSlugTheColumnStores(): void
    {
        $limitHan = str_repeat('漢', Player::MAX_NAME_LENGTH);

        $component = $this->creatorWith(self::westRosterNamed($limitHan, 'Bob', 'Carol'), slug: 'one-slugifier-launch');
        $component->call('launch');

        $this->assertSame(Response::HTTP_FOUND, $component->response()->getStatusCode(), (string) $component->response()->getContent());

        $stored = $this->freshEntityManager()->getRepository(Player::class)->findOneBy(['name' => $limitHan]);

        $this->assertInstanceOf(Player::class, $stored);
        $this->assertSame(Player::slugify($limitHan), $stored->slug);
        $this->assertSame(self::TRUNCATED_HAN_SLUG, $stored->slug);
        $this->assertSame(Player::MAX_NAME_LENGTH, mb_strlen($stored->slug));
    }

    #[Test]
    #[DataProvider('provideANameThatSlugifiesToNothingIsReportedAsAConformityIssueCases')]
    public function aNameThatSlugifiesToNothingIsReportedAsAConformityIssue(string $unusableName): void
    {
        $component = $this->creatorWith(self::westRosterNamed($unusableName, 'Bob', 'Carol'), slug: 'unusable-name-game')->component();

        $this->assertFalse($component->canLaunch());
        $this->assertSame(['1 player has no usable name.'], $component->getConformityIssues());
    }

    public static function provideANameThatSlugifiesToNothingIsReportedAsAConformityIssueCases(): iterable
    {
        yield 'nothing at all' => [''];

        yield 'whitespace only' => ['   '];

        yield 'punctuation only' => ['!!!'];

        yield 'a row of hyphens' => ['---'];
    }

    #[Test]
    public function twoBlankNamesAreReportedAsUnusableRatherThanAsASharedName(): void
    {
        $component = $this->creatorWith(self::westRosterNamed('', '  ', 'Carol'), slug: 'two-blank-names-game')->component();

        $this->assertSame(['2 players have no usable name.'], $component->getConformityIssues());
    }

    /** @param list<string> $expectedRemainingIssues */
    #[Test]
    #[DataProvider('provideAPlayerCountOutsideTheAllowedRangeIsReportedAsAConformityIssueCases')]
    public function aPlayerCountOutsideTheAllowedRangeIsReportedAsAConformityIssue(int $playerCount, array $expectedRemainingIssues): void
    {
        $issues = $this->creatorWith([], $playerCount, slug: 'out-of-range-count')
            ->component()
            ->getConformityIssues()
        ;

        $this->assertCount(\count($expectedRemainingIssues) + 1, $issues);
        $this->assertSame($expectedRemainingIssues, \array_slice($issues, 1));
    }

    public static function provideAPlayerCountOutsideTheAllowedRangeIsReportedAsAConformityIssueCases(): iterable
    {
        yield 'no players at all, matched by a count of zero' => [0, []];

        yield 'one below the minimum' => [2, ['Add 2 more players.']];

        yield 'one above the maximum' => [19, ['Add 19 more players.']];
    }

    #[Test]
    #[DataProvider('provideAPlayerCountAtTheEdgeOfTheAllowedRangeRaisesNoCountIssueCases')]
    public function aPlayerCountAtTheEdgeOfTheAllowedRangeRaisesNoCountIssue(int $playerCount): void
    {
        $issues = $this->creatorWith([], $playerCount, slug: 'edge-of-range-count')
            ->component()
            ->getConformityIssues()
        ;

        $this->assertSame([sprintf('Add %d more players.', $playerCount)], $issues);
    }

    public static function provideAPlayerCountAtTheEdgeOfTheAllowedRangeRaisesNoCountIssueCases(): iterable
    {
        yield 'the minimum' => [3];

        yield 'the maximum' => [18];
    }

    #[Test]
    #[DataProvider('provideANameAtTheLengthLimitIsAcceptedByAddPlayerCases')]
    public function aNameAtTheLengthLimitIsAcceptedByAddPlayer(string $name, string $expectedStoredName): void
    {
        $component = $this->createLiveComponent('GameCreator');
        $this->addPlayer($component, $name, 'hatti');

        $this->assertSame([], $this->fieldErrorsOf($component, 'newPlayerName'));
        $this->assertSame([['name' => $expectedStoredName, 'empire' => 'hatti']], $component->component()->players);
    }

    public static function provideANameAtTheLengthLimitIsAcceptedByAddPlayerCases(): iterable
    {
        yield 'twenty ascii characters' => [str_repeat('a', Player::MAX_NAME_LENGTH), 'A'.str_repeat('a', Player::MAX_NAME_LENGTH - 1)];

        yield 'twenty accented characters, forty bytes' => [str_repeat('é', Player::MAX_NAME_LENGTH), str_repeat('é', Player::MAX_NAME_LENGTH)];
    }

    #[Test]
    public function aNameOneCharacterOverTheLengthLimitIsRefusedByAddPlayer(): void
    {
        $component = $this->createLiveComponent('GameCreator');
        $this->addPlayer($component, str_repeat('a', Player::MAX_NAME_LENGTH + 1), 'hatti');

        $this->assertSame(['Name cannot be longer than 20 characters.'], $this->fieldErrorsOf($component, 'newPlayerName'));
        $this->assertSame([], $component->component()->players);
    }

    #[Test]
    public function anOverlongNameAlreadyOnTheRosterReportsItsLengthRatherThanTheCollision(): void
    {
        $overlongName = str_repeat('a', Player::MAX_NAME_LENGTH + 1);

        $component = $this->createLiveComponent('GameCreator', ['players' => [['name' => $overlongName, 'empire' => 'hatti']]]);
        $this->addPlayer($component, $overlongName, 'hellas');

        $this->assertSame(['Name cannot be longer than 20 characters.'], $this->fieldErrorsOf($component, 'newPlayerName'));
    }

    #[Test]
    public function anInjectedNameOverTheLengthLimitIsReportedAsAConformityIssue(): void
    {
        $overlongName = str_repeat('a', Player::MAX_NAME_LENGTH + 1);

        $component = $this->creatorWith(self::westRosterNamed($overlongName, 'Bob', 'Carol'), slug: 'overlong-injected-name')->component();

        $this->assertFalse($component->canLaunch());
        $this->assertCount(1, $component->getConformityIssues());
    }

    #[Test]
    public function aGameNameOneCharacterOverTheLengthLimitIsReportedAndRefusesLaunch(): void
    {
        $component = $this->creatorWith(self::westRosterNamed('Alice', 'Bob', 'Carol'))
            ->set('game.slug', str_repeat('a', Game::MAX_SLUG_LENGTH + 1))
            ->component()
        ;

        $this->assertSame('The address this name builds is longer than 64 characters.', (string) $component->getError('game.slug')?->getMessage());
        $this->assertFalse($component->canLaunch());
    }

    /** @param list<array{name: string, empire: string}> $players */
    private function creatorWith(array $players, int $playerCount = 3, ?string $region = 'west', string $slug = 'a-game'): TestLiveComponent
    {
        $game = new CreateGame();
        $game->slug = $slug;
        $game->playerCount = $playerCount;
        $game->region = $region;

        return $this->createLiveComponent('GameCreator', ['game' => $game, 'players' => $players]);
    }

    private function addPlayer(TestLiveComponent $component, string $name, string $empire): void
    {
        $component
            ->set('newPlayerName', $name)
            ->set('newPlayerEmpire', $empire)
        ;
        $component->call('addPlayer');
    }

    /** @return list<string> */
    private function fieldErrorsOf(TestLiveComponent $component, string $field): array
    {
        return array_values($component->component()->getErrors($field));
    }

    /** @return list<string> */
    private function empireChoicesOf(TestLiveComponent $component, int $index): array
    {
        return array_column($component->component()->getEmpireChoicesFor($index), 'empire');
    }

    /** @return list<string> */
    private function takenEmpiresOf(TestLiveComponent $component, int $index): array
    {
        return array_values(array_column(
            array_filter(
                $component->component()->getEmpireChoicesFor($index),
                static fn (array $choice): bool => $choice['taken'],
            ),
            'empire',
        ));
    }

    /** @return list<array{name: string, empire: string}> */
    private static function westRosterNamed(string ...$names): array
    {
        return array_map(
            static fn (string $name, string $empire): array => ['name' => $name, 'empire' => $empire],
            $names,
            \array_slice(['hatti', 'hellas', 'minoa'], 0, \count($names)),
        );
    }

    private function freshEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
