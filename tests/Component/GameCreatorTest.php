<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\State\Game;
use App\State\Player;
use App\State\Region;
use App\State\ASTVersion;
use App\Rules\Action\CreateGame;
use App\Rules\Ruleset\ScenarioRegistry;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\GameFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class GameCreatorTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithLiveComponents;

    /**
     * A row's empire select, option values in render order: the empty option, then the whole
     * 9-player west pool in config/game/scenarios.yaml's own order. Spelled out rather than read
     * back from ScenarioRegistry, so that an implementation reordering the pool — or appending an
     * empire to it — fails here instead of agreeing with itself.
     */
    private const array WEST_NINE_EMPIRE_OPTIONS = ['', 'assyria', 'carthage', 'celt', 'egypt', 'hatti', 'hellas', 'iberia', 'minoa', 'rome'];

    /**
     * The slug both thirty-character CJK names below are stored under. Spelled out rather than read
     * back from Player::slugify(), for the same reason as the empire pool above: an implementation
     * that changes where the cut falls must fail here instead of agreeing with itself.
     */
    private const string TRUNCATED_HAN_SLUG = 'han-han-han-han-han-han-han-ha';

    #[Test]
    public function mountProposesAUuidAsTheDefaultGameSlug(): void
    {
        $component = $this->createLiveComponent('GameCreator');
        $rendered = $component->render();

        $this->assertTrue(Uuid::isValid($component->component()->game->slug));
        $this->assertStringContainsString('value="'.$component->component()->game->slug.'"', $rendered->toString());
    }

    /**
     * The bound is declared once, by the scenarios the ruleset describes — the input carries their
     * ends because a number field cannot carry a set.
     */
    #[Test]
    public function playerCountInputBoundsComeFromTheScenariosThemselves(): void
    {
        $counts = self::getContainer()->get(ScenarioRegistry::class)->playerCounts();

        $rendered = $this->createLiveComponent('GameCreator')->render()->toString();

        $this->assertStringContainsString(sprintf('min="%d" max="%d"', $counts[0], $counts[\count($counts) - 1]), $rendered);
    }

    #[Test]
    public function scenarioSummaryShowsTheCardLimitForTheDefaultPlayerCount(): void
    {
        $rendered = $this->createLiveComponent('GameCreator')->render()->toString();

        $this->assertStringContainsString('Card limit: 8', $rendered);
    }

    #[Test]
    public function scenarioSummaryShowsTheRaisedCardLimitForALargeGame(): void
    {
        $rendered = $this->createLiveComponent('GameCreator')
            ->set('game.playerCount', 12)
            ->render()
            ->toString()
        ;

        $this->assertStringContainsString('Card limit: 9', $rendered);
    }

    #[Test]
    public function settingTheSlugSlugifiesItAndShowsItAsAvailable(): void
    {
        $rendered = $this->createLiveComponent('GameCreator')
            ->set('game.slug', 'Super Game de Nayte')
            ->render()
        ;

        $this->assertStringContainsString('value="super-game-de-nayte"', $rendered->toString());
        $this->assertStringContainsString('aria-label="Slug available"', $rendered->toString());
    }

    #[Test]
    public function slugOfAnExistingGameIsShownAsUnavailable(): void
    {
        GameBuilder::create()->withSlug('taken-slug')->persist($this->entityManager);

        $rendered = $this->createLiveComponent('GameCreator')
            ->set('game.slug', 'Taken Slug')
            ->render()
        ;

        $this->assertStringContainsString('value="taken-slug"', $rendered->toString());
        $this->assertStringContainsString('aria-label="Slug unavailable"', $rendered->toString());
    }

    #[Test]
    public function slugOfAnExistingGameShowsAFieldErrorInRealTime(): void
    {
        GameBuilder::create()->withSlug('taken-slug')->persist($this->entityManager);

        $rendered = $this->createLiveComponent('GameCreator')
            ->set('game.slug', 'Taken Slug')
            ->render()
        ;

        $this->assertStringContainsString('Slug "taken-slug" is not available.', $rendered->crawler()->filter('[data-error="game.slug"]')->text());
    }

    #[Test]
    public function reservedSlugShowsAFieldErrorInRealTime(): void
    {
        $rendered = $this->createLiveComponent('GameCreator')
            ->set('game.slug', 'create')
            ->render()
        ;

        $this->assertStringContainsString('This name is reserved.', $rendered->crawler()->filter('[data-error="game.slug"]')->text());
    }

    #[Test]
    public function settingPlayerCountToTenClearsTheRegion(): void
    {
        $rendered = $this->createLiveComponent('GameCreator')
            ->set('game.playerCount', 10)
            ->render()
        ;

        $select = $rendered->crawler()->filter('select[data-model="game.region"]');
        $options = $select->filter('option');

        $this->assertNotNull($select->attr('disabled'));
        $this->assertCount(1, $options);
        $this->assertSame('West + East', trim($options->text()));
        $this->assertSame('', $options->attr('value'));
        $this->assertNotNull($options->attr('selected'));
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
    public function regionSelectShowsTheChosenRegionAndIsEnabledWhenPlayerCountIsNotAboveNine(): void
    {
        $rendered = $this->createLiveComponent('GameCreator')
            ->set('game.playerCount', 9)
            ->set('game.region', 'west')
            ->render()
        ;

        $select = $rendered->crawler()->filter('select[data-model="game.region"]');
        $options = $select->filter('option');

        $this->assertNull($select->attr('disabled'));
        $this->assertCount(2, $options);
        $this->assertSame(['West', 'East'], $options->each(static fn ($node): string => trim((string) $node->text())));
        $this->assertNotNull($select->filter('option[value="west"]')->attr('selected'));
        $this->assertNull($select->filter('option[value="east"]')->attr('selected'));
    }

    #[Test]
    public function addingPlayersDoesNotPersistAnythingButRendersTheTable(): void
    {
        $gamesBefore = $this->entityManager->getRepository(Game::class)->count([]);
        $playersBefore = $this->entityManager->getRepository(Player::class)->count([]);

        $component = $this->createLiveComponent('GameCreator')
            ->set('newPlayerName', 'Alice')
            ->set('newPlayerEmpire', 'hatti')
        ;
        $component->call('addPlayer');

        $component
            ->set('newPlayerName', 'Bob')
            ->set('newPlayerEmpire', 'rome')
        ;
        $rendered = $component->call('addPlayer')->render();

        $this->assertStringContainsString('Alice', $rendered->toString());
        $this->assertStringContainsString('Bob', $rendered->toString());

        $freshEntityManager = $this->freshEntityManager();
        $this->assertSame($gamesBefore, $freshEntityManager->getRepository(Game::class)->count([]));
        $this->assertSame($playersBefore, $freshEntityManager->getRepository(Player::class)->count([]));
    }

    #[Test]
    public function addingAPlayerWithADuplicateNameSlugIsRefused(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('newPlayerName', 'Alice')
            ->set('newPlayerEmpire', 'hatti')
        ;
        $component->call('addPlayer');

        $component
            ->set('newPlayerName', 'alice')
            ->set('newPlayerEmpire', 'rome')
        ;
        $rendered = $component->call('addPlayer')->render();

        $this->assertStringContainsString('Name already taken.', $rendered->crawler()->filter('[data-error="newPlayerName"]')->text());
        $this->assertSame(1, substr_count($rendered->toString(), '<td>Alice</td>'));
        $this->assertStringNotContainsString('<td>alice</td>', $rendered->toString());
    }

    #[Test]
    public function addingAPlayerWhoseNameOnlyDiffersBySurroundingWhitespaceIsRefused(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('newPlayerName', '  toto  ')
            ->set('newPlayerEmpire', 'hatti')
        ;
        $component->call('addPlayer');

        $component
            ->set('newPlayerName', '  toto  ')
            ->set('newPlayerEmpire', 'rome')
        ;
        $rendered = $component->call('addPlayer')->render();

        $this->assertStringContainsString('Name already taken.', $rendered->crawler()->filter('[data-error="newPlayerName"]')->text());
        $this->assertSame(1, substr_count($rendered->toString(), '<td>Toto</td>'));
    }

    #[Test]
    public function addingAPlayerWithAnEmpireOutsideTheCurrentScenarioIsRefused(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('game.playerCount', 5)
            ->set('newPlayerName', 'Alice')
            ->set('newPlayerEmpire', 'celt')
        ;

        $actionResponse = $component->call('addPlayer')->response()->getContent();

        $this->assertStringContainsString('Empire &quot;celt&quot; is not available.', (string) $actionResponse);
        $this->assertSame([], $component->component()->players);
    }

    #[Test]
    #[DataProvider('provideAddingAPlayerWithABlankNameShowsAFieldErrorCases')]
    public function addingAPlayerWithABlankNameShowsAFieldError(string $unusableName): void
    {
        $rendered = $this->createLiveComponent('GameCreator')
            ->set('newPlayerName', $unusableName)
            ->call('addPlayer')
            ->render()
        ;

        $this->assertStringContainsString('Player name is required.', $rendered->crawler()->filter('[data-error="newPlayerName"]')->text());
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
        $component = $this->createLiveComponent('GameCreator')
            ->set('newPlayerName', '')
        ;
        $component->call('addPlayer');

        $this->assertGreaterThan(0, $component->render()->crawler()->filter('[data-error="newPlayerName"]')->count());

        $component
            ->set('newPlayerName', 'Alice')
            ->set('newPlayerEmpire', 'hatti')
        ;
        $rendered = $component->call('addPlayer')->render();

        $this->assertCount(0, $rendered->crawler()->filter('[data-error="newPlayerName"]'));
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
            $component
                ->set('newPlayerName', $name)
                ->set('newPlayerEmpire', $empire)
            ;
            $component->call('addPlayer');
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
    public function slugTakenAtLaunchTimeDisplaysAnErrorAndCreatesNothing(): void
    {
        GameBuilder::create()->withSlug('race-slug')->persist($this->entityManager);

        $gamesBefore = $this->entityManager->getRepository(Game::class)->count([]);

        $component = $this->createLiveComponent('GameCreator')
            ->set('game.slug', 'Race Slug')
            ->set('game.playerCount', 3)
        ;

        foreach ([['Alice', 'hatti'], ['Bob', 'hellas'], ['Carol', 'minoa']] as [$name, $empire]) {
            $component
                ->set('newPlayerName', $name)
                ->set('newPlayerEmpire', $empire)
            ;
            $component->call('addPlayer');
        }

        $rendered = $component->call('launch')->render();

        $this->assertStringContainsString('is not available', $rendered->crawler()->filter('[data-error="game.slug"]')->text());

        $freshEntityManager = $this->freshEntityManager();
        $this->assertSame($gamesBefore, $freshEntityManager->getRepository(Game::class)->count([]));
    }

    #[Test]
    public function launchWithTheReservedCreateSlugDisplaysAnErrorAndCreatesNothing(): void
    {
        $gamesBefore = $this->entityManager->getRepository(Game::class)->count([]);

        $component = $this->createLiveComponent('GameCreator')
            ->set('game.slug', 'create')
            ->set('game.playerCount', 3)
        ;

        foreach ([['Alice', 'hatti'], ['Bob', 'hellas'], ['Carol', 'minoa']] as [$name, $empire]) {
            $component
                ->set('newPlayerName', $name)
                ->set('newPlayerEmpire', $empire)
            ;
            $component->call('addPlayer');
        }

        $rendered = $component->call('launch')->render();

        $this->assertStringContainsString('This name is reserved.', $rendered->crawler()->filter('[data-error="game.slug"]')->text());

        $freshEntityManager = $this->freshEntityManager();
        $this->assertSame($gamesBefore, $freshEntityManager->getRepository(Game::class)->count([]));
    }

    #[Test]
    public function addPlayerRowIsDisabledAndAddPlayerIsRefusedWhenThePlayerLimitIsReached(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('game.playerCount', 3)
        ;

        foreach ([['Alice', 'hatti'], ['Bob', 'hellas'], ['Carol', 'minoa']] as [$name, $empire]) {
            $component
                ->set('newPlayerName', $name)
                ->set('newPlayerEmpire', $empire)
            ;
            $component->call('addPlayer');
        }

        $rendered = $component->render()->toString();

        $this->assertStringContainsString('data-model="norender|newPlayerName" value="" disabled', $rendered);
        $this->assertStringContainsString('data-model="newPlayerEmpire" disabled', $rendered);
        $this->assertStringContainsString('Player limit reached (3/3).', $rendered);

        $component
            ->set('newPlayerName', 'Dave')
            ->set('newPlayerEmpire', 'egypt')
        ;
        $rendered = $component->call('addPlayer')->render()->toString();

        $this->assertStringContainsString('Player limit reached (3/3).', $rendered);
        $this->assertStringNotContainsString('<td>Dave</td>', $rendered);
    }

    #[Test]
    public function loweringPlayerCountBelowTheAlreadyAddedPlayersCountDisablesTheAddPlayerRow(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('game.playerCount', 5)
        ;

        foreach ([['Alice', 'hatti'], ['Bob', 'hellas'], ['Carol', 'minoa']] as [$name, $empire]) {
            $component
                ->set('newPlayerName', $name)
                ->set('newPlayerEmpire', $empire)
            ;
            $component->call('addPlayer');
        }

        $rendered = $component->set('game.playerCount', 3)->render()->toString();

        $this->assertStringContainsString('data-model="norender|newPlayerName" value="" disabled', $rendered);
        $this->assertStringContainsString('data-model="newPlayerEmpire" disabled', $rendered);
        $this->assertStringContainsString('Player limit reached (3/3).', $rendered);
    }

    #[Test]
    public function launchIsDisabledWithLowerAlternativeWhenNotEnoughPlayersButAboveTheMinimum(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('game.playerCount', 8)
        ;

        foreach ([['Alice', 'hatti'], ['Bob', 'hellas'], ['Carol', 'minoa'], ['Dave', 'egypt'], ['Eve', 'carthage']] as [$name, $empire]) {
            $component
                ->set('newPlayerName', $name)
                ->set('newPlayerEmpire', $empire)
            ;
            $component->call('addPlayer');
        }

        $rendered = $component->render()->toString();

        $this->assertTrue($this->isLaunchButtonDisabled($rendered));
        $this->assertStringContainsString('data-conformity="error"', $rendered);
        $this->assertStringContainsString('Add 3 more players, or lower the player count to 5.', $rendered);
    }

    #[Test]
    public function launchIsDisabledWithoutLowerAlternativeWhenNotEnoughPlayersAndBelowTheMinimum(): void
    {
        $rendered = $this->createLiveComponent('GameCreator')
            ->set('game.playerCount', 9)
            ->render()
            ->toString()
        ;

        $this->assertTrue($this->isLaunchButtonDisabled($rendered));
        $this->assertStringContainsString('data-conformity="error"', $rendered);
        $this->assertStringContainsString('Add 9 more players.', $rendered);
        $this->assertStringNotContainsString('or lower the player count', $rendered);
    }

    #[Test]
    public function launchIsDisabledWithRaiseAlternativeWhenTooManyPlayers(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('game.playerCount', 5)
        ;

        foreach ([['Alice', 'hatti'], ['Bob', 'hellas'], ['Carol', 'minoa'], ['Dave', 'egypt'], ['Eve', 'assyria']] as [$name, $empire]) {
            $component
                ->set('newPlayerName', $name)
                ->set('newPlayerEmpire', $empire)
            ;
            $component->call('addPlayer');
        }

        $rendered = $component->set('game.playerCount', 3)->render()->toString();

        $this->assertTrue($this->isLaunchButtonDisabled($rendered));
        $this->assertStringContainsString('data-conformity="error"', $rendered);
        $this->assertStringContainsString('Remove 2 players, or raise the player count to 5.', $rendered);
    }

    #[Test]
    public function launchIsActiveAndShowsNoMismatchMessageWhenPlayerCountMatchesTarget(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('game.playerCount', 3)
        ;

        foreach ([['Alice', 'hatti'], ['Bob', 'hellas'], ['Carol', 'minoa']] as [$name, $empire]) {
            $component
                ->set('newPlayerName', $name)
                ->set('newPlayerEmpire', $empire)
            ;
            $component->call('addPlayer');
        }

        $rendered = $component->render()->toString();

        $this->assertFalse($this->isLaunchButtonDisabled($rendered));
        $this->assertStringContainsString('data-conformity="ok"', $rendered);
        $this->assertStringContainsString('Everything is fine.', $rendered);
    }

    #[Test]
    public function createButtonIsDisabledWhenTheSlugIsAlreadyTaken(): void
    {
        GameBuilder::create()->withSlug('taken-slug')->persist($this->entityManager);

        $component = $this->createLiveComponent('GameCreator')
            ->set('game.slug', 'Taken Slug')
            ->set('game.playerCount', 3)
        ;

        foreach ([['Alice', 'hatti'], ['Bob', 'hellas'], ['Carol', 'minoa']] as [$name, $empire]) {
            $component
                ->set('newPlayerName', $name)
                ->set('newPlayerEmpire', $empire)
            ;
            $component->call('addPlayer');
        }

        $rendered = $component->render()->toString();

        $this->assertTrue($this->isLaunchButtonDisabled($rendered));
    }

    #[Test]
    public function createButtonIsDisabledWhenTheSlugIsReserved(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('game.slug', 'create')
            ->set('game.playerCount', 3)
        ;

        foreach ([['Alice', 'hatti'], ['Bob', 'hellas'], ['Carol', 'minoa']] as [$name, $empire]) {
            $component
                ->set('newPlayerName', $name)
                ->set('newPlayerEmpire', $empire)
            ;
            $component->call('addPlayer');
        }

        $rendered = $component->render()->toString();

        $this->assertTrue($this->isLaunchButtonDisabled($rendered));
    }

    #[Test]
    public function createButtonIsEnabledWhenTheEntireFormIsValid(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('game.slug', 'Valid New Game')
            ->set('game.playerCount', 3)
        ;

        foreach ([['Alice', 'hatti'], ['Bob', 'hellas'], ['Carol', 'minoa']] as [$name, $empire]) {
            $component
                ->set('newPlayerName', $name)
                ->set('newPlayerEmpire', $empire)
            ;
            $component->call('addPlayer');
        }

        $rendered = $component->render()->toString();

        $this->assertFalse($this->isLaunchButtonDisabled($rendered));
    }

    #[Test]
    public function launchIsRefusedServerSideWhenPlayerCountMismatchesAndNothingIsCreated(): void
    {
        $gamesBefore = $this->entityManager->getRepository(Game::class)->count([]);
        $playersBefore = $this->entityManager->getRepository(Player::class)->count([]);

        $component = $this->createLiveComponent('GameCreator')
            ->set('game.slug', 'Mismatch Game')
            ->set('game.playerCount', 9)
        ;

        $rendered = $component->call('launch')->render();

        $this->assertStringContainsString('data-conformity="error"', $rendered->toString());
        $this->assertStringContainsString('Add 9 more players.', $rendered->toString());

        $freshEntityManager = $this->freshEntityManager();
        $this->assertSame($gamesBefore, $freshEntityManager->getRepository(Game::class)->count([]));
        $this->assertSame($playersBefore, $freshEntityManager->getRepository(Player::class)->count([]));
    }

    /**
     * The dash is no longer a text node: the empire cell is a select, and "no empire" is its empty
     * option. The row is therefore read as "exactly one option is selected, it carries no value,
     * and it is the placeholder" — a cell rendering nothing, or silently pre-picking an empire,
     * fails all three.
     */
    #[Test]
    public function addingAPlayerWithoutAnEmpireShowsADashAndNoError(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('newPlayerName', 'Alice')
            ->set('newPlayerEmpire', '')
        ;
        $rendered = $component->call('addPlayer')->render();

        $this->assertStringNotContainsString('data-error', $rendered->toString());

        $row = $rendered->crawler()->filter('tbody tr');
        $this->assertSame('Alice', trim($row->filter('td')->first()->text()));

        $selected = $row->filter('select option[selected]');
        $this->assertCount(1, $selected);
        $this->assertSame('', $selected->attr('value'));
        $this->assertSame('— no empire —', trim($selected->text()));
    }

    #[Test]
    public function assigningAnEmpireToARowThatHadNoneSelectsItInThatRowsSelect(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('game.playerCount', 9)
            ->set('game.region', 'west')
            ->set('newPlayerName', 'Alice')
            ->set('newPlayerEmpire', '')
        ;
        $component->call('addPlayer');

        $rendered = $component
            ->set('players.0.empire', 'hatti')
            ->call('setEmpire', ['index' => 0])
            ->render()
        ;

        $this->assertSame('hatti', $component->component()->players[0]['empire']);
        $this->assertSame('hatti', $this->rowSelectedEmpire($rendered->crawler(), 0));
    }

    #[Test]
    public function assigningADifferentEmpireToARowThatAlreadyHadOneReplacesIt(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('game.playerCount', 9)
            ->set('game.region', 'west')
            ->set('newPlayerName', 'Alice')
            ->set('newPlayerEmpire', 'hatti')
        ;
        $component->call('addPlayer');

        $rendered = $component
            ->set('players.0.empire', 'rome')
            ->call('setEmpire', ['index' => 0])
            ->render()
        ;

        $this->assertSame('rome', $component->component()->players[0]['empire']);
        $this->assertSame('rome', $this->rowSelectedEmpire($rendered->crawler(), 0));
        $this->assertContains('hatti', $this->rowEmpireChoices($rendered->crawler(), 0));
    }

    #[Test]
    public function selectingTheEmptyOptionUnassignsTheRowsEmpire(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('game.playerCount', 9)
            ->set('game.region', 'west')
            ->set('newPlayerName', 'Alice')
            ->set('newPlayerEmpire', 'hatti')
        ;
        $component->call('addPlayer');

        $rendered = $component
            ->set('players.0.empire', '')
            ->call('setEmpire', ['index' => 0])
            ->render()
        ;

        $this->assertSame('', $component->component()->players[0]['empire']);
        $this->assertSame('', $this->rowSelectedEmpire($rendered->crawler(), 0));
        $this->assertStringContainsString('1 player still needs an empire.', $rendered->toString());
    }

    #[Test]
    public function settingARowsEmpireToOneOutsideTheCurrentScenarioIsRefused(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('game.playerCount', 5)
            ->set('newPlayerName', 'Alice')
            ->set('newPlayerEmpire', '')
        ;
        $component->call('addPlayer');

        $actionResponse = $component
            ->set('players.0.empire', 'celt')
            ->call('setEmpire', ['index' => 0])
            ->response()
            ->getContent()
        ;

        $this->assertStringContainsString('Empire &quot;celt&quot; is not available.', (string) $actionResponse);
        $this->assertSame('', $component->component()->players[0]['empire']);
    }

    #[Test]
    public function settingARowsEmpireToOneAlreadyHeldByAnotherRowIsRefused(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('game.playerCount', 9)
            ->set('game.region', 'west')
        ;

        foreach ([['Alice', 'hatti'], ['Bob', '']] as [$name, $empire]) {
            $component->set('newPlayerName', $name)->set('newPlayerEmpire', $empire);
            $component->call('addPlayer');
        }

        $actionResponse = $component
            ->set('players.1.empire', 'hatti')
            ->call('setEmpire', ['index' => 1])
            ->response()
            ->getContent()
        ;

        $this->assertStringContainsString('Empire &quot;hatti&quot; is not available.', (string) $actionResponse);
        $this->assertSame('', $component->component()->players[1]['empire']);
        $this->assertSame('hatti', $component->component()->players[0]['empire']);
    }

    #[Test]
    public function assigningAnEmpireFlagsItAsTakenOnTheOtherRowsWhileLeavingItSelectableOnItsOwn(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('game.playerCount', 9)
            ->set('game.region', 'west')
        ;

        foreach (['Alice', 'Bob'] as $name) {
            $component->set('newPlayerName', $name)->set('newPlayerEmpire', '');
            $component->call('addPlayer');
        }

        $crawler = $component
            ->set('players.0.empire', 'hatti')
            ->call('setEmpire', ['index' => 0])
            ->render()
            ->crawler()
        ;

        $this->assertContains('hatti', $this->rowEmpireChoices($crawler, 1));
        $this->assertSame(['hatti'], $this->rowTakenEmpires($crawler, 1));
        $this->assertSame([], $this->rowTakenEmpires($crawler, 0));
        $this->assertSame('hatti', $this->rowSelectedEmpire($crawler, 0));
    }

    #[Test]
    public function assigningAnEmpireOnOneRowLeavesEveryRowsOptionSequenceUnchanged(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('game.playerCount', 9)
            ->set('game.region', 'west')
        ;

        foreach (['Alice', 'Bob'] as $name) {
            $component->set('newPlayerName', $name)->set('newPlayerEmpire', '');
            $component->call('addPlayer');
        }

        $beforeAssignment = $component->render()->crawler();
        $ownRowSequence = $this->rowEmpireChoices($beforeAssignment, 0);
        $otherRowSequence = $this->rowEmpireChoices($beforeAssignment, 1);

        $afterAssignment = $component
            ->set('players.0.empire', 'hatti')
            ->call('setEmpire', ['index' => 0])
            ->render()
            ->crawler()
        ;

        $this->assertSame(self::WEST_NINE_EMPIRE_OPTIONS, $ownRowSequence);
        $this->assertSame($ownRowSequence, $this->rowEmpireChoices($afterAssignment, 0));
        $this->assertSame($otherRowSequence, $this->rowEmpireChoices($afterAssignment, 1));
    }

    #[Test]
    public function aRowsOwnEmpireKeepsItsPlaceInTheScenarioOrderInsteadOfBeingAppendedLast(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('game.playerCount', 9)
            ->set('game.region', 'west')
            ->set('newPlayerName', 'Alice')
            ->set('newPlayerEmpire', 'hatti')
        ;

        $crawler = $component->call('addPlayer')->render()->crawler();

        $this->assertSame(self::WEST_NINE_EMPIRE_OPTIONS, $this->rowEmpireChoices($crawler, 0));
        $this->assertSame('hatti', $this->rowSelectedEmpire($crawler, 0));
    }

    #[Test]
    public function eachPlayerRowBindsItsEmpireSelectToItsOwnRow(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('game.playerCount', 9)
            ->set('game.region', 'west')
        ;

        foreach ([['Alice', 'hatti'], ['Bob', 'rome'], ['Carol', '']] as [$name, $empire]) {
            $component->set('newPlayerName', $name)->set('newPlayerEmpire', $empire);
            $component->call('addPlayer');
        }

        $selects = $component->render()->crawler()->filter('tbody tr select');

        $this->assertSame(
            ['players.0.empire', 'players.1.empire', 'players.2.empire'],
            $selects->each(static fn (Crawler $select): string => (string) $select->attr('data-model')),
        );
        $this->assertSame(
            ['0', '1', '2'],
            $selects->each(static fn (Crawler $select): string => (string) $select->attr('data-live-index-param')),
        );
    }

    #[Test]
    public function settingTheEmpireOfARowThatDoesNotExistChangesNothing(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('game.playerCount', 9)
            ->set('game.region', 'west')
            ->set('newPlayerName', 'Alice')
            ->set('newPlayerEmpire', 'hatti')
        ;
        $component->call('addPlayer');

        $component->call('setEmpire', ['index' => 5]);

        $this->assertSame([['name' => 'Alice', 'empire' => 'hatti']], $component->component()->players);
    }

    #[Test]
    public function assigningARandomEmpireOnAnEmptyRowPicksAScenarioEmpireNotAlreadyTakenAndHidesTheDice(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('game.playerCount', 9)
            ->set('game.region', 'west')
        ;

        $component->set('newPlayerName', 'Alice')->set('newPlayerEmpire', 'hatti');
        $component->call('addPlayer');
        $component->set('newPlayerName', 'Bob')->set('newPlayerEmpire', '');
        $component->call('addPlayer');

        $rendered = $component->call('assignRandomEmpire', ['index' => 1])->render()->toString();

        $scenarioEmpires = self::getContainer()->get(ScenarioRegistry::class)->find(9, Region::West)->empires;
        $players = $component->component()->players;

        $this->assertNotSame('', $players[1]['empire']);
        $this->assertContains($players[1]['empire'], $scenarioEmpires);
        $this->assertNotSame('hatti', $players[1]['empire']);
        $this->assertStringNotContainsString('data-live-action-param="assignRandomEmpire"', $rendered);
    }

    #[Test]
    public function assigningARandomEmpireIsDeterministicWhenOnlyOneEmpireRemains(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('game.playerCount', 9)
            ->set('game.region', 'west')
        ;

        foreach ([
            ['Alice', 'assyria'],
            ['Bob', 'carthage'],
            ['Carol', 'celt'],
            ['Dave', 'egypt'],
            ['Eve', 'hatti'],
            ['Frank', 'hellas'],
            ['Grace', 'iberia'],
            ['Heidi', 'rome'],
        ] as [$name, $empire]) {
            $component->set('newPlayerName', $name)->set('newPlayerEmpire', $empire);
            $component->call('addPlayer');
        }

        $component->set('newPlayerName', 'Ivan')->set('newPlayerEmpire', '');
        $component->call('addPlayer');

        $component->call('assignRandomEmpire', ['index' => 8]);

        $this->assertSame('minoa', $component->component()->players[8]['empire']);
    }

    #[Test]
    public function assigningRandomEmpiresFillsAllEmptyRowsFromTheScenarioWithoutDuplicates(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('game.playerCount', 3)
            ->set('game.region', 'west')
        ;

        foreach (['Alice', 'Bob', 'Carol'] as $name) {
            $component->set('newPlayerName', $name)->set('newPlayerEmpire', '');
            $component->call('addPlayer');
        }

        $component->call('assignRandomEmpires');

        $scenarioEmpires = self::getContainer()->get(ScenarioRegistry::class)->find(3, Region::West)->empires;
        $assignedEmpires = array_map(
            static fn (array $player): string => $player['empire'],
            $component->component()->players,
        );

        $this->assertNotContains('', $assignedEmpires);
        $this->assertCount(3, array_intersect($assignedEmpires, $scenarioEmpires));
        $this->assertCount(3, array_unique($assignedEmpires));
    }

    #[Test]
    public function changingRegionAfterAssignmentInvalidatesTheEmpireAndDisablesLaunch(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('game.playerCount', 9)
            ->set('game.region', 'east')
            ->set('newPlayerName', 'Alice')
            ->set('newPlayerEmpire', 'kushan')
        ;
        $component->call('addPlayer');

        $rendered = $component->set('game.region', 'west')->render()->toString();

        $this->assertTrue($this->isLaunchButtonDisabled($rendered));
        $this->assertStringContainsString('data-conformity="error"', $rendered);
        $this->assertStringContainsString('Alice&#039;s empire &quot;kushan&quot; is not part of the current scenario.', $rendered);
    }

    #[Test]
    public function duplicateEmpiresAcrossPlayersAreReportedAsAConformityIssue(): void
    {
        $game = new CreateGame();
        $game->playerCount = 9;
        $game->region = 'west';

        $component = $this->createLiveComponent('GameCreator', [
            'game' => $game,
            'players' => [
                ['name' => 'Alice', 'empire' => 'hatti'],
                ['name' => 'Bob', 'empire' => 'hatti'],
            ],
        ]);

        $rendered = $component->render()->toString();

        $this->assertTrue($this->isLaunchButtonDisabled($rendered));
        $this->assertStringContainsString('data-conformity="error"', $rendered);
        $this->assertStringContainsString('Alice and Bob share the empire &quot;hatti&quot;.', $rendered);
    }

    #[Test]
    #[DataProvider('provideNamesFoldingToTheSameSlugAreReportedAsAConformityIssueCases')]
    public function namesFoldingToTheSameSlugAreReportedAsAConformityIssue(string $first, string $second, string $sharedSlug): void
    {
        $game = new CreateGame();
        $game->slug = 'colliding-names-game';
        $game->playerCount = 3;
        $game->region = 'west';

        $component = $this->createLiveComponent('GameCreator', [
            'game' => $game,
            'players' => [
                ['name' => $first, 'empire' => 'hatti'],
                ['name' => $second, 'empire' => 'hellas'],
                ['name' => 'Carol', 'empire' => 'minoa'],
            ],
        ]);

        $rendered = $component->render()->toString();

        $this->assertTrue($this->isLaunchButtonDisabled($rendered));
        $this->assertStringContainsString('data-conformity="error"', $rendered);
        $this->assertStringContainsString(sprintf('%s and %s share the name &quot;%s&quot;.', $first, $second, $sharedSlug), $rendered);
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
        $game = new CreateGame();
        $game->slug = 'distinct-names-game';
        $game->playerCount = 3;
        $game->region = 'west';

        $component = $this->createLiveComponent('GameCreator', [
            'game' => $game,
            'players' => [
                ['name' => 'Bob', 'empire' => 'hatti'],
                ['name' => 'Bobby', 'empire' => 'hellas'],
                ['name' => 'Carol', 'empire' => 'minoa'],
            ],
        ]);

        $rendered = $component->render()->toString();

        $this->assertFalse($this->isLaunchButtonDisabled($rendered));
        $this->assertStringContainsString('data-conformity="ok"', $rendered);
    }

    #[Test]
    public function launchIsRefusedServerSideWhenTwoNamesFoldToTheSameSlugAndNothingIsCreated(): void
    {
        $gamesBefore = $this->entityManager->getRepository(Game::class)->count([]);
        $playersBefore = $this->entityManager->getRepository(Player::class)->count([]);

        $game = new CreateGame();
        $game->slug = 'colliding-names-launch';
        $game->playerCount = 3;
        $game->region = 'west';

        $component = $this->createLiveComponent('GameCreator', [
            'game' => $game,
            'players' => [
                ['name' => 'Bob', 'empire' => 'hatti'],
                ['name' => 'BOB', 'empire' => 'hellas'],
                ['name' => 'Carol', 'empire' => 'minoa'],
            ],
        ]);

        $rendered = $component->call('launch')->render();

        $this->assertStringContainsString('Bob and BOB share the name &quot;bob&quot;.', $rendered->toString());

        $freshEntityManager = $this->freshEntityManager();
        $this->assertSame($gamesBefore, $freshEntityManager->getRepository(Game::class)->count([]));
        $this->assertSame($playersBefore, $freshEntityManager->getRepository(Player::class)->count([]));
    }

    #[Test]
    public function namesCollidingOnlyOnceTheirSlugsAreTruncatedAreReportedAsAConformityIssue(): void
    {
        $thirtyHan = str_repeat('漢', 30);
        $eightHanThenGuo = str_repeat('漢', 8).str_repeat('国', 22);

        $game = new CreateGame();
        $game->slug = 'truncated-collision-game';
        $game->playerCount = 3;
        $game->region = 'west';

        $component = $this->createLiveComponent('GameCreator', [
            'game' => $game,
            'players' => [
                ['name' => $thirtyHan, 'empire' => 'hatti'],
                ['name' => $eightHanThenGuo, 'empire' => 'hellas'],
                ['name' => 'Carol', 'empire' => 'minoa'],
            ],
        ]);

        $rendered = $component->render()->toString();

        $this->assertTrue($this->isLaunchButtonDisabled($rendered));
        $this->assertStringContainsString('data-conformity="error"', $rendered);
        $this->assertStringContainsString(
            sprintf('%s and %s share the name &quot;%s&quot;.', $thirtyHan, $eightHanThenGuo, self::TRUNCATED_HAN_SLUG),
            $rendered,
        );
    }

    #[Test]
    public function namesWhoseSlugsStillDifferOnceTruncatedAreNotReportedAsAConformityIssue(): void
    {
        $game = new CreateGame();
        $game->slug = 'truncated-distinct-game';
        $game->playerCount = 3;
        $game->region = 'west';

        $component = $this->createLiveComponent('GameCreator', [
            'game' => $game,
            'players' => [
                ['name' => str_repeat('漢', 30), 'empire' => 'hatti'],
                ['name' => str_repeat('国', 30), 'empire' => 'hellas'],
                ['name' => 'Carol', 'empire' => 'minoa'],
            ],
        ]);

        $rendered = $component->render()->toString();

        $this->assertFalse($this->isLaunchButtonDisabled($rendered));
        $this->assertStringContainsString('data-conformity="ok"', $rendered);
    }

    #[Test]
    public function launchIsRefusedServerSideWhenTwoNamesCollideOnlyOnceTruncatedAndNothingIsCreated(): void
    {
        $gamesBefore = $this->entityManager->getRepository(Game::class)->count([]);
        $playersBefore = $this->entityManager->getRepository(Player::class)->count([]);

        $thirtyHan = str_repeat('漢', 30);
        $eightHanThenGuo = str_repeat('漢', 8).str_repeat('国', 22);

        $game = new CreateGame();
        $game->slug = 'truncated-collision-launch';
        $game->playerCount = 3;
        $game->region = 'west';

        $component = $this->createLiveComponent('GameCreator', [
            'game' => $game,
            'players' => [
                ['name' => $thirtyHan, 'empire' => 'hatti'],
                ['name' => $eightHanThenGuo, 'empire' => 'hellas'],
                ['name' => 'Carol', 'empire' => 'minoa'],
            ],
        ]);

        $rendered = $component->call('launch')->render()->toString();

        $this->assertStringContainsString(
            sprintf('%s and %s share the name &quot;%s&quot;.', $thirtyHan, $eightHanThenGuo, self::TRUNCATED_HAN_SLUG),
            $rendered,
        );
        $this->assertStringNotContainsString('Slug &quot;truncated-collision-launch&quot; is not available.', $rendered);

        $freshEntityManager = $this->freshEntityManager();
        $this->assertSame($gamesBefore, $freshEntityManager->getRepository(Game::class)->count([]));
        $this->assertSame($playersBefore, $freshEntityManager->getRepository(Player::class)->count([]));
    }

    #[Test]
    public function theSlugTheCreatorsGateComparesIsTheSlugTheColumnStores(): void
    {
        $thirtyHan = str_repeat('漢', 30);

        $game = new CreateGame();
        $game->slug = 'one-slugifier-launch';
        $game->playerCount = 3;
        $game->region = 'west';

        $component = $this->createLiveComponent('GameCreator', [
            'game' => $game,
            'players' => [
                ['name' => $thirtyHan, 'empire' => 'hatti'],
                ['name' => 'Bob', 'empire' => 'hellas'],
                ['name' => 'Carol', 'empire' => 'minoa'],
            ],
        ]);

        $component->call('launch');

        $this->assertSame(Response::HTTP_FOUND, $component->response()->getStatusCode(), (string) $component->response()->getContent());

        $freshEntityManager = $this->freshEntityManager();
        $stored = $freshEntityManager->getRepository(Player::class)->findOneBy(['name' => $thirtyHan]);

        $this->assertInstanceOf(Player::class, $stored);
        $this->assertSame(Player::slugify($thirtyHan), $stored->slug);
        $this->assertSame(self::TRUNCATED_HAN_SLUG, $stored->slug);
        $this->assertSame(Player::MAX_NAME_LENGTH, mb_strlen($stored->slug));
    }

    #[Test]
    #[DataProvider('provideANameThatSlugifiesToNothingIsReportedAsAConformityIssueCases')]
    public function aNameThatSlugifiesToNothingIsReportedAsAConformityIssue(string $unusableName): void
    {
        $game = new CreateGame();
        $game->slug = 'unusable-name-game';
        $game->playerCount = 3;
        $game->region = 'west';

        $component = $this->createLiveComponent('GameCreator', [
            'game' => $game,
            'players' => [
                ['name' => $unusableName, 'empire' => 'hatti'],
                ['name' => 'Bob', 'empire' => 'hellas'],
                ['name' => 'Carol', 'empire' => 'minoa'],
            ],
        ]);

        $rendered = $component->render()->toString();

        $this->assertTrue($this->isLaunchButtonDisabled($rendered));
        $this->assertStringContainsString('data-conformity="error"', $rendered);
        $this->assertStringContainsString('1 player has no usable name.', $rendered);
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
        $game = new CreateGame();
        $game->slug = 'two-blank-names-game';
        $game->playerCount = 3;
        $game->region = 'west';

        $component = $this->createLiveComponent('GameCreator', [
            'game' => $game,
            'players' => [
                ['name' => '', 'empire' => 'hatti'],
                ['name' => '  ', 'empire' => 'hellas'],
                ['name' => 'Carol', 'empire' => 'minoa'],
            ],
        ]);

        $rendered = $component->render()->toString();

        $this->assertTrue($this->isLaunchButtonDisabled($rendered));
        $this->assertStringContainsString('2 players have no usable name.', $rendered);
        $this->assertStringNotContainsString('share the name', $rendered);
    }

    #[Test]
    public function launchIsRefusedServerSideWhenAPlayerNameIsBlankAndNothingIsCreated(): void
    {
        $gamesBefore = $this->entityManager->getRepository(Game::class)->count([]);
        $playersBefore = $this->entityManager->getRepository(Player::class)->count([]);

        $game = new CreateGame();
        $game->slug = 'blank-name-launch';
        $game->playerCount = 3;
        $game->region = 'west';

        $component = $this->createLiveComponent('GameCreator', [
            'game' => $game,
            'players' => [
                ['name' => '', 'empire' => 'hatti'],
                ['name' => 'Bob', 'empire' => 'hellas'],
                ['name' => 'Carol', 'empire' => 'minoa'],
            ],
        ]);

        $rendered = $component->call('launch')->render();

        $this->assertStringContainsString('1 player has no usable name.', $rendered->toString());

        $freshEntityManager = $this->freshEntityManager();
        $this->assertSame($gamesBefore, $freshEntityManager->getRepository(Game::class)->count([]));
        $this->assertSame($playersBefore, $freshEntityManager->getRepository(Player::class)->count([]));
    }

    #[Test]
    public function launchSucceedsWhenEveryInjectedNameSlugifies(): void
    {
        $game = new CreateGame();
        $game->slug = 'usable-names-launch';
        $game->playerCount = 3;
        $game->region = 'west';

        $component = $this->createLiveComponent('GameCreator', [
            'game' => $game,
            'players' => [
                ['name' => 'Alice', 'empire' => 'hatti'],
                ['name' => 'Bob', 'empire' => 'hellas'],
                ['name' => 'Carol', 'empire' => 'minoa'],
            ],
        ]);

        $component->call('launch');

        $response = $component->response();
        $this->assertSame(Response::HTTP_FOUND, $response->getStatusCode(), (string) $response->getContent());
        $this->assertStringEndsWith('/usable-names-launch', (string) $response->headers->get('Location'));

        $freshEntityManager = $this->freshEntityManager();
        $createdGame = $freshEntityManager->getRepository(Game::class)->findOneBy(['slug' => 'usable-names-launch']);

        $this->assertInstanceOf(Game::class, $createdGame);
        $this->assertCount(3, $freshEntityManager->getRepository(Player::class)->findBy(['game' => $createdGame->id]));
    }

    #[Test]
    public function launchIsRefusedServerSideWhenAPlayerHasNoEmpireEvenIfThePlayerCountMatches(): void
    {
        $gamesBefore = $this->entityManager->getRepository(Game::class)->count([]);
        $playersBefore = $this->entityManager->getRepository(Player::class)->count([]);

        $component = $this->createLiveComponent('GameCreator')
            ->set('game.slug', 'No Empire Game')
            ->set('game.playerCount', 3)
            ->set('game.region', 'west')
        ;

        foreach ([['Alice', 'hatti'], ['Bob', 'hellas'], ['Carol', '']] as [$name, $empire]) {
            $component->set('newPlayerName', $name)->set('newPlayerEmpire', $empire);
            $component->call('addPlayer');
        }

        $rendered = $component->call('launch')->render();

        $this->assertStringContainsString('1 player still needs an empire.', $rendered->toString());

        $freshEntityManager = $this->freshEntityManager();
        $this->assertSame($gamesBefore, $freshEntityManager->getRepository(Game::class)->count([]));
        $this->assertSame($playersBefore, $freshEntityManager->getRepository(Player::class)->count([]));
    }

    /** @param list<string> $expectedRemainingIssues */
    #[Test]
    #[DataProvider('provideAPlayerCountOutsideTheAllowedRangeIsReportedAsAConformityIssueCases')]
    public function aPlayerCountOutsideTheAllowedRangeIsReportedAsAConformityIssue(int $playerCount, array $expectedRemainingIssues): void
    {
        $game = new CreateGame();
        $game->slug = 'out-of-range-count';
        $game->playerCount = $playerCount;
        $game->region = 'west';

        $issues = $this->createLiveComponent('GameCreator', ['game' => $game, 'players' => []])
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
        $game = new CreateGame();
        $game->slug = 'edge-of-range-count';
        $game->playerCount = $playerCount;
        $game->region = 'west';

        $issues = $this->createLiveComponent('GameCreator', ['game' => $game, 'players' => []])
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
    public function launchIsRefusedServerSideWhenThePlayerCountIsZeroAndNothingIsCreated(): void
    {
        $gamesBefore = $this->entityManager->getRepository(Game::class)->count([]);
        $playersBefore = $this->entityManager->getRepository(Player::class)->count([]);

        $game = new CreateGame();
        $game->slug = 'empty-roster-launch';
        $game->playerCount = 0;
        $game->region = 'west';

        $this->createLiveComponent('GameCreator', ['game' => $game, 'players' => []])->call('launch');

        $freshEntityManager = $this->freshEntityManager();
        $this->assertSame($gamesBefore, $freshEntityManager->getRepository(Game::class)->count([]));
        $this->assertSame($playersBefore, $freshEntityManager->getRepository(Player::class)->count([]));
    }

    /**
     * The launch-level positive control for the range rule, taken at the top edge rather than in the
     * comfortable middle: eighteen is the largest roster config/game/scenarios.yaml describes, and a
     * bound compared with the wrong operator would refuse exactly this game and nothing else.
     */
    #[Test]
    public function launchSucceedsWithAFullEighteenPlayerRoster(): void
    {
        $game = new CreateGame();
        $game->slug = 'eighteen-player-launch';
        $game->playerCount = 18;
        $game->region = null;
        $players = array_map(
            static fn (string $empire): array => ['name' => ucfirst($empire).' player', 'empire' => $empire],
            self::getContainer()->get(ScenarioRegistry::class)->find(18, null)->empires,
        );

        $component = $this->createLiveComponent('GameCreator', ['game' => $game, 'players' => $players]);
        $component->call('launch');

        $response = $component->response();
        $this->assertSame(Response::HTTP_FOUND, $response->getStatusCode(), (string) $response->getContent());

        $freshEntityManager = $this->freshEntityManager();
        $createdGame = $freshEntityManager->getRepository(Game::class)->findOneBy(['slug' => 'eighteen-player-launch']);

        $this->assertInstanceOf(Game::class, $createdGame);
        $this->assertCount(18, $freshEntityManager->getRepository(Player::class)->findBy(['game' => $createdGame->id]));
    }

    #[Test]
    #[DataProvider('provideANameAtTheLengthLimitIsAcceptedByAddPlayerCases')]
    public function aNameAtTheLengthLimitIsAcceptedByAddPlayer(string $name, string $expectedStoredName): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('newPlayerName', $name)
            ->set('newPlayerEmpire', 'hatti')
        ;

        $rendered = $component->call('addPlayer')->render();

        $this->assertCount(0, $rendered->crawler()->filter('[data-error="newPlayerName"]'));
        $this->assertSame([['name' => $expectedStoredName, 'empire' => 'hatti']], $component->component()->players);
    }

    public static function provideANameAtTheLengthLimitIsAcceptedByAddPlayerCases(): iterable
    {
        yield 'thirty ascii characters' => [str_repeat('a', Player::MAX_NAME_LENGTH), 'A'.str_repeat('a', Player::MAX_NAME_LENGTH - 1)];

        yield 'thirty accented characters, sixty bytes' => [str_repeat('é', Player::MAX_NAME_LENGTH), str_repeat('é', Player::MAX_NAME_LENGTH)];
    }

    #[Test]
    public function aNameOneCharacterOverTheLengthLimitIsRefusedByAddPlayer(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('newPlayerName', str_repeat('a', Player::MAX_NAME_LENGTH + 1))
            ->set('newPlayerEmpire', 'hatti')
        ;

        $rendered = $component->call('addPlayer')->render();

        $this->assertStringContainsString('Name cannot be longer than 30 characters.', $rendered->crawler()->filter('[data-error="newPlayerName"]')->text());
        $this->assertSame([], $component->component()->players);
    }

    #[Test]
    public function anOverlongNameAlreadyOnTheRosterReportsItsLengthRatherThanTheCollision(): void
    {
        $overlongName = str_repeat('a', Player::MAX_NAME_LENGTH + 1);

        $component = $this->createLiveComponent('GameCreator', ['players' => [['name' => $overlongName, 'empire' => 'hatti']]])
            ->set('newPlayerName', $overlongName)
            ->set('newPlayerEmpire', 'hellas')
        ;

        $rendered = $component->call('addPlayer')->render();

        $errorText = $rendered->crawler()->filter('[data-error="newPlayerName"]')->text();
        $this->assertStringContainsString('Name cannot be longer than 30 characters.', $errorText);
        $this->assertStringNotContainsString('Name already taken.', $errorText);
    }

    #[Test]
    public function anInjectedNameOverTheLengthLimitIsReportedAsAConformityIssue(): void
    {
        $game = new CreateGame();
        $game->slug = 'overlong-injected-name';
        $game->playerCount = 3;
        $game->region = 'west';

        $component = $this->createLiveComponent('GameCreator', [
            'game' => $game,
            'players' => [
                ['name' => str_repeat('a', Player::MAX_NAME_LENGTH + 1), 'empire' => 'hatti'],
                ['name' => 'Bob', 'empire' => 'hellas'],
                ['name' => 'Carol', 'empire' => 'minoa'],
            ],
        ]);

        $rendered = $component->render()->toString();

        $this->assertCount(1, $component->component()->getConformityIssues());
        $this->assertTrue($this->isLaunchButtonDisabled($rendered));
        $this->assertStringContainsString('data-conformity="error"', $rendered);
    }

    #[Test]
    public function launchIsRefusedServerSideWhenAnInjectedNameIsOverlongAndNothingIsCreated(): void
    {
        $gamesBefore = $this->entityManager->getRepository(Game::class)->count([]);
        $playersBefore = $this->entityManager->getRepository(Player::class)->count([]);

        $game = new CreateGame();
        $game->slug = 'overlong-name-launch';
        $game->playerCount = 3;
        $game->region = 'west';

        $this->createLiveComponent('GameCreator', [
            'game' => $game,
            'players' => [
                ['name' => str_repeat('a', Player::MAX_NAME_LENGTH + 1), 'empire' => 'hatti'],
                ['name' => 'Bob', 'empire' => 'hellas'],
                ['name' => 'Carol', 'empire' => 'minoa'],
            ],
        ])->call('launch');

        $freshEntityManager = $this->freshEntityManager();
        $this->assertSame($gamesBefore, $freshEntityManager->getRepository(Game::class)->count([]));
        $this->assertSame($playersBefore, $freshEntityManager->getRepository(Player::class)->count([]));
    }

    #[Test]
    #[DataProvider('provideARosterWhoseLongestNameSitsOnTheLengthLimitLaunchesCases')]
    public function aRosterWhoseLongestNameSitsOnTheLengthLimitLaunches(string $name): void
    {
        $game = new CreateGame();
        $game->slug = 'limit-length-name-launch';
        $game->playerCount = 3;
        $game->region = 'west';

        $component = $this->createLiveComponent('GameCreator', [
            'game' => $game,
            'players' => [
                ['name' => $name, 'empire' => 'hatti'],
                ['name' => 'Bob', 'empire' => 'hellas'],
                ['name' => 'Carol', 'empire' => 'minoa'],
            ],
        ]);
        $component->call('launch');

        $response = $component->response();
        $this->assertSame(Response::HTTP_FOUND, $response->getStatusCode(), (string) $response->getContent());

        $freshEntityManager = $this->freshEntityManager();
        $createdGame = $freshEntityManager->getRepository(Game::class)->findOneBy(['slug' => 'limit-length-name-launch']);

        $this->assertInstanceOf(Game::class, $createdGame);
        $this->assertCount(3, $freshEntityManager->getRepository(Player::class)->findBy(['game' => $createdGame->id]));
    }

    public static function provideARosterWhoseLongestNameSitsOnTheLengthLimitLaunchesCases(): iterable
    {
        yield 'thirty ascii characters' => [str_repeat('a', Player::MAX_NAME_LENGTH)];

        yield 'thirty accented characters, sixty bytes' => [str_repeat('é', Player::MAX_NAME_LENGTH)];
    }

    #[Test]
    public function aGameNameOneCharacterOverTheLengthLimitShowsAFieldErrorAndDisablesLaunch(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('game.playerCount', 3)
            ->set('game.region', 'west')
        ;

        foreach ([['Alice', 'hatti'], ['Bob', 'hellas'], ['Carol', 'minoa']] as [$name, $empire]) {
            $component->set('newPlayerName', $name)->set('newPlayerEmpire', $empire);
            $component->call('addPlayer');
        }

        $rendered = $component->set('game.slug', str_repeat('a', Game::MAX_SLUG_LENGTH + 1))->render();

        $this->assertSame('The address this name builds is longer than 64 characters.', trim($rendered->crawler()->filter('[data-error="game.slug"]')->text()));
        $this->assertTrue($this->isLaunchButtonDisabled($rendered->toString()));
    }

    #[Test]
    public function aGameNameExactlyAtTheLengthLimitIsAcceptedAndLaunches(): void
    {
        $slug = str_repeat('a', Game::MAX_SLUG_LENGTH);

        $component = $this->createLiveComponent('GameCreator')
            ->set('game.playerCount', 3)
            ->set('game.region', 'west')
        ;

        foreach ([['Alice', 'hatti'], ['Bob', 'hellas'], ['Carol', 'minoa']] as [$name, $empire]) {
            $component->set('newPlayerName', $name)->set('newPlayerEmpire', $empire);
            $component->call('addPlayer');
        }

        $component->set('game.slug', $slug);
        $component->call('launch');

        $response = $component->response();
        $this->assertSame(Response::HTTP_FOUND, $response->getStatusCode(), (string) $response->getContent());
        $this->assertStringEndsWith('/'.$slug, (string) $response->headers->get('Location'));

        $createdGame = $this->freshEntityManager()->getRepository(Game::class)->findOneBy(['slug' => $slug]);

        $this->assertInstanceOf(Game::class, $createdGame);
        $this->assertSame(Game::MAX_SLUG_LENGTH, mb_strlen($createdGame->slug));
    }

    #[Test]
    public function launchIsRefusedServerSideWhenAnInjectedGameNameIsOverlongAndNothingIsCreated(): void
    {
        $gamesBefore = $this->entityManager->getRepository(Game::class)->count([]);
        $playersBefore = $this->entityManager->getRepository(Player::class)->count([]);

        $game = new CreateGame();
        $game->slug = str_repeat('a', Game::MAX_SLUG_LENGTH + 1);
        $game->playerCount = 3;
        $game->region = 'west';

        $component = $this->createLiveComponent('GameCreator', [
            'game' => $game,
            'players' => [
                ['name' => 'Alice', 'empire' => 'hatti'],
                ['name' => 'Bob', 'empire' => 'hellas'],
                ['name' => 'Carol', 'empire' => 'minoa'],
            ],
        ]);

        $rendered = $component->call('launch')->render();

        $this->assertSame('The address this name builds is longer than 64 characters.', trim($rendered->crawler()->filter('[data-error="game.slug"]')->text()));

        $freshEntityManager = $this->freshEntityManager();
        $this->assertSame($gamesBefore, $freshEntityManager->getRepository(Game::class)->count([]));
        $this->assertSame($playersBefore, $freshEntityManager->getRepository(Player::class)->count([]));
    }

    private function rowSelectedEmpire(Crawler $crawler, int $index): string
    {
        $selected = $crawler->filter('tbody tr')->eq($index)->filter('select option[selected]');
        $this->assertCount(1, $selected);

        return (string) $selected->attr('value');
    }

    /** @return list<string> */
    private function rowEmpireChoices(Crawler $crawler, int $index): array
    {
        return $crawler->filter('tbody tr')->eq($index)->filter('select option')->each(
            static fn (Crawler $option): string => (string) $option->attr('value'),
        );
    }

    /** @return list<string> */
    private function rowTakenEmpires(Crawler $crawler, int $index): array
    {
        return $crawler->filter('tbody tr')->eq($index)->filter('select option[disabled]')->each(
            static fn (Crawler $option): string => (string) $option->attr('value'),
        );
    }

    private function freshEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function isLaunchButtonDisabled(string $html): bool
    {
        preg_match('/<button\b[^>]*data-live-action-param="launch"[^>]*>/', $html, $matches);

        $tag = (string) preg_replace('/\sdata-loading="[^"]*"/', '', $matches[0] ?? '');

        return 1 === preg_match('/\bdisabled\b/', $tag);
    }
}
