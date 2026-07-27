<?php

declare(strict_types=1);

namespace App\Game;

use App\Game\Dto\AstEraDefinition;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class AstCatalog
{
    private const array ERA_NAMES = [
        'start' => 'Start',
        'stone_age' => 'Stone Age',
        'early_bronze_age' => 'Early Bronze Age',
        'middle_bronze_age' => 'Middle Bronze Age',
        'late_bronze_age' => 'Late Bronze Age',
        'early_iron_age' => 'Early Iron Age',
        'late_iron_age' => 'Late Iron Age',
    ];

    /**
     * @var null|array{
     *     ast?: array{
     *         requirements?: array<string, array{
     *             basic?: array<string, int>,
     *             expert?: array<string, int>,
     *         }>,
     *         tracks?: array<string, array<string, array{
     *             empires?: list<string>,
     *             spans?: array<string, int>,
     *         }>>,
     *     },
     * }
     */
    private ?array $astData = null;

    /** @var null|list<AstEraDefinition> */
    private ?array $eras = null;

    /** @var array<string, list<AstEraDefinition>> keyed by "{version}:{group}" */
    private array $columnEras = [];

    public function __construct(#[Autowire('%kernel.project_dir%/config/game/ast.yaml')] private readonly string $astDataPath) {}

    public function resolveEmpireGroup(ASTVersion $version, ?string $empireSlug): string
    {
        if (null === $empireSlug) {
            return 'standard';
        }

        $groups = $this->loadAstData()['ast']['tracks'][$version->value] ?? [];

        foreach ($groups as $group => $groupData) {
            if (in_array($empireSlug, $groupData['empires'] ?? [], true)) {
                return $group;
            }
        }

        return 'standard';
    }

    public function getTrackLength(ASTVersion $version, string $group): int
    {
        return array_sum($this->getSpans($version, $group));
    }

    /** @return list<AstEraDefinition> */
    public function getEras(): array
    {
        if (null !== $this->eras) {
            return $this->eras;
        }

        $requirements = $this->loadAstData()['ast']['requirements'] ?? [];
        $eras = [];
        $index = 0;

        foreach ($requirements as $key => $requirement) {
            $eras[] = new AstEraDefinition(
                key: $key,
                name: self::ERA_NAMES[$key] ?? $key,
                basicRequirements: $requirement['basic'] ?? [],
                expertRequirements: $requirement['expert'] ?? [],
                index: $index,
            );
            ++$index;
        }

        $this->eras = $eras;

        return $this->eras;
    }

    /** @return list<AstEraDefinition> one entry per track position, in file order */
    public function getColumnEras(ASTVersion $version, string $group): array
    {
        $cacheKey = "{$version->value}:{$group}";

        if (isset($this->columnEras[$cacheKey])) {
            return $this->columnEras[$cacheKey];
        }

        $spans = $this->getSpans($version, $group);
        $columnEras = [];

        foreach ($this->getEras() as $era) {
            for ($i = 0; $i < ($spans[$era->key] ?? 0); ++$i) {
                $columnEras[] = $era;
            }
        }

        $this->columnEras[$cacheKey] = $columnEras;

        return $columnEras;
    }

    public function getEraForPosition(int $position, ASTVersion $version, string $group): AstEraDefinition
    {
        $columnEras = $this->getColumnEras($version, $group);

        if ([] === $columnEras) {
            throw new \RuntimeException('Invalid AST configuration: no eras defined');
        }

        $clampedPosition = max(0, min($position, count($columnEras) - 1));

        return $columnEras[$clampedPosition];
    }

    /** @return array<string, int> */
    private function getSpans(ASTVersion $version, string $group): array
    {
        return $this->loadAstData()['ast']['tracks'][$version->value][$group]['spans'] ?? [];
    }

    /**
     * @return array{
     *     ast?: array{
     *         requirements?: array<string, array{
     *             basic?: array<string, int>,
     *             expert?: array<string, int>,
     *         }>,
     *         tracks?: array<string, array<string, array{
     *             empires?: list<string>,
     *             spans?: array<string, int>,
     *         }>>,
     *     },
     * }
     */
    private function loadAstData(): array
    {
        if (null !== $this->astData) {
            return $this->astData;
        }

        $data = yaml_parse_file($this->astDataPath);
        $this->astData = false === $data ? [] : $data;

        return $this->astData;
    }
}
