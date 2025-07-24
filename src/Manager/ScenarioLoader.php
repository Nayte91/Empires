<?php

namespace App\Manager;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

class ScenarioLoader {
    public function __construct(
        #[Autowire('%kernel.project_dir%/config/game/scenarios.yaml')] private string $scenariosDataPath
    ) {}

    public function getScenarios(): ?array
    {
        $data = $this->loadScenariosData();
        return $data['scenarios'];
    }

    public function getCivilizationsByPlayersAndRegion(int $playerCount, ?string $region): array
    {
        $scenarios = $this->getScenarios();

        if ($playerCount < 10) {
            $key = $playerCount . '_' . $region;
            return $scenarios[$key];
        }

        if ($playerCount > 12) {
            $civilizations = [];
            foreach ($scenarios[$playerCount] as $region) {
                $civilizations[] = $region;
            }

            return $civilizations;
        }

        return $scenarios[$playerCount];
    }

    private function loadScenariosData(): array
    {
        static $cache = null;

        if ($cache === null) {
            $cache = yaml_parse_file($this->scenariosDataPath);
        }

        return $cache;
    }
}
