<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Rules\Action\CreateGame;
use App\Rules\Ruleset\GameRegistry;
use App\Rules\Ruleset\ScenarioRegistry;
use App\Rules\Scenario\ScenarioRuleSummarizer;
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
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
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

    #[LiveProp(writable: true)]
    #[Assert\Sequentially([
        new Assert\NotBlank(message: 'Player name is required.', normalizer: [self::class, 'slugify']),
        new Assert\Expression('not this.hasPlayerNamed(value)', message: 'Name already taken.'),
    ])]
    public string $newPlayerName = '';

    #[LiveProp(writable: true)]
    public string $newPlayerEmpire = '';

    /** @var list<array{name: string, empire: string}> */
    #[LiveProp(writable: true)]
    public array $players = [];

    public ?string $error = null;

    public function __construct(
        private readonly ScenarioRegistry $scenarioRegistry,
        private readonly GameRegistry $gameRegistry,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly MessageBusInterface $commandBus,
        private readonly ValidatorInterface $validator,
        private readonly ScenarioRuleSummarizer $scenarioRuleSummarizer,
    ) {}

    public function mount(): void
    {
        $this->game = new CreateGame();
        $this->game->slug = (string) Uuid::v7();
    }

    /**
     * `players.{i}.{key}` is identity-writable per row, so the hydrator accepts any index a
     * request names. A path past the current roster (e.g. `players.5.empire` on a one-row
     * list) makes LiveComponentHydrator::setWritablePaths() auto-vivify an empty
     * `players[5]`, then fail to write `empire` into it — a failure it swallows internally,
     * so the empty row survives into $this->players and crashes the template on render.
     * No legitimate UI interaction can name a row past the one it was just given, so this
     * is a fabricated request, not a user mistake: drop the row rather than report it, unlike
     * setEmpire()'s guard, which reports because it clears a value a genuine race could produce.
     */
    #[PostHydrate]
    public function dropMalformedPlayerRows(): void
    {
        $this->players = array_values(array_filter(
            $this->players,
            static fn (array $player): bool => \array_key_exists('name', $player) && \array_key_exists('empire', $player),
        ));
    }

    public function onSlugUpdated(): void
    {
        $this->game->slug = self::slugify($this->game->slug);
        $this->validateField('game.slug', false);
    }

    public function isSlugAvailable(): bool
    {
        return 0 === \count($this->validator->validateProperty($this->game, 'slug'));
    }

    public function hasPlayerNamed(string $name): bool
    {
        $slug = self::slugify($name);

        return array_any($this->players, static fn (array $player): bool => self::slugify($player['name']) === $slug);
    }

    public function onScenarioUpdated(): void
    {
        $regions = $this->scenarioRegistry->regionsFor($this->game->playerCount);

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
            $this->error = sprintf('Player limit reached (%d/%d).', \count($this->players), $this->game->playerCount);

            return;
        }

        $this->validateField('newPlayerName', false);

        if ([] !== $this->getErrors('newPlayerName')) {
            return;
        }

        $empire = $this->newPlayerEmpire;

        if ('' !== $empire && !\in_array($empire, $this->getAvailableEmpires(), true)) {
            $this->error = sprintf('Empire "%s" is not available.', $empire);

            return;
        }

        $this->players[] = ['name' => ucfirst(trim($this->newPlayerName)), 'empire' => $empire];
        $this->newPlayerName = '';
        $this->newPlayerEmpire = '';
        $this->error = null;

        /** @var list<string> $validatedFields */
        $validatedFields = $this->validatedFields;
        $this->validatedFields = array_values(array_diff($validatedFields, ['newPlayerName']));
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

        $remaining = $this->getAvailableEmpires();

        if ([] === $remaining) {
            return;
        }

        $this->players[$index]['empire'] = $remaining[random_int(0, \count($remaining) - 1)];
    }

    /**
     * The row's own select writes straight into $players[$index]['empire'] via its
     * data-model path, so by the time this runs the candidate is already stored —
     * this is a post-write guard, not a gate before the assignment lands. It clears
     * the row back to unassigned when the value is illegitimate: not part of the
     * current scenario, or already held by another row (a crafted request, since
     * the row's own option list never offers either).
     */
    #[LiveAction]
    public function setEmpire(#[LiveArg] int $index): void
    {
        if (!isset($this->players[$index])) {
            return;
        }

        $empire = $this->players[$index]['empire'];

        if ('' === $empire) {
            return;
        }

        $scenarioEmpires = $this->scenarioRegistry->empiresFor($this->game->playerCount, $this->game->region);
        $takenByOthers = array_column(
            array_filter($this->players, static fn (array $player, int $i): bool => $i !== $index, ARRAY_FILTER_USE_BOTH),
            'empire',
        );

        if (!\in_array($empire, $scenarioEmpires, true) || \in_array($empire, $takenByOthers, true)) {
            $this->error = sprintf('Empire "%s" is not available.', $empire);
            $this->players[$index]['empire'] = '';
        }
    }

    #[LiveAction]
    public function assignRandomEmpires(): void
    {
        $remaining = $this->getAvailableEmpires();
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
        if (!$this->canLaunch()) {
            $this->validateField('game.slug', false);

            $issues = $this->getConformityIssues();

            if ([] !== $issues) {
                $this->error = implode(' ', $issues);
            }

            return null;
        }

        $this->game->players = $this->players;

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

        return new RedirectResponse($this->urlGenerator->generate('app_game_dashboard', ['slug' => $this->game->slug]));
    }

    public function isPlayerLimitReached(): bool
    {
        return \count($this->players) >= $this->game->playerCount;
    }

    public function canLaunch(): bool
    {
        return 0 === \count($this->validator->validate($this->game)) && [] === $this->getConformityIssues();
    }

    public function canAssignRandomEmpires(): bool
    {
        if ([] === $this->getAvailableEmpires()) {
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

    /** @return list<string> */
    public function getConformityIssues(): array
    {
        return array_values(array_filter([
            $this->getPlayerCountMismatchIssue(),
            ...$this->getInvalidEmpireIssues(),
            ...$this->getDuplicateEmpireIssues(),
            $this->getMissingEmpireIssue(),
        ], static fn (?string $issue): bool => null !== $issue));
    }

    /** @return list<string> */
    public function getAvailableEmpires(): array
    {
        return array_values(array_diff(
            $this->scenarioRegistry->empiresFor($this->game->playerCount, $this->game->region),
            array_column($this->players, 'empire'),
        ));
    }

    /** @return list<string> */
    public function getEmpireChoicesFor(int $index): array
    {
        $current = $this->players[$index]['empire'] ?? '';

        if ('' === $current) {
            return $this->getAvailableEmpires();
        }

        return [...$this->getAvailableEmpires(), $current];
    }

    /** @return list<array{value: string, label: string}> */
    public function getRegionChoices(): array
    {
        if ([] === $this->scenarioRegistry->regionsFor($this->game->playerCount)) {
            return [['value' => '', 'label' => 'East + West']];
        }

        return array_map(
            static fn (string $region): array => ['value' => $region, 'label' => ucfirst($region)],
            ['west', 'east'],
        );
    }

    /** @return list<string> */
    public function getScenarioSummary(): array
    {
        return $this->scenarioRuleSummarizer->summarize($this->game);
    }

    public static function slugify(string $value): string
    {
        return strtolower((string) new AsciiSlugger()->slug($value));
    }

    private function getPlayerCountMismatchIssue(): ?string
    {
        $count = \count($this->players);
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

    /** @return list<string> */
    private function getInvalidEmpireIssues(): array
    {
        $scenarioEmpires = $this->scenarioRegistry->empiresFor($this->game->playerCount, $this->game->region);
        $issues = [];

        foreach ($this->players as $player) {
            if ('' !== $player['empire'] && !\in_array($player['empire'], $scenarioEmpires, true)) {
                $issues[] = sprintf('%s\'s empire "%s" is not part of the current scenario.', $player['name'], $player['empire']);
            }
        }

        return $issues;
    }

    /** @return list<string> */
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
        $missing = \count(array_filter($this->players, static fn (array $player): bool => '' === $player['empire']));

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
        return $this->gameRegistry->getLimits();
    }
}
