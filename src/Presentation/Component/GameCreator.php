<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Presentation\CreationSummarizer;
use App\Rules\Action\CreateGame;
use App\Rules\Ruleset\Scenario;
use App\Rules\Ruleset\ScenarioRegistry;
use App\State\Game;
use App\State\Player;
use App\State\Region;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
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

#[AsLiveComponent(template: 'organisms/GameCreator.html.twig')]
final class GameCreator
{
    use DefaultActionTrait;
    use ValidatableComponentTrait;

    #[Assert\Valid]
    #[LiveProp(writable: ['slug', 'playerCount', 'region', 'astVersion'], useSerializerForHydration: true, onUpdated: ['slug' => 'onSlugUpdated', 'playerCount' => 'onScenarioUpdated', 'region' => 'onScenarioUpdated'])]
    public CreateGame $game; // @phpstan-ignore property.uninitialized (initialized in mount())

    #[LiveProp(writable: true)]
    #[Assert\Sequentially([
        new Assert\NotBlank(message: 'Player name is required.', normalizer: [Player::class, 'slugify']),
        new Assert\Length(max: Player::MAX_NAME_LENGTH, maxMessage: 'Name cannot be longer than {{ limit }} characters.'),
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
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly MessageBusInterface $commandBus,
        private readonly ValidatorInterface $validator,
        private readonly CreationSummarizer $creationSummarizer,
    ) {}

    public function mount(): void
    {
        $this->game = new CreateGame();
        $this->game->slug = (string) Uuid::v7();
    }

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
        $this->game->slug = Game::slugify($this->game->slug);
        $this->validateField('game.slug', false);
    }

    public function isSlugAvailable(): bool
    {
        return 0 === \count($this->validator->validateProperty($this->game, 'slug'));
    }

    public function hasPlayerNamed(string $name): bool
    {
        $slug = Player::slugify($name);

        return array_any($this->players, static fn (array $player): bool => Player::slugify($player['name']) === $slug);
    }

    public function onScenarioUpdated(): void
    {
        $scenarios = $this->scenarioRegistry->forPlayerCount($this->game->playerCount);

        if ([] === $scenarios) {
            $this->game->region = null;

            return;
        }

        $blocks = array_map(static fn (Scenario $scenario): ?Region => $scenario->soleBlock(), $scenarios);

        if (!\in_array($this->selectedRegion(), $blocks, true)) {
            $this->game->region = $blocks[0]?->value;
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

        $scenarioEmpires = $this->scenarioEmpires();
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

    /**
     * The playable counts are a set — whichever the ruleset happens to describe — but a number
     * input can only carry a range, so the widget gets its ends. A hole in the middle, if the
     * ruleset ever had one, is caught by KnownScenario at launch and by nothing here.
     */
    /** The bound is declared once, by the scenarios the ruleset describes; the input carries their ends because a number field cannot carry a set. */
    public function getMinPlayers(): int
    {
        return $this->playableCounts()[0] ?? 1;
    }

    public function getMaxPlayers(): int
    {
        $counts = $this->playableCounts();

        return [] === $counts ? PHP_INT_MAX : $counts[\count($counts) - 1];
    }

    /** @return list<string> */
    public function getConformityIssues(): array
    {
        // REFACTOR-WHEN: a conformity issue needs per-type styling or ordering in the template.
        // Each builder below assembles its issue with sprintf into a finished string, so the
        // template and the component tests can only discriminate issues by wording; crossing
        // the threshold means returning code + parameters per issue instead.
        return array_values(array_filter([
            $this->getPlayerCountOutOfRangeIssue(),
            $this->getPlayerCountMismatchIssue(),
            ...$this->getInvalidEmpireIssues(),
            ...$this->getDuplicateEmpireIssues(),
            $this->getBlankNameIssue(),
            ...$this->getDuplicateNameIssues(),
            ...$this->getOverlongNameIssues(),
            $this->getMissingEmpireIssue(),
        ], static fn (?string $issue): bool => null !== $issue));
    }

    /** @return list<string> */
    public function getAvailableEmpires(): array
    {
        return array_values(array_diff(
            $this->scenarioEmpires(),
            array_column($this->players, 'empire'),
        ));
    }

    /** @return list<array{empire: string, taken: bool}> */
    public function getEmpireChoicesFor(int $index): array
    {
        $takenByOthers = array_column(
            array_filter($this->players, static fn (array $player, int $i): bool => $i !== $index, ARRAY_FILTER_USE_BOTH),
            'empire',
        );

        return array_map(
            static fn (string $empire): array => ['empire' => $empire, 'taken' => \in_array($empire, $takenByOthers, true)],
            $this->scenarioEmpires(),
        );
    }

    /**
     * A scenario on both boxes names no single region, so it carries the empty value the model
     * reads back as null.
     *
     * @return list<array{value: string, label: string}>
     */
    public function getRegionChoices(): array
    {
        return array_map(
            static fn (Scenario $scenario): array => [
                'value' => $scenario->soleBlock()->value ?? '',
                'label' => implode(' + ', array_map(static fn (Region $block): string => ucfirst($block->value), $scenario->blocks)),
            ],
            $this->scenarioRegistry->forPlayerCount($this->game->playerCount),
        );
    }

    /** @return list<string> */
    public function getScenarioSummary(): array
    {
        return $this->creationSummarizer->summarize($this->game);
    }

    /**
     * The one place the client's string becomes a region. It stays a string on CreateGame because a
     * writable LiveComponent path may only carry a scalar (LiveComponentHydrator), so a crafted
     * value arrives here intact: tryFrom() turns it into no region at all, which addresses no
     * scenario, which leaves every player outside it and the launch refused.
     */
    private function selectedRegion(): ?Region
    {
        return null === $this->game->region ? null : Region::tryFrom($this->game->region);
    }

    /** @return list<string> */
    private function scenarioEmpires(): array
    {
        return $this->scenarioRegistry->find($this->game->playerCount, $this->selectedRegion())->empires ?? [];
    }

    private function getPlayerCountOutOfRangeIssue(): ?string
    {
        $count = $this->game->playerCount;
        $min = $this->getMinPlayers();
        $max = $this->getMaxPlayers();

        if ($count < $min) {
            return sprintf('Player count must be at least %d.', $min);
        }

        if ($count > $max) {
            return sprintf('Player count must be at most %d.', $max);
        }

        return null;
    }

    private function getPlayerCountMismatchIssue(): ?string
    {
        $count = \count($this->players);
        $target = $this->game->playerCount;

        if ($count === $target) {
            return null;
        }

        if ($count < $target) {
            $missing = $target - $count;
            $message = sprintf('Add %d more %s', $missing, 1 === $missing ? 'player' : 'players');

            if ($count >= $this->getMinPlayers()) {
                return $message.sprintf(', or lower the player count to %d.', $count);
            }

            return $message.'.';
        }

        $extra = $count - $target;
        $message = sprintf('Remove %d %s', $extra, 1 === $extra ? 'player' : 'players');

        if ($count <= $this->getMaxPlayers()) {
            return $message.sprintf(', or raise the player count to %d.', $count);
        }

        return $message.'.';
    }

    /** @return list<string> */
    private function getInvalidEmpireIssues(): array
    {
        $scenarioEmpires = $this->scenarioEmpires();
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

    /** @return list<string> */
    private function getDuplicateNameIssues(): array
    {
        $namesBySlug = [];

        foreach ($this->players as $player) {
            $namesBySlug[Player::slugify($player['name'])][] = $player['name'];
        }

        $issues = [];

        foreach ($namesBySlug as $slug => $names) {
            if ('' === $slug) {
                continue;
            }
            if (\count($names) < 2) {
                continue;
            }
            $last = array_pop($names);
            $issues[] = sprintf('%s share the name "%s".', implode(', ', $names).' and '.$last, $slug);
        }

        return $issues;
    }

    private function getBlankNameIssue(): ?string
    {
        $blank = \count(array_filter($this->players, static fn (array $player): bool => '' === Player::slugify($player['name'])));

        if (0 === $blank) {
            return null;
        }

        return 1 === $blank
            ? '1 player has no usable name.'
            : sprintf('%d players have no usable name.', $blank);
    }

    /** @return list<string> */
    private function getOverlongNameIssues(): array
    {
        $issues = [];

        foreach ($this->players as $player) {
            if (mb_strlen($player['name']) > Player::MAX_NAME_LENGTH) {
                $issues[] = sprintf('%s\'s name is longer than %d characters.', $player['name'], Player::MAX_NAME_LENGTH);
            }
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

    /** @return list<int> */
    private function playableCounts(): array
    {
        return $this->scenarioRegistry->playerCounts();
    }
}
