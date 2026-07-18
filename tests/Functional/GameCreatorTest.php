<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GameSession;
use App\Entity\Player;
use App\Game\ASTType;
use App\Game\GameData;
use App\Game\ScenarioCatalog;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class GameCreatorTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function mountProposesAUuidAsTheDefaultGameSlug(): void
    {
        $component = $this->createLiveComponent('GameCreator');
        $rendered = $component->render();

        self::assertTrue(Uuid::isValid($component->component()->slug));
        self::assertStringContainsString('value="'.$component->component()->slug.'"', $rendered->toString());
    }

    #[Test]
    public function playerCountInputBoundsComeFromGameDataLimits(): void
    {
        $limits = self::getContainer()->get(GameData::class)->getLimits();

        $rendered = $this->createLiveComponent('GameCreator')->render()->toString();

        self::assertStringContainsString(
            sprintf('min="%d" max="%d"', $limits['min_players'], $limits['max_players']),
            $rendered,
        );
    }

    #[Test]
    public function settingTheSlugSlugifiesItAndShowsItAsAvailable(): void
    {
        $rendered = $this->createLiveComponent('GameCreator')
            ->set('slug', 'Super Game de Nayte')
            ->render()
        ;

        self::assertStringContainsString('value="super-game-de-nayte"', $rendered->toString());
        self::assertStringContainsString('game-creator__slug-status--available', $rendered->toString());
    }

    #[Test]
    public function slugOfAnExistingGameIsShownAsUnavailable(): void
    {
        $this->createGame('taken-slug');

        $rendered = $this->createLiveComponent('GameCreator')
            ->set('slug', 'Taken Slug')
            ->render()
        ;

        self::assertStringContainsString('value="taken-slug"', $rendered->toString());
        self::assertStringContainsString('game-creator__slug-status--taken', $rendered->toString());
    }

    #[Test]
    public function settingPlayerCountToTenClearsTheRegion(): void
    {
        $rendered = $this->createLiveComponent('GameCreator')
            ->set('playerCount', 10)
            ->render()
        ;

        self::assertStringContainsString('<option value="" selected >Combined maps</option>', $rendered->toString());
        self::assertStringContainsString('<select data-model="region" disabled>', $rendered->toString());
    }

    #[Test]
    public function addingPlayersDoesNotPersistAnythingButRendersTheTable(): void
    {
        $gamesBefore = $this->entityManager->getRepository(GameSession::class)->count([]);
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

        self::assertStringContainsString('Alice', $rendered->toString());
        self::assertStringContainsString('Bob', $rendered->toString());

        $freshEntityManager = $this->freshEntityManager();
        self::assertSame($gamesBefore, $freshEntityManager->getRepository(GameSession::class)->count([]));
        self::assertSame($playersBefore, $freshEntityManager->getRepository(Player::class)->count([]));
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

        self::assertStringContainsString('already exists', $rendered->toString());
        self::assertSame(1, substr_count($rendered->toString(), '<td>Alice</td>'));
        self::assertStringNotContainsString('<td>alice</td>', $rendered->toString());
    }

    #[Test]
    public function launchCreatesTheGameAndItsPlayersAndRedirectsToTheOperatorConsole(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('slug', 'Launch Game')
            ->set('playerCount', 9)
            ->set('region', 'west')
            ->set('astType', 'expert')
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
        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('/game/launch-game/operator', (string) $response->headers->get('Location'));

        $freshEntityManager = $this->freshEntityManager();
        $game = $freshEntityManager->getRepository(GameSession::class)->findOneBy(['slug' => 'launch-game']);

        self::assertNotNull($game);
        self::assertSame(9, $game->playerCount);
        self::assertSame('west', $game->region);
        self::assertSame(ASTType::EXPERT, $game->astType);

        $players = $freshEntityManager->getRepository(Player::class)->findBy(['game' => $game->id]);
        self::assertCount(9, $players);

        $alice = null;
        foreach ($players as $player) {
            if ('Alice' === $player->name) {
                $alice = $player;
            }
        }

        self::assertInstanceOf(Player::class, $alice);
        self::assertSame('alice', $alice->slug);
        self::assertSame('hatti', $alice->empire);
    }

    #[Test]
    public function slugTakenAtLaunchTimeDisplaysAnErrorAndCreatesNothing(): void
    {
        $this->createGame('race-slug');

        $gamesBefore = $this->entityManager->getRepository(GameSession::class)->count([]);

        $component = $this->createLiveComponent('GameCreator')
            ->set('slug', 'Race Slug')
            ->set('playerCount', 3)
        ;

        foreach ([['Alice', 'hatti'], ['Bob', 'hellas'], ['Carol', 'minoa']] as [$name, $empire]) {
            $component
                ->set('newPlayerName', $name)
                ->set('newPlayerEmpire', $empire)
            ;
            $component->call('addPlayer');
        }

        $rendered = $component->call('launch')->render();

        self::assertStringContainsString('is not available', $rendered->toString());

        $freshEntityManager = $this->freshEntityManager();
        self::assertSame($gamesBefore, $freshEntityManager->getRepository(GameSession::class)->count([]));
    }

    #[Test]
    public function addPlayerRowIsDisabledAndAddPlayerIsRefusedWhenThePlayerLimitIsReached(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('playerCount', 3)
        ;

        foreach ([['Alice', 'hatti'], ['Bob', 'hellas'], ['Carol', 'minoa']] as [$name, $empire]) {
            $component
                ->set('newPlayerName', $name)
                ->set('newPlayerEmpire', $empire)
            ;
            $component->call('addPlayer');
        }

        $rendered = $component->render()->toString();

        self::assertStringContainsString('data-model="newPlayerName" value="" disabled', $rendered);
        self::assertStringContainsString('data-model="newPlayerEmpire" disabled', $rendered);
        self::assertStringContainsString('Player limit reached (3/3).', $rendered);

        $component
            ->set('newPlayerName', 'Dave')
            ->set('newPlayerEmpire', 'egypt')
        ;
        $rendered = $component->call('addPlayer')->render()->toString();

        self::assertStringContainsString('Player limit reached (3/3).', $rendered);
        self::assertStringNotContainsString('<td>Dave</td>', $rendered);
    }

    #[Test]
    public function loweringPlayerCountBelowTheAlreadyAddedPlayersCountDisablesTheAddPlayerRow(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('playerCount', 5)
        ;

        foreach ([['Alice', 'hatti'], ['Bob', 'hellas'], ['Carol', 'minoa']] as [$name, $empire]) {
            $component
                ->set('newPlayerName', $name)
                ->set('newPlayerEmpire', $empire)
            ;
            $component->call('addPlayer');
        }

        $rendered = $component->set('playerCount', 3)->render()->toString();

        self::assertStringContainsString('data-model="newPlayerName" value="" disabled', $rendered);
        self::assertStringContainsString('data-model="newPlayerEmpire" disabled', $rendered);
        self::assertStringContainsString('Player limit reached (3/3).', $rendered);
    }

    #[Test]
    public function launchIsDisabledWithLowerAlternativeWhenNotEnoughPlayersButAboveTheMinimum(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('playerCount', 8)
        ;

        foreach ([['Alice', 'hatti'], ['Bob', 'hellas'], ['Carol', 'minoa'], ['Dave', 'egypt'], ['Eve', 'carthage']] as [$name, $empire]) {
            $component
                ->set('newPlayerName', $name)
                ->set('newPlayerEmpire', $empire)
            ;
            $component->call('addPlayer');
        }

        $rendered = $component->render()->toString();

        self::assertTrue(self::isLaunchButtonDisabled($rendered));
        self::assertStringContainsString('game-creator__conformity--error', $rendered);
        self::assertStringContainsString(
            'Add 3 more players, or lower the player count to 5.',
            $rendered,
        );
    }

    #[Test]
    public function launchIsDisabledWithoutLowerAlternativeWhenNotEnoughPlayersAndBelowTheMinimum(): void
    {
        $rendered = $this->createLiveComponent('GameCreator')
            ->set('playerCount', 9)
            ->render()
            ->toString()
        ;

        self::assertTrue(self::isLaunchButtonDisabled($rendered));
        self::assertStringContainsString('game-creator__conformity--error', $rendered);
        self::assertStringContainsString('Add 9 more players.', $rendered);
        self::assertStringNotContainsString('or lower the player count', $rendered);
    }

    #[Test]
    public function launchIsDisabledWithRaiseAlternativeWhenTooManyPlayers(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('playerCount', 5)
        ;

        foreach ([['Alice', 'hatti'], ['Bob', 'hellas'], ['Carol', 'minoa'], ['Dave', 'egypt'], ['Eve', 'assyria']] as [$name, $empire]) {
            $component
                ->set('newPlayerName', $name)
                ->set('newPlayerEmpire', $empire)
            ;
            $component->call('addPlayer');
        }

        $rendered = $component->set('playerCount', 3)->render()->toString();

        self::assertTrue(self::isLaunchButtonDisabled($rendered));
        self::assertStringContainsString('game-creator__conformity--error', $rendered);
        self::assertStringContainsString(
            'Remove 2 players, or raise the player count to 5.',
            $rendered,
        );
    }

    #[Test]
    public function launchIsActiveAndShowsNoMismatchMessageWhenPlayerCountMatchesTarget(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('playerCount', 3)
        ;

        foreach ([['Alice', 'hatti'], ['Bob', 'hellas'], ['Carol', 'minoa']] as [$name, $empire]) {
            $component
                ->set('newPlayerName', $name)
                ->set('newPlayerEmpire', $empire)
            ;
            $component->call('addPlayer');
        }

        $rendered = $component->render()->toString();

        self::assertFalse(self::isLaunchButtonDisabled($rendered));
        self::assertStringContainsString('game-creator__conformity--ok', $rendered);
        self::assertStringContainsString('Everything is fine.', $rendered);
    }

    #[Test]
    public function launchIsRefusedServerSideWhenPlayerCountMismatchesAndNothingIsCreated(): void
    {
        $gamesBefore = $this->entityManager->getRepository(GameSession::class)->count([]);
        $playersBefore = $this->entityManager->getRepository(Player::class)->count([]);

        $component = $this->createLiveComponent('GameCreator')
            ->set('slug', 'Mismatch Game')
            ->set('playerCount', 9)
        ;

        $rendered = $component->call('launch')->render();

        self::assertStringContainsString('game-creator__conformity--error', $rendered->toString());
        self::assertStringContainsString('Add 9 more players.', $rendered->toString());

        $freshEntityManager = $this->freshEntityManager();
        self::assertSame($gamesBefore, $freshEntityManager->getRepository(GameSession::class)->count([]));
        self::assertSame($playersBefore, $freshEntityManager->getRepository(Player::class)->count([]));
    }

    #[Test]
    public function addingAPlayerWithoutAnEmpireShowsADashAndNoError(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('newPlayerName', 'Alice')
            ->set('newPlayerEmpire', '')
        ;
        $rendered = $component->call('addPlayer')->render()->toString();

        self::assertStringNotContainsString('game-creator__error', $rendered);
        self::assertMatchesRegularExpression('/<td>Alice<\/td>\s*<td>—<\/td>/', $rendered);
    }

    #[Test]
    public function assigningARandomEmpireOnAnEmptyRowPicksAScenarioEmpireNotAlreadyTakenAndHidesTheDice(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('playerCount', 9)
            ->set('region', 'west')
        ;

        $component->set('newPlayerName', 'Alice')->set('newPlayerEmpire', 'hatti');
        $component->call('addPlayer');
        $component->set('newPlayerName', 'Bob')->set('newPlayerEmpire', '');
        $component->call('addPlayer');

        $rendered = $component->call('assignRandomEmpire', ['index' => 1])->render()->toString();

        $scenarioEmpires = self::getContainer()->get(ScenarioCatalog::class)->empiresFor(9, 'west');
        $players = $component->component()->players;

        self::assertNotSame('', $players[1]['empire']);
        self::assertContains($players[1]['empire'], $scenarioEmpires);
        self::assertNotSame('hatti', $players[1]['empire']);
        self::assertStringNotContainsString('game-creator__random-player', $rendered);
    }

    #[Test]
    public function assigningARandomEmpireIsDeterministicWhenOnlyOneEmpireRemains(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('playerCount', 9)
            ->set('region', 'west')
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

        self::assertSame('minoa', $component->component()->players[8]['empire']);
    }

    #[Test]
    public function assigningRandomEmpiresFillsAllEmptyRowsFromTheScenarioWithoutDuplicates(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('playerCount', 3)
            ->set('region', 'west')
        ;

        foreach (['Alice', 'Bob', 'Carol'] as $name) {
            $component->set('newPlayerName', $name)->set('newPlayerEmpire', '');
            $component->call('addPlayer');
        }

        $component->call('assignRandomEmpires');

        $scenarioEmpires = self::getContainer()->get(ScenarioCatalog::class)->empiresFor(3, 'west');
        $assignedEmpires = array_map(
            static fn (array $player): string => $player['empire'],
            $component->component()->players,
        );

        self::assertNotContains('', $assignedEmpires);
        self::assertCount(3, array_intersect($assignedEmpires, $scenarioEmpires));
        self::assertCount(3, array_unique($assignedEmpires));
    }

    #[Test]
    public function changingRegionAfterAssignmentInvalidatesTheEmpireAndDisablesLaunch(): void
    {
        $component = $this->createLiveComponent('GameCreator')
            ->set('playerCount', 9)
            ->set('region', 'east')
            ->set('newPlayerName', 'Alice')
            ->set('newPlayerEmpire', 'kushan')
        ;
        $component->call('addPlayer');

        $rendered = $component->set('region', 'west')->render()->toString();

        self::assertTrue(self::isLaunchButtonDisabled($rendered));
        self::assertStringContainsString('game-creator__conformity--error', $rendered);
        self::assertStringContainsString('Alice&#039;s empire &quot;kushan&quot; is not part of the current scenario.', $rendered);
    }

    #[Test]
    public function duplicateEmpiresAcrossPlayersAreReportedAsAConformityIssue(): void
    {
        $component = $this->createLiveComponent('GameCreator', [
            'playerCount' => 9,
            'region' => 'west',
            'players' => [
                ['name' => 'Alice', 'empire' => 'hatti'],
                ['name' => 'Bob', 'empire' => 'hatti'],
            ],
        ]);

        $rendered = $component->render()->toString();

        self::assertTrue(self::isLaunchButtonDisabled($rendered));
        self::assertStringContainsString('game-creator__conformity--error', $rendered);
        self::assertStringContainsString('Alice and Bob share the empire &quot;hatti&quot;.', $rendered);
    }

    #[Test]
    public function launchIsRefusedServerSideWhenAPlayerHasNoEmpireEvenIfThePlayerCountMatches(): void
    {
        $gamesBefore = $this->entityManager->getRepository(GameSession::class)->count([]);
        $playersBefore = $this->entityManager->getRepository(Player::class)->count([]);

        $component = $this->createLiveComponent('GameCreator')
            ->set('slug', 'No Empire Game')
            ->set('playerCount', 3)
            ->set('region', 'west')
        ;

        foreach ([['Alice', 'hatti'], ['Bob', 'hellas'], ['Carol', '']] as [$name, $empire]) {
            $component->set('newPlayerName', $name)->set('newPlayerEmpire', $empire);
            $component->call('addPlayer');
        }

        $rendered = $component->call('launch')->render();

        self::assertStringContainsString('1 player still needs an empire.', $rendered->toString());

        $freshEntityManager = $this->freshEntityManager();
        self::assertSame($gamesBefore, $freshEntityManager->getRepository(GameSession::class)->count([]));
        self::assertSame($playersBefore, $freshEntityManager->getRepository(Player::class)->count([]));
    }

    private function createGame(string $slug): GameSession
    {
        $game = new GameSession($slug);
        $this->entityManager->persist($game);
        $this->entityManager->flush();

        return $game;
    }

    private function freshEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * The button always carries data-loading="addAttribute(disabled)" (loading-state
     * directive), which itself contains the word "disabled". That decoy is stripped
     * before checking for the actual HTML `disabled` attribute.
     */
    private static function isLaunchButtonDisabled(string $html): bool
    {
        preg_match('/<button\b[^>]*class="game-creator__launch"[^>]*>/', $html, $matches);
        $tag = str_replace('addAttribute(disabled)', '', $matches[0] ?? '');

        return 1 === preg_match('/\bdisabled\b/', $tag);
    }
}
