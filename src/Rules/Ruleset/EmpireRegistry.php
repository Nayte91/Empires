<?php

declare(strict_types=1);

namespace App\Rules\Ruleset;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

final class EmpireRegistry
{
    /**
     * @var null|array<string, array{
     *     position: int,
     *     name: string,
     *     demonym: string,
     *     adjective: string,
     *     color: string,
     * }>
     */
    private ?array $empiresData = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/game/empires.yaml')]
        private readonly string $empiresConfigPath,
    ) {}

    /** @return array<string, Empire> */
    public function findAll(): array
    {
        return array_map(
            $this->hydrateEmpire(...),
            $this->getEmpiresData()
        );
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

    public function positionOf(string $name): int
    {
        return $this->findByName($name)->position ?? PHP_INT_MAX;
    }

    /**
     * @return array<string, array{
     *     position: int,
     *     name: string,
     *     demonym: string,
     *     adjective: string,
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
            color: $data['color'],
        );
    }
}
