<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\State\Game;
use App\State\Player;
use App\State\ASTVersion;
use App\Rules\Action\CreateGame;
use App\Rules\Ruleset\GameRegistry;
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

    #[Test]
    public function mountProposesAUuidAsTheDefaultGameSlug(): void
    {
        $component = $this->createLiveComponent('GameCreator');
        $rendered = $component->render();

        $this->assertTrue(Uuid::isValid($component->component()->game->slug));
        $this->assertStringContainsString('value="'.$component->component()->game->slug.'"', $rendered->toString());
    }

    #[Test]
    public function playerCountInputBoundsComeFromGameDataLimits(): void
    {
        $limits = self::getContainer()->get(GameRegistry::class)->getLimits();

        $rendered = $this->createLiveComponent('GameCreator')->render()->toString();

        $this->assertStringContainsString(sprintf('min="%d" max="%d"', $limits['min_players'], $limits['max_players']), $rendered);
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
        $this->assertSame('East + West', trim($options->text()));
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

    /**
     * newPlayerEmpire can hold an empire outside the current scenario pool — it survives a
     * player count or region change that re-renders the select without it. The state is set
     * up directly here; only the server-side guard in addPlayer() keeps it out of the roster.
     * The assertion reads the action response, not a later render(): $error is not a LiveProp,
     * so it does not survive into a subsequent request. The roster is read on the live $players
     * prop, never on $game->players — the command is only filled at launch, so asserting on it
     * here would pass against an empty array whatever the guard did.
     */
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
    public function addingAPlayerWithABlankNameShowsAFieldError(): void
    {
        $rendered = $this->createLiveComponent('GameCreator')
            ->set('newPlayerName', '')
            ->call('addPlayer')
            ->render()
        ;

        $this->assertStringContainsString('Player name is required.', $rendered->crawler()->filter('[data-error="newPlayerName"]')->text());
    }

    /**
     * Anti-regression: without de-tracking in addPlayer(), the scratch field stays in
     * validatedFields and the next PostHydrate replays NotBlank against the
     * just-cleared newPlayerName, resurrecting a ghost error the user never caused.
     */
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
        $this->assertSame('west', $game->region);
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

    /**
     * The sequence mirrors what the browser actually sends on a row select change: the model write
     * onto that row's own path, then the setEmpire action. The write is what assigns — an
     * identity-writable array prop accepts a nested path natively — so setEmpire only ever runs as a
     * post-write guard, and a legitimate empire simply survives it.
     */
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

    /**
     * The row's model path is client-writable and can carry an empire outside the scenario pool — a
     * stale option from a render that preceded a player count or region change, or a crafted request.
     * The candidate has already landed in the row by the time setEmpire() runs, so the guard reverts
     * rather than blocks. The error naming "celt" is this test's positive control: only a candidate
     * that actually reached the row can produce it, which is what makes the emptied row a reversal
     * instead of a write that silently never happened. The assertion reads the action response, not a
     * later render(): $error is not a LiveProp, so it does not survive into a subsequent request.
     */
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

    /**
     * The duplicate-empire conformity issue exists to catch a state the UI should never produce;
     * a crafted write onto another row's model path is the way one could be produced, so the guard
     * is checked from that side too. As above, the error naming "hatti" proves the candidate reached
     * row 1 before being reverted.
     */
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

    /**
     * An empire another row holds used to be removed from every other list; it is now offered
     * everywhere and merely flagged. The taken set is asserted whole rather than by membership: on
     * an implementation that dropped the flag entirely the set would be empty, and on one that
     * flagged the whole pool it would be full — both read as "hatti is not taken here" under a
     * single assertNotContains, and both must be red.
     */
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

    /**
     * The guarantee the whole change exists for, and the one the previous implementation failed:
     * assigning row 0 removed that empire from row 1's list and moved it to the end of row 0's own,
     * so both sequences shifted under a render. The DOM diffing library reconciles <option> elements
     * by position, which is how the request-in-flight race that got issue #19 rejected mis-paired
     * them; a composition that never changes leaves nothing to mis-pair. Pinning the sequence
     * against the expected pool first is the anti-vacuity control — a row rendering no options at
     * all would satisfy before/after equality on its own.
     */
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

    /**
     * The row's own empire used to be appended after the available ones, so picking it sent it to
     * the bottom of that row's select while every other row kept it in place. It now sits in the
     * pool's own order whoever holds it — the empty option still first, still the only way back to
     * no empire.
     */
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

    /**
     * The defect this exists for: every row's select once shared a single staging prop. The client's
     * SetValueOntoModelFieldsPlugin rewrites every [data-model] element from its prop's value on each
     * render:finished, so one shared prop meant one shared <select> value — assigning row 1 blanked
     * row 0 on screen while the server-rendered HTML stayed correct. Distinct per-row model paths are
     * what make that impossible, and this pins them: on the shared-prop implementation all three
     * selects read the same name and this goes red.
     *
     * What it does not prove: no component test runs that plugin, so this asserts the wiring is
     * per-row, never that the browser ends up displaying each row correctly. Only a browser check
     * covers the rendered outcome.
     */
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

        $scenarioEmpires = self::getContainer()->get(ScenarioRegistry::class)->empiresFor(9, 'west');
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

        $scenarioEmpires = self::getContainer()->get(ScenarioRegistry::class)->empiresFor(3, 'west');
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

    /**
     * addPlayer() refuses a name the roster already holds, but it is no longer the only door into
     * $players: the prop is identity-writable, so a well-formed row can be injected straight past
     * it. The roster is mounted directly here for that reason — going through addPlayer() would
     * test the door, not the gate behind it.
     *
     * Each pair is one no `===` on the raw names would catch: the rule compares slugs, because
     * uniq_player_game_slug does. The roster is otherwise fully conform — count matches, three
     * distinct in-scenario empires, an available slug — so the disabled button and the error band
     * have exactly one cause left.
     */
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

    /**
     * The positive control for the two around it: same roster shape, same count, same empires, two
     * names that merely resemble each other. An implementation reporting every roster as colliding
     * satisfies both neighbours and fails here.
     */
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

    /**
     * The gate the hole actually threatened. canLaunch() only drives a `disabled` attribute, which
     * the crafted request that produced this roster never reads; launch() is what it reaches, and
     * what would otherwise have handed the bus two players whose slugs collide. Asserted on the
     * persisted counts rather than on the message, so an implementation reporting the issue while
     * still creating the game is red.
     */
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

    /**
     * The rule is judged on the slug, never on the raw string, because that is what the board URL is
     * built from: a name a naive `'' === $name` waves through still persists a player with an empty
     * slug. Each row is a name that looks filled in the input and reaches the slugger as nothing.
     */
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

    /**
     * Two blank rows were already refused before getBlankNameIssue() existed, but only by accident:
     * they shared the '' slug bucket, so the duplicate-name rule reported them as sharing the name
     * "" — an operator-visible nonsense sentence. Its absence is half the fix, so it is asserted
     * here rather than left to the reader to infer from the new message.
     */
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

    /**
     * The gate the hole actually threatened, and the exact reproduction that opened it: one injected
     * blank row used to pass getConformityIssues(), canLaunch() and launch() alike, persisting a
     * Player with an empty name and an empty slug — a board URL that cannot be built. The crafted
     * request that produces this roster never renders a button and never reads a `disabled`
     * attribute, so the persisted counts are what is asserted, not the button state.
     */
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

    /**
     * The positive control for the three above, and the one they cannot supply: they all inject the
     * roster past addPlayer(), so an implementation refusing every injected roster would satisfy
     * each of them. Same door, names that slugify, and the game is created.
     */
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

    /**
     * playerCount is client-writable, and every other conformity rule is satisfied vacuously by a
     * roster that matches it — getPlayerCountMismatchIssue() included, since count([]) === 0 is a
     * match. Only a rule reading config/game/game_data.yaml's own min_players/max_players catches a
     * count whose sole guard was the form's min=/max= attributes. The tail of the list is spelled
     * out and the total counted, which pins the new issue to the head of getConformityIssues()
     * without depending on how it is worded.
     *
     * @param list<string> $expectedRemainingIssues
     */
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

    /**
     * The positive control the three rows above cannot supply on their own: a rule refusing every
     * count would satisfy each of them. The minimum and the maximum are precisely the counts an
     * operator is allowed to pick, so the only issue an empty roster still owes is the mismatch.
     */
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

    /**
     * The gate the hole actually threatened, and the exact reproduction that opened it: a crafted
     * request setting playerCount to zero used to clear canLaunch() and persist a Game with no
     * players at all. Only the persisted counts are asserted here — that request never renders a
     * form and never reads a `disabled` attribute, and the reported issue is pinned above.
     */
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
            self::getContainer()->get(ScenarioRegistry::class)->empiresFor(18, null),
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

    /**
     * Player::$name is a 50-wide column, so a name between the rule and the column persists in
     * silence — the wall a user hits by typing is the only thing that ever bounded it, and there is
     * no wall at all on the crafted door. The accented row is thirty characters and sixty bytes:
     * a byte-wise measure would refuse a name the rule allows.
     */
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

    /**
     * Length sits between NotBlank and the uniqueness Expression inside the Sequentially, so a name
     * that is both too long and already on the roster reports the thing the operator can act on.
     * Reported as a collision it would send them to shorten a different row, or to hunt for a
     * duplicate they can see is the same string they just typed.
     */
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

    /**
     * The crafted-row counterpart to the door above: $players is identity-writable, so a row that
     * was never typed into the add field reaches the roster unmeasured. Everything else about this
     * roster conforms — three usable names, three distinct scenario empires, a matching count — so
     * the single issue reported can only be the length one, whatever its wording turns out to be.
     */
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

    /**
     * The positive control for the crafted door, and the row that pins the measure to characters:
     * thirty 'é' are sixty bytes, so a byte-wise count would refuse a roster the rule allows —
     * and nothing else in the suite would notice, since every other name here is ascii.
     */
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

    /**
     * The game name had no bound at all: AvailableGameSlug reports blank, reserved and taken, and a
     * hundred-character name cleared all three. Nothing downstream caught it either — onSlugUpdated()
     * slugifies what is typed but never shortens it, and SQLite stores past a declared width in
     * silence, so the name persisted whole and became the game's URL. The roster is made conform
     * first so the disabled button has exactly one cause left, and the field error is asserted
     * alongside it: an operator handed a dead button and no sentence cannot know what to shorten.
     */
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

        $this->assertSame('Game name cannot be longer than 64 characters.', trim($rendered->crawler()->filter('[data-error="game.slug"]')->text()));
        $this->assertTrue($this->isLaunchButtonDisabled($rendered->toString()));
    }

    /**
     * The positive control for the two around it, taken at the limit rather than in the comfortable
     * middle: a bound compared with the wrong operator refuses exactly this name and nothing else,
     * and would do it to a game an operator is entitled to create. The persisted length is read back
     * rather than the presence of the row, since the whole point of the rule is that the column
     * silently accepts more than it declares — a name that arrived truncated would still be found.
     */
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

    /**
     * The gate the hole actually threatened. The input above is debounced and validated as it is
     * typed, but game.slug is a writable path: a request that never rendered a form hands launch()
     * whatever it likes, and launch() is the only thing between it and a persisted game. Asserted on
     * the persisted counts rather than on the button, which that request never reads.
     */
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

        $this->assertSame('Game name cannot be longer than 64 characters.', trim($rendered->crawler()->filter('[data-error="game.slug"]')->text()));

        $freshEntityManager = $this->freshEntityManager();
        $this->assertSame($gamesBefore, $freshEntityManager->getRepository(Game::class)->count([]));
        $this->assertSame($playersBefore, $freshEntityManager->getRepository(Player::class)->count([]));
    }

    /**
     * The empire a row's select currently shows. Asserting the count first is what keeps a row that
     * rendered no select — or one that pre-selected several options — from reading as an empty
     * empire, which is a legitimate value here.
     */
    private function rowSelectedEmpire(Crawler $crawler, int $index): string
    {
        $selected = $crawler->filter('tbody tr')->eq($index)->filter('select option[selected]');
        $this->assertCount(1, $selected);

        return (string) $selected->attr('value');
    }

    /**
     * The values a row's select offers, in render order and empty option included. A sequence, not a
     * set: order is the property under test wherever this is compared, since it is what the DOM
     * diffing library reconciles positionally.
     *
     * @return list<string>
     */
    private function rowEmpireChoices(Crawler $crawler, int $index): array
    {
        return $crawler->filter('tbody tr')->eq($index)->filter('select option')->each(
            static fn (Crawler $option): string => (string) $option->attr('value'),
        );
    }

    /**
     * The subset of those values the row renders as unpickable. A client-side courtesy only — what
     * the server actually refuses is pinned separately, on setEmpire().
     *
     * @return list<string>
     */
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
