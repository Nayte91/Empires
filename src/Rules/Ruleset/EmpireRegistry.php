<?php

declare(strict_types=1);

namespace App\Rules\Ruleset;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

final class EmpireRegistry
{
    public const string SORT_BY_POSITION = 'position';
    public const string SORT_BY_NAME = 'name';

    /**
     * @var null|array<string, array{
     *     position: int,
     *     name: string,
     *     demonym: string,
     *     adjective: string,
     *     people_icon: ?string,
     *     ship_icon: ?string,
     *     city_icon: ?string,
     *     color: string,
     * }>
     */
    private ?array $empiresData = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/game/empires.yaml')]
        private readonly string $empiresConfigPath,
        private readonly ScenarioRegistry $scenarioRegistry,
    ) {}

    /** @return array<string, Empire> */
    public function findAll(string $sortBy = self::SORT_BY_POSITION): array
    {
        $empiresArray = $this->getEmpiresData();

        if ('name' === $sortBy) {
            uasort($empiresArray, static fn ($a, $b): int => $a['name'] <=> $b['name']);
        }

        return array_map(
            $this->hydrateEmpire(...),
            $empiresArray
        );
    }

    public function findByPosition(int $position): ?Empire
    {
        $empiresArray = $this->getEmpiresData();

        foreach ($empiresArray as $empireData) {
            if ($empireData['position'] === $position) {
                return $this->hydrateEmpire($empireData);
            }
        }

        return null;
    }

    public function findByName(string $name): ?Empire
    {
        $empiresArray = $this->getEmpiresData();

        foreach ($empiresArray as $empireData) {
            if ($empireData['name'] === $name) {
                return $this->hydrateEmpire($empireData);
            }
        }

        return null;
    }

    public function positionOf(?string $name): int
    {
        return null === $name ? PHP_INT_MAX : ($this->findByName($name)->position ?? PHP_INT_MAX);
    }

    /** @return list<Empire> */
    public function findByPlayerCountAndRegion(int $playerCount, ?string $region): array
    {
        $slugs = $this->scenarioRegistry->empiresFor($playerCount, $region);

        return array_values(array_filter(
            array_map($this->findByName(...), $slugs),
            static fn (?Empire $empire): bool => $empire instanceof Empire,
        ));
    }

    /**
     * @return array<string, array{
     *     position: int,
     *     name: string,
     *     demonym: string,
     *     adjective: string,
     *     people_icon: ?string,
     *     ship_icon: ?string,
     *     city_icon: ?string,
     *     color: string,
     * }>
     */
    private function getEmpiresData(): array
    {
        if (null === $this->empiresData) {
            $data = Yaml::parseFile($this->empiresConfigPath);

            if (!isset($data['empires']) || !is_array($data['empires'])) {
                throw new \RuntimeException('Invalid empires configuration file');
            }

            /**
             * @var array<string, array{
             *     position: int,
             *     name: string,
             *     demonym: string,
             *     adjective: string,
             *     people_icon: ?string,
             *     ship_icon: ?string,
             *     city_icon: ?string,
             *     color: string,
             * }> $empires
             */
            $empires = $data['empires'];
            $this->empiresData = $empires;
        }

        return $this->empiresData;
    }

    /**
     * @param array{
     *     position: int,
     *     name: string,
     *     demonym: string,
     *     adjective: string,
     *     people_icon: ?string,
     *     ship_icon: ?string,
     *     city_icon: ?string,
     *     color: string,
     * } $data
     */
    private function hydrateEmpire(array $data): Empire
    {
        return new Empire(
            position: $data['position'],
            name: $data['name'],
            demonym: $data['demonym'],
            adjective: $data['adjective'],
            peopleIcon: $data['people_icon'],
            shipIcon: $data['ship_icon'],
            cityIcon: $data['city_icon'],
            color: $data['color'],
        );
    }
}
