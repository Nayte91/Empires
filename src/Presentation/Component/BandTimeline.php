<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Rules\Ruleset\AstEraDefinition;
use App\Rules\Ruleset\AstRegistry;
use App\Rules\ScoreCalculator;
use App\State\ASTVersion;
use App\State\Game;
use App\State\Player;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The A.S.T. read at phone width: the real track, one column per position — sixteen in basic,
 * seventeen in expert — so a marker sits where it actually stands rather than in a collapsed band.
 *
 * The boundaries across the top are the standard group's, as on the wide board. Which era a
 * position belongs to still varies by setup, and that is answered per player rather than drawn.
 */
#[AsLiveComponent(template: 'molecules/bandTimeline.html.twig')]
final class BandTimeline
{
    use DefaultActionTrait;

    /** The board draws one set of boundaries, the same one the wide A.S.T. draws. */
    private const string SHARED_LAYOUT_GROUP = 'standard';

    /**
     * A column header has room for three letters and nothing else. Everywhere with room spells the
     * era out, so this is a display spelling of the name rather than a second name for it.
     *
     * @var array<string, string>
     */
    private const array BAND_ABBREVIATIONS = [
        'start' => 'ST',
        'stone_age' => 'STN',
        'early_bronze_age' => 'EBA',
        'middle_bronze_age' => 'MBA',
        'late_bronze_age' => 'LBA',
        'early_iron_age' => 'EIA',
        'late_iron_age' => 'LIA',
    ];

    /** How each requirement reads. The list has the width to say what it means. */
    private const array REQUIREMENT_FORMATS = [
        'cities' => '%d cities',
        'advances' => '%d advances',
        'advance_points' => '%d advance points',
        'min_advance_cost' => 'min cost %d',
        'max_advance_cost' => 'max cost %d',
    ];

    #[LiveProp]
    public Game $game; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    public function __construct(private readonly AstRegistry $astRegistry) {}

    /** @return list<AstEraDefinition> */
    public function getBands(): array
    {
        return $this->astRegistry->getEras();
    }

    /**
     * The shared track, one entry per position: sixteen columns in basic, seventeen in expert.
     * Era boundaries are the standard group's, exactly as the wide board draws them — a single
     * grid cannot show three sets of boundaries at once without reading as three tables.
     *
     * @return list<AstEraDefinition>
     */
    public function getColumns(): array
    {
        return $this->astRegistry->getColumnEras($this->game->astVersion, self::SHARED_LAYOUT_GROUP);
    }

    /**
     * The eras across the top, each as wide as it runs.
     *
     * @return list<array{era: AstEraDefinition, span: int}>
     */
    public function getHeaders(): array
    {
        $headers = [];
        $current = null;
        $span = 0;

        foreach ($this->getColumns() as $era) {
            if (null !== $current && $current->key !== $era->key) {
                $headers[] = ['era' => $current, 'span' => $span];
                $span = 0;
            }

            $current = $era;
            ++$span;
        }

        if (null !== $current) {
            $headers[] = ['era' => $current, 'span' => $span];
        }

        return $headers;
    }

    /**
     * One row per player, marked on the position they stand on and carrying its own reading of the
     * track. The columns line up for everyone — a position is a position — but where one age ends
     * and the next begins is the empire's own business: a Minoan on column 5 is still in the Stone
     * Age where a standard empire has already reached the Early Bronze Age.
     *
     * @return list<array{player: Player, position: int, era: AstEraDefinition, columns: list<AstEraDefinition>}>
     */
    public function getRows(): array
    {
        return array_map(
            function (Player $player): array {
                $columns = $this->astRegistry->getColumnEras($this->game->astVersion, $this->groupOf($player));
                $position = max(0, min($player->astPosition, \count($columns) - 1));

                return [
                    'player' => $player,
                    'position' => $position,
                    'era' => $columns[$position],
                    'columns' => $columns,
                ];
            },
            $this->game->players->toArray(),
        );
    }

    /**
     * What each position on the track is worth. One scale serves every empire: a position is
     * priced by how far along it is, not by which era a given setup calls it.
     *
     * @return list<int>
     */
    public function getScale(): array
    {
        return array_map(
            static fn (int $position): int => $position * ScoreCalculator::POINTS_PER_AST_POSITION,
            range(0, $this->getTrackLength() - 1),
        );
    }

    /** Every track of one version is the same length; only the era boundaries move. */
    public function getTrackLength(): int
    {
        return \count($this->getColumns());
    }

    /** 'EBA' — the three letters a column header has room for. */
    public function abbreviationOf(AstEraDefinition $band): string
    {
        return self::BAND_ABBREVIATIONS[$band->key] ?? mb_strtoupper(mb_substr($band->key, 0, 3));
    }

    /** The band a requirement list belongs to is met once somebody has actually reached it. */
    public function isReached(AstEraDefinition $band): bool
    {
        $highest = 0;

        foreach ($this->game->players as $player) {
            $columns = $this->astRegistry->getColumnEras($this->game->astVersion, $this->groupOf($player));
            $position = max(0, min($player->astPosition, \count($columns) - 1));
            $highest = max($highest, $columns[$position]->index);
        }

        return $band->index <= $highest;
    }

    /** '3 cities · 3 advances · min cost 100', or null when the band asks nothing of anyone. */
    public function requirementOf(AstEraDefinition $band): ?string
    {
        $requirements = ASTVersion::EXPERT === $this->game->astVersion ? $band->expertRequirements : $band->basicRequirements;

        if ([] === $requirements) {
            return null;
        }

        $parts = [];

        foreach ($requirements as $key => $value) {
            $parts[] = \sprintf(self::REQUIREMENT_FORMATS[$key] ?? '%d '.$key, $value);
        }

        return implode(' · ', $parts);
    }

    private function groupOf(Player $player): string
    {
        return $this->astRegistry->resolveEmpireGroup($this->game->astVersion, $player->empire);
    }
}
