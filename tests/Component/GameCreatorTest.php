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
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class GameCreatorTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithLiveComponents;

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

    #[Test]
    public function assigningAnEmpireDropsItFromTheOtherRowsChoicesWhileKeepingItOnItsOwn(): void
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

        $this->assertContains('hatti', $this->rowEmpireChoices($crawler, 0));
        $this->assertSame('hatti', $this->rowSelectedEmpire($crawler, 0));
        $this->assertNotContains('hatti', $this->rowEmpireChoices($crawler, 1));
        $this->assertContains('rome', $this->rowEmpireChoices($crawler, 1));
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

    /** @return list<string> */
    private function rowEmpireChoices(Crawler $crawler, int $index): array
    {
        return $crawler->filter('tbody tr')->eq($index)->filter('select option')->each(
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
