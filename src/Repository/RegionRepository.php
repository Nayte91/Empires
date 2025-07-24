<?php

namespace App\Repository;

use App\Entity\Civilization;
use App\Entity\Region;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

class RegionRepository {
    private ?array $regionsData = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/game/game_data.yaml')]
        private readonly string $regionsConfigPath,
        private readonly CivilizationRepository $civilizationRepository
    ) {}

    public function findAll(): array {
        $regionsArray = $this->getRegionsData();

        return array_map(
            fn(array $data): Region => $this->hydrateRegion($data),
            $regionsArray
        );
    }

    public function findByPlayerCount(int $playerCount): array
    {
        match (true) {
            $playerCount <= 9 => $regionsArray = ['east', 'west'],
            $playerCount >= 10 => $regionsArray = [],
        };

        return array_map(
            fn(string $regionName): Region => $this->findByName($regionName),
            $regionsArray
        );
    }

    public function findByName(string $regionName): ?Region
    {
        $regionsArray = $this->getRegionsData();

        return array_key_exists($regionName, $regionsArray) ? $this->hydrateRegion($regionsArray[$regionName]) : null;
    }

    public function findByCivilization(string $civilizationName): ?Region
    {
        $regionsArray = $this->getRegionsData();

        foreach ($regionsArray as $regionName => $regionData) {
            if (in_array($civilizationName, $regionData['civilizations'])) { return $this->hydrateRegion($regionsArray[$regionName]); }
        }

        return null;
    }

    private function getRegionsData(): array
    {
        if ($this->regionsData === null) {
            $data = Yaml::parseFile($this->regionsConfigPath);

            if (!isset($data['regions']) || !is_array($data['regions'])) {
                throw new \RuntimeException('Invalid regions configuration file');
            }

            $this->regionsData = $data['regions'];
        }

        return $this->regionsData;
    }

    private function hydrateRegion(array $data): Region
    {
        $region = new Region(
            name: $data['name'],
            civilizations: array_map(
                fn(string $name): Civilization => $this->civilizationRepository->findByName($name),
                $data['civilizations']
            )
        );

        return $region;
    }
}
