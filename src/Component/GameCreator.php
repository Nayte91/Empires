<?php

declare(strict_types=1);

namespace App\Component;

use App\Game\Command\CreateGame;
use App\Game\Dto\Empire;
use App\Game\EmpireCatalog;
use App\Game\GameData;
use App\Game\ScenarioCatalog;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\ValidatableComponentTrait;

#[AsLiveComponent(template: 'organisms/gameCreator.html.twig')]
final class GameCreator
{
    use DefaultActionTrait;
    use ValidatableComponentTrait;

    #[Assert\Valid]
    #[LiveProp(writable: ['slug', 'playerCount', 'region', 'astVersion'], useSerializerForHydration: true, onUpdated: ['slug' => 'onSlugUpdated', 'playerCount' => 'onScenarioUpdated', 'region' => 'onScenarioUpdated'])]
    public CreateGame $game; // @phpstan-ignore property.uninitialized (initialized in mount())

    #[LiveProp(writable: true, onUpdated: 'onNewPlayerNameUpdated')]
    #[Assert\Sequentially([
        new Assert\NotBlank(message: 'Player name is required.', normalizer: [self::class, 'slugify']),
        new Assert\Expression('not this.hasPlayerNamed(value)', message: 'A player named {{ value }} already exists.'),
    ])]
    public string $newPlayerName = '';

    #[LiveProp(writable: true)]
    public string $newPlayerEmpire = '';

    public ?string $error = null;

    public function __construct(
        private readonly EmpireCatalog $empireCatalog,
        private readonly ScenarioCatalog $scenarioCatalog,
        private readonly GameData $gameData,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly MessageBusInterface $commandBus,
        private readonly ValidatorInterface $validator,
    ) {}

    public function mount(): void
    {
        $this->game = new CreateGame();
        $this->game->slug = (string) Uuid::v7();
    }

    public function onSlugUpdated(): void
    {
        $this->game->slug = self::slugify($this->game->slug);
        $this->validateField('game.slug', false);
    }

    public function onNewPlayerNameUpdated(): void
    {
        $this->validateField('newPlayerName', false);
    }

    /**
     * Stateless, direct ValidatorInterface call (not the trait's isValid()/validate()):
     * ComponentValidationErrors::count() counts tracked field keys, not violations, so
     * it returns non-zero as soon as any field has ever been validated — even
     * successfully. See canLaunch() for the same reasoning.
     */
    public function isSlugAvailable(): bool
    {
        return 0 === \count($this->validator->validateProperty($this->game, 'slug'));
    }

    public function hasPlayerNamed(string $name): bool
    {
        $slug = self::slugify($name);

        return array_any($this->game->players, static fn (array $player): bool => self::slugify($player['name']) === $slug);
    }

    public function onScenarioUpdated(): void
    {
        $regions = $this->scenarioCatalog->regionsFor($this->game->playerCount);

        if ([] === $regions) {
            $this->game->region = null;

            return;
        }

        if (null === $this->game->region || !\in_array($this->game->region, $regions, true)) {
            $this->game->region = $this->getRegionChoices()[0]['value'];
        }
    }

    #[LiveAction]
    public function addPlayer(): void
    {
        if ($this->isPlayerLimitReached()) {
            $this->error = sprintf('Player limit reached (%d/%d).', \count($this->game->players), $this->game->playerCount);

            return;
        }

        $this->validateField('newPlayerName', false);

        if ([] !== $this->getErrors('newPlayerName')) {
            return;
        }

        $empire = $this->newPlayerEmpire;

        if ('' !== $empire && !\in_array($empire, $this->remainingEmpires(), true)) {
            $this->error = sprintf('Empire "%s" is not available.', $empire);

            return;
        }

        $this->game->players[] = ['name' => trim($this->newPlayerName), 'empire' => $empire];
        $this->newPlayerName = '';
        $this->newPlayerEmpire = '';
        $this->error = null;

        // De-track the scratch field: the trait's PostHydrate hook replays validation
        // on every tracked field, which would fail NotBlank on the just-cleared name.
        /** @var list<string> $validatedFields */
        $validatedFields = $this->validatedFields;
        $this->validatedFields = array_values(array_diff($validatedFields, ['newPlayerName']));
        $this->getErrorsObject()->set('newPlayerName', []);
    }

    #[LiveAction]
    public function removePlayer(#[LiveArg] int $index): void
    {
        unset($this->game->players[$index]);
        $this->game->players = array_values($this->game->players);
    }

    #[LiveAction]
    public function assignRandomEmpire(#[LiveArg] int $index): void
    {
        if (!isset($this->game->players[$index]) || '' !== $this->game->players[$index]['empire']) {
            return;
        }

        $remaining = $this->remainingEmpires();

        if ([] === $remaining) {
            return;
        }

        $this->game->players[$index]['empire'] = $remaining[random_int(0, \count($remaining) - 1)];
    }

    #[LiveAction]
    public function assignRandomEmpires(): void
    {
        $remaining = $this->remainingEmpires();
        shuffle($remaining);

        foreach ($this->game->players as $index => $player) {
            if ([] === $remaining) {
                break;
            }

            if ('' !== $player['empire']) {
                continue;
            }

            $this->game->players[$index]['empire'] = array_shift($remaining);
        }
    }

    #[LiveAction]
    public function launch(): ?Response
    {
        if (!$this->canLaunch()) {
            $this->validateField('game.slug', false);

            $issues = $this->getConformityIssues();

            if ([] !== $issues) {
                $this->error = implode(' ', $issues);
            }

            return null;
        }

        try {
            $this->commandBus->dispatch($this->game);
        } catch (HandlerFailedException $exception) {
            foreach ($exception->getWrappedExceptions() as $wrappedException) {
                if (!$wrappedException instanceof UniqueConstraintViolationException) {
                    continue;
                }

                $this->error = sprintf('Slug "%s" is not available.', $this->game->slug);

                return null;
            }

            throw $exception;
        }

        return new RedirectResponse($this->urlGenerator->generate('app_game_operator', ['slug' => $this->game->slug]));
    }

    public function isPlayerLimitReached(): bool
    {
        return \count($this->game->players) >= $this->game->playerCount;
    }

    /**
     * Single source of truth for every launch() precondition (the DB unique-constraint
     * check is inherently racy and stays a defensive catch inside launch() itself):
     * drives both the "Create the game" button state and launch()'s entry guard.
     *
     * Stateless ValidatorInterface call, not the trait's isValid() — see isSlugAvailable().
     */
    public function canLaunch(): bool
    {
        return 0 === \count($this->validator->validate($this->game)) && [] === $this->getConformityIssues();
    }

    public function canAssignRandomEmpires(): bool
    {
        if ([] === $this->remainingEmpires()) {
            return false;
        }

        return array_any($this->game->players, static fn (array $player): bool => '' === $player['empire']);
    }

    public function getMinPlayers(): int
    {
        return $this->getLimits()['min_players'] ?? 1;
    }

    public function getMaxPlayers(): int
    {
        return $this->getLimits()['max_players'] ?? PHP_INT_MAX;
    }

    /** @return list<string> */
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

    /**
     * Backs the "Region" select: a genuinely single choice (combined map) once
     * the scenario no longer splits into east/west — the option list must change
     * structurally, a stale option merely disabled would survive DOM morphing.
     *
     * @return list<array{value: string, label: string}>
     */
    public function getRegionChoices(): array
    {
        if ([] === $this->scenarioCatalog->regionsFor($this->game->playerCount)) {
            return [['value' => '', 'label' => 'East + West']];
        }

        return array_map(
            static fn (string $region): array => ['value' => $region, 'label' => ucfirst($region)],
            ['west', 'east'],
        );
    }

    /**
     * Public static: used both as the internal slug helper and as the NotBlank
     * normalizer on $newPlayerName (Assert\NotBlank requires a static callable).
     */
    public static function slugify(string $value): string
    {
        return strtolower((string) new AsciiSlugger()->slug($value));
    }

    /**
     * Empire slugs of the current scenario minus those already taken by a player.
     * Backs both the "Empire" select (getAvailableEmpires()) and the
     * random-assignment actions, so the two stay consistent by construction.
     *
     * @return list<string>
     */
    private function remainingEmpires(): array
    {
        $scenarioEmpires = $this->scenarioCatalog->empiresFor($this->game->playerCount, $this->game->region);

        $taken = array_map(
            static fn (array $player): string => $player['empire'],
            $this->game->players,
        );

        return array_values(array_filter(
            $scenarioEmpires,
            static fn (string $empire): bool => !\in_array($empire, $taken, true),
        ));
    }

    private function getPlayerCountMismatchIssue(): ?string
    {
        $count = \count($this->game->players);
        $target = $this->game->playerCount;

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
     * Names players whose empire is no longer part of the current scenario —
     * only a player-count/region change can create that state, the UI never offers
     * an invalid empire.
     *
     * @return list<string>
     */
    private function getInvalidEmpireIssues(): array
    {
        $scenarioEmpires = $this->scenarioCatalog->empiresFor($this->game->playerCount, $this->game->region);
        $issues = [];

        foreach ($this->game->players as $player) {
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

        foreach ($this->game->players as $player) {
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

        foreach ($this->game->players as $player) {
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
