<?php

declare(strict_types=1);

namespace App\Game;

use App\Game\Dto\AstEraDefinition;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class AstCatalog
{
    /**
     * @var null|array{
     *     ast?: array{
     *         eras?: array<string, array{
     *             name?: string,
     *             span?: int,
     *             requirements?: array{
     *                 basic?: array<string, int>,
     *                 expert?: array<string, int>,
     *             },
     *         }>,
     *     },
     * }
     */
    private ?array $astData = null;

    /** @var null|list<AstEraDefinition> */
    private ?array $eras = null;

    /** @var null|list<AstEraDefinition> */
    private ?array $columnEras = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/game/ast.yaml')]
        private readonly string $astDataPath,
    ) {}

    public function getTrackLength(): int
    {
        return array_sum(array_map(static fn (AstEraDefinition $era): int => $era->span, $this->getEras()));
    }

    /** @return list<AstEraDefinition> */
    public function getEras(): array
    {
        if (null !== $this->eras) {
            return $this->eras;
        }

        $erasData = $this->loadAstData()['ast']['eras'] ?? [];
        $eras = [];
        $index = 0;

        foreach ($erasData as $key => $era) {
            $eras[] = $this->hydrateEra($key, $era, $index);
            ++$index;
        }

        $this->eras = $eras;

        return $this->eras;
    }

    /** @return list<AstEraDefinition> one entry per track position, in file order */
    public function getColumnEras(): array
    {
        if (null !== $this->columnEras) {
            return $this->columnEras;
        }

        $columnEras = [];

        foreach ($this->getEras() as $era) {
            for ($i = 0; $i < $era->span; ++$i) {
                $columnEras[] = $era;
            }
        }

        $this->columnEras = $columnEras;

        return $this->columnEras;
    }

    public function getEraForPosition(int $position): AstEraDefinition
    {
        $columnEras = $this->getColumnEras();

        if ([] === $columnEras) {
            throw new \RuntimeException('Invalid AST configuration: no eras defined');
        }

        $clampedPosition = max(0, min($position, count($columnEras) - 1));

        return $columnEras[$clampedPosition];
    }

    /**
     * @param array{
     *     name?: string,
     *     span?: int,
     *     requirements?: array{
     *         basic?: array<string, int>,
     *         expert?: array<string, int>,
     *     },
     * } $era
     */
    private function hydrateEra(string $key, array $era, int $index): AstEraDefinition
    {
        return new AstEraDefinition(
            key: $key,
            name: $era['name'] ?? '',
            span: $era['span'] ?? 0,
            basicRequirements: $era['requirements']['basic'] ?? [],
            expertRequirements: $era['requirements']['expert'] ?? [],
            index: $index,
        );
    }

    /**
     * @return array{
     *     ast?: array{
     *         eras?: array<string, array{
     *             name?: string,
     *             span?: int,
     *             requirements?: array{
     *                 basic?: array<string, int>,
     *                 expert?: array<string, int>,
     *             },
     *         }>,
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
