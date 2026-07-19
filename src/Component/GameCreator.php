<?php

declare(strict_types=1);

namespace App\Component;

use App\Entity\GameSession;
use App\Entity\Player;
use App\Game\Dto\Empire;
use App\Game\EmpireCatalog;
use App\Game\GameData;
use App\Game\ScenarioCatalog;
use App\Repository\GameSessionRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent(template: 'organisms/gameCreator.html.twig')]
final class GameCreator
{
    use DefaultActionTrait;

    /**
     * Governance rule: every first-level static path (e.g. /rules, /stats)
     * must be added here BEFORE its route is created, and checked against
     * existing game slugs in the database — otherwise it either shadows, or
     * is shadowed by, the /{slug} wildcard (GameController::dashboard()).
     *
     * Slugs colliding with a static route declared before the /{slug}
     * wildcard (e.g. app_game_create at /create), which would make the
     * resulting game's dashboard unreachable.
     *
     * @var list<string>
     */
    private const array RESERVED_SLUGS = ['create'];

    #[LiveProp(writable: true, onUpdated: 'onSlugUpdated')]
    public string $slug = '';

    #[LiveProp(writable: true, onUpdated: 'onScenarioUpdated')]
    public int $playerCount = 9;

    #[LiveProp(writable: true, onUpdated: 'onScenarioUpdated')]
    public ?string $region = 'west';

    #[LiveProp(writable: true)]
    public string $astVersion = 'basic';

    /** @var list<array{name: string, empire: string}> */
    #[LiveProp]
    public array $players = [];

    #[LiveProp(writable: true)]
    public string $newPlayerName = '';

    #[LiveProp(writable: true)]
    public string $newPlayerEmpire = '';

    public ?string $error = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GameSessionRepository $gameRepository,
        private readonly EmpireCatalog $empireCatalog,
        private readonly ScenarioCatalog $scenarioCatalog,
        private readonly GameData $gameData,
        private readonly SluggerInterface $slugger,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function mount(): void
    {
        $this->slug = (string) Uuid::v7();
    }

    public function onSlugUpdated(): void
    {
        $this->slug = $this->slugify($this->slug);
    }

    public function isSlugAvailable(): bool
    {
        if ('' === $this->slug || \in_array($this->slug, self::RESERVED_SLUGS, true)) {
            return false;
        }

        return null === $this->gameRepository->findOneBy(['slug' => $this->slug]);
    }

    /**
     * Enforces the region/player-count invariant (§ scenario setup) in memory,
     * no flush: the GameSession entity does not exist yet at this stage.
     */
    public function onScenarioUpdated(): void
    {
        if ($this->playerCount >= 10) {
            $this->region = null;
        } elseif (null === $this->region) {
            $this->region = 'west';
        }
    }

    #[LiveAction]
    public function addPlayer(): void
    {
        if ($this->isPlayerLimitReached()) {
            $this->error = sprintf('Player limit reached (%d/%d).', \count($this->players), $this->playerCount);

            return;
        }

        $name = trim($this->newPlayerName);

        if ('' === $name) {
            $this->error = 'Player name is required.';

            return;
        }

        $slug = $this->slugify($name);

        if ('' === $slug) {
            $this->error = 'Player name is required.';

            return;
        }

        foreach ($this->players as $existing) {
            if ($this->slugify($existing['name']) === $slug) {
                $this->error = sprintf('A player named "%s" already exists.', $name);

                return;
            }
        }

        $empire = $this->newPlayerEmpire;

        if ('' !== $empire && !\in_array($empire, $this->remainingEmpires(), true)) {
            $this->error = sprintf('Empire "%s" is not available.', $empire);

            return;
        }

        $this->players[] = ['name' => $name, 'empire' => $empire];
        $this->newPlayerName = '';
        $this->newPlayerEmpire = '';
        $this->error = null;
    }

    #[LiveAction]
    public function removePlayer(#[LiveArg] int $index): void
    {
        unset($this->players[$index]);
        $this->players = array_values($this->players);
    }

    #[LiveAction]
    public function assignRandomEmpire(#[LiveArg] int $index): void
    {
        if (!isset($this->players[$index]) || '' !== $this->players[$index]['empire']) {
            return;
        }

        $remaining = $this->remainingEmpires();

        if ([] === $remaining) {
            return;
        }

        $this->players[$index]['empire'] = $remaining[random_int(0, \count($remaining) - 1)];
    }

    #[LiveAction]
    public function assignRandomEmpires(): void
    {
        $remaining = $this->remainingEmpires();
        shuffle($remaining);

        foreach ($this->players as $index => $player) {
            if ([] === $remaining) {
                break;
            }

            if ('' !== $player['empire']) {
                continue;
            }

            $this->players[$index]['empire'] = array_shift($remaining);
        }
    }

    #[LiveAction]
    public function launch(): ?Response
    {
        if ([] !== $issues = $this->getConformityIssues()) {
            $this->error = implode(' ', $issues);

            return null;
        }

        if (\in_array($this->slug, self::RESERVED_SLUGS, true)) {
            $this->error = 'This name is reserved.';

            return null;
        }

        if (!$this->isSlugAvailable()) {
            $this->error = sprintf('Slug "%s" is not available.', $this->slug);

            return null;
        }

        try {
            $this->entityManager->wrapInTransaction(function (): void {
                $game = new GameSession($this->slug);
                $game->playerCount = $this->playerCount;
                $game->region = $this->region;
                $game->setAstVersionValue($this->astVersion);

                $this->entityManager->persist($game);

                foreach ($this->players as $playerData) {
                    $player = new Player($game, $playerData['name']);
                    $player->empire = $playerData['empire'];
                    $this->entityManager->persist($player);
                }
            });
        } catch (UniqueConstraintViolationException) {
            $this->error = sprintf('Slug "%s" is not available.', $this->slug);

            return null;
        }

        return new RedirectResponse($this->urlGenerator->generate('app_game_operator', ['slug' => $this->slug]));
    }

    public function isPlayerLimitReached(): bool
    {
        return \count($this->players) >= $this->playerCount;
    }

    public function isReadyToCreate(): bool
    {
        return [] === $this->getConformityIssues();
    }

    public function canAssignRandomEmpires(): bool
    {
        if ([] === $this->remainingEmpires()) {
            return false;
        }

        return array_any($this->players, static fn (array $player): bool => '' === $player['empire']);
    }

    public function getMinPlayers(): int
    {
        return $this->getLimits()['min_players'] ?? 1;
    }

    public function getMaxPlayers(): int
    {
        return $this->getLimits()['max_players'] ?? PHP_INT_MAX;
    }

    /**
     * Single source of truth for the launch guard: every reason the current state
     * cannot be turned into a game, in priority order (§ scenario conformity):
     * player-count mismatch, empires invalidated by a scenario change, duplicate
     * empires (unreachable through the UI but kept as a safety net), then players
     * still missing an empire.
     *
     * @return list<string>
     */
    public function getConformityIssues(): array
    {
        $issues = [];

        $countMismatch = $this->getPlayerCountMismatchIssue();

        if (null !== $countMismatch) {
            $issues[] = $countMismatch;
        }

        array_push($issues, ...$this->getInvalidEmpireIssues());
        array_push($issues, ...$this->getDuplicateEmpireIssues());

        $missingEmpireIssue = $this->getMissingEmpireIssue();

        if (null !== $missingEmpireIssue) {
            $issues[] = $missingEmpireIssue;
        }

        return $issues;
    }

    /** @return list<Empire> */
    public function getAvailableEmpires(): array
    {
        return array_values(array_filter(
            array_map($this->empireCatalog->findByName(...), $this->remainingEmpires()),
            static fn (?Empire $empire): bool => $empire instanceof Empire,
        ));
    }

    /**
     * @return array<string, array{
     *     name: string,
     *     name2019: string,
     *     name2024: string,
     *     empires: list<string>,
     * }>
     */
    public function getRegions(): array
    {
        return $this->gameData->getRegions();
    }

    private function slugify(string $value): string
    {
        return strtolower((string) $this->slugger->slug($value));
    }

    /**
     * Empire slugs of the current scenario (§ scenario setup) minus those already
     * taken by a player. Backs both the "Empire" select (getAvailableEmpires()) and
     * the random-assignment actions, so the two stay consistent by construction.
     *
     * @return list<string>
     */
    private function remainingEmpires(): array
    {
        $scenarioEmpires = $this->scenarioCatalog->empiresFor($this->playerCount, $this->region);

        $taken = array_map(
            static fn (array $player): string => $player['empire'],
            $this->players,
        );

        return array_values(array_filter(
            $scenarioEmpires,
            static fn (string $empire): bool => !\in_array($empire, $taken, true),
        ));
    }

    /**
     * Explains how to unblock the player-count/playerCount mismatch, using
     * GameData's min/max player bounds to decide whether lowering/raising the
     * target is offered.
     */
    private function getPlayerCountMismatchIssue(): ?string
    {
        $count = \count($this->players);
        $target = $this->playerCount;

        if ($count === $target) {
            return null;
        }

        $limits = $this->getLimits();

        if ($count < $target) {
            $missing = $target - $count;
            $message = sprintf('Add %d more %s', $missing, 1 === $missing ? 'player' : 'players');

            if (isset($limits['min_players']) && $count >= $limits['min_players']) {
                return $message.sprintf(', or lower the player count to %d.', $count);
            }

            return $message.'.';
        }

        $extra = $count - $target;
        $message = sprintf('Remove %d %s', $extra, 1 === $extra ? 'player' : 'players');

        if (isset($limits['max_players']) && $count <= $limits['max_players']) {
            return $message.sprintf(', or raise the player count to %d.', $count);
        }

        return $message.'.';
    }

    /**
     * Names players whose empire is no longer part of the current scenario, e.g.
     * after a player-count/region change (§ scenario setup) invalidates it.
     *
     * @return list<string>
     */
    private function getInvalidEmpireIssues(): array
    {
        $scenarioEmpires = $this->scenarioCatalog->empiresFor($this->playerCount, $this->region);
        $issues = [];

        foreach ($this->players as $player) {
            if ('' !== $player['empire'] && !\in_array($player['empire'], $scenarioEmpires, true)) {
                $issues[] = sprintf('%s\'s empire "%s" is not part of the current scenario.', $player['name'], $player['empire']);
            }
        }

        return $issues;
    }

    /**
     * Names players sharing the same empire. Unreachable through the UI (addPlayer()
     * and the random-assignment actions both draw from remainingEmpires()), kept as
     * a safety net against state built through unforeseen chains of interactions.
     *
     * @return list<string>
     */
    private function getDuplicateEmpireIssues(): array
    {
        $namesByEmpire = [];

        foreach ($this->players as $player) {
            if ('' !== $player['empire']) {
                $namesByEmpire[$player['empire']][] = $player['name'];
            }
        }

        $issues = [];

        foreach ($namesByEmpire as $empire => $names) {
            if (\count($names) < 2) {
                continue;
            }

            $last = array_pop($names);
            $issues[] = sprintf('%s share the empire "%s".', implode(', ', $names).' and '.$last, $empire);
        }

        return $issues;
    }

    private function getMissingEmpireIssue(): ?string
    {
        $missing = 0;

        foreach ($this->players as $player) {
            if ('' === $player['empire']) {
                ++$missing;
            }
        }

        if (0 === $missing) {
            return null;
        }

        return 1 === $missing
            ? '1 player still needs an empire.'
            : sprintf('%d players still need an empire.', $missing);
    }

    /**
     * Single read of GameData's limits, shared by getMinPlayers()/getMaxPlayers()
     * and the player-count mismatch message.
     *
     * @return array{
     *     max_cities?: int,
     *     max_population?: int,
     *     max_ships?: int,
     *     modes?: list<string>,
     *     min_players?: int,
     *     max_players?: int,
     * }
     */
    private function getLimits(): array
    {
        return $this->gameData->getLimits();
    }
}
