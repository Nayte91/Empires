<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\State\Game;
use App\State\Player;
use App\State\ASTVersion;
use App\Rules\Action\CreateGame;
use App\Rules\Ruleset\GameData;
use App\Rules\Ruleset\ScenarioCatalog;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\GameFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
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
        $limits = self::getContainer()->get(GameData::class)->getLimits();

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

        $this->assertStringContainsString('already exists', $rendered->crawler()->filter('[data-error="newPlayerName"]')->text());
        $this->assertSame(1, substr_count($rendered->toString(), '<td>Alice</td>'));
        $this->assertStringNotContainsString('<td>alice</td>', $rendered->toString());
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

        $this->assertStringContainsString('data-model="newPlayerName" value="" disabled', $rendered);
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

        $this->assertStringContainsString('data-model="newPlayerName" value="" disabled', $rendered);
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

    #[Test]
    public function addingAPlayerWithoutAnEmpireShowsADashAndNoError(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('newPlayerName', 'Alice')
            ->set('newPlayerEmpire', '')
        ;
        $rendered = $component->call('addPlayer')->render()->toString();

        $this->assertStringNotContainsString('data-error', $rendered);
        $this->assertMatchesRegularExpression('/<td>Alice<\/td>\s*<td>—<\/td>/', $rendered);
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

        $scenarioEmpires = self::getContainer()->get(ScenarioCatalog::class)->empiresFor(9, 'west');
        $players = $component->component()->game->players;

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

        $this->assertSame('minoa', $component->component()->game->players[8]['empire']);
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

        $scenarioEmpires = self::getContainer()->get(ScenarioCatalog::class)->empiresFor(3, 'west');
        $assignedEmpires = array_map(
            static fn (array $player): string => $player['empire'],
            $component->component()->game->players,
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
        $game->players = [
            ['name' => 'Alice', 'empire' => 'hatti'],
            ['name' => 'Bob', 'empire' => 'hatti'],
        ];

        $component = $this->createLiveComponent('GameCreator', ['game' => $game]);

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

    private function freshEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function isLaunchButtonDisabled(string $html): bool
    {
        preg_match('/<button\b[^>]*data-live-action-param="launch"[^>]*>/', $html, $matches);

        return 1 === preg_match('/\bdisabled\b/', $matches[0] ?? '');
    }
}
