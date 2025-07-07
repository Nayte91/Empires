<?php

namespace App\Repository;

use App\Entity\Civilization;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

class Civilizations {
    public const string SORT_BY_POSITION = 'position';
    public const string SORT_BY_NAME = 'name';

    private ?array $civilizationsData = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/game/civilizations.yaml')]
        private readonly string $civilizationsConfigPath
    ) {}

    public function getCivilizations(string $sortBy = self::SORT_BY_POSITION): array {
        $civilizationsArray = $this->getCivilizationsData();

        if ($sortBy === 'name') {
            uasort($civilizationsArray, fn($a, $b) => $a['name'] <=> $b['name']);
        }

        return array_map(
            fn(array $data): Civilization => $this->hydrateCivilization($data),
            $civilizationsArray
        );
    }

    public function getCivilizationByPosition(int $position): ?Civilization
    {
        $civilizationsArray = $this->getCivilizationsData();

        foreach ($civilizationsArray as $civilizationData) {
            if ($civilizationData['position'] === $position) {
                return $this->hydrateCivilization($civilizationData);
            }
        }

        return null;
    }

    private function getCivilizationsData(): array
    {
        if ($this->civilizationsData === null) {
            $data = Yaml::parseFile($this->civilizationsConfigPath);

            if (!isset($data['civilizations']) || !is_array($data['civilizations'])) {
                throw new \RuntimeException('Invalid civilizations configuration file');
            }

            $this->civilizationsData = $data['civilizations'];
        }

        return $this->civilizationsData;
    }

    private function hydrateCivilization(array $data): Civilization
    {
        $civilization = new Civilization;
        $civilization->position = $data['position'];
        $civilization->name = $data['name'];
        $civilization->demonym = $data['demonym'];
        $civilization->adjective = $data['adjective'];
        $civilization->peopleIcon = $data['people_icon'];
        $civilization->shipIcon = $data['ship_icon'];
        $civilization->cityIcon = $data['city_icon'];
        $civilization->color = $data['color'];

        return $civilization;
    }
}
