<?php

namespace App\Repository;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class GameData
{
    public function __construct(#[Autowire('%kernel.project_dir%/config/game/game_data.yaml')] private string $gameDataPath) {}

    public function getLimits(): array
    {
        return $this->loadGameData()['limits'] ?? [];
    }

    public function getRegions(): ?array
    {
        $data = $this->loadGameData();
        return $data['regions'] ?? [];
    }

    public function getCivilizationsByRegionAndPlayers(string $region): array
    {
        $regions = $this->getRegions();
        return $regions[$region]['civilizations'] ?? [];
    }

    private function loadGameData(): array
    {
        static $cache = null;

        if ($cache === null) {
            $cache = yaml_parse_file($this->gameDataPath);
        }

        return $cache;
    }
}
