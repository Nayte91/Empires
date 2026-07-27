<?php

declare(strict_types=1);

namespace App\Rules\Ruleset;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

// REFACTOR-WHEN: a 3rd responsibility joins limits/regions — split them (regions -> ScenarioCatalog,
// limits -> a dedicated class) instead of growing this class further.
final readonly class GameData
{
    public function __construct(#[Autowire('%kernel.project_dir%/config/game/game_data.yaml')] private string $gameDataPath) {}

    /**
     * @return array{
     *     max_cities?: int,
     *     max_population?: int,
     *     max_ships?: int,
     *     modes?: list<string>,
     *     min_players?: int,
     *     max_players?: int,
     *     hand_size_brackets?: list<array{from_players: int, cards: int}>,
     * }
     */
    public function getLimits(): array
    {
        return $this->loadGameData()['limits'] ?? [];
    }

    /**
     * @return array<string, array{
     *     name: string,
     *     name2019: string,
     *     name2024: string,
     *     empires: list<string>,
     * }>
     */
    public function getRegions(): array
    {
        $data = $this->loadGameData();

        return $data['regions'] ?? [];
    }

    /**
     * @return array{
     *     regions?: array<string, array{
     *         name: string,
     *         name2019: string,
     *         name2024: string,
     *         empires: list<string>,
     *     }>,
     *     limits?: array{
     *         max_cities?: int,
     *         max_population?: int,
     *         max_ships?: int,
     *         modes?: list<string>,
     *         min_players?: int,
     *         max_players?: int,
     *         hand_size_brackets?: list<array{from_players: int, cards: int}>,
     *     },
     * }
     */
    private function loadGameData(): array
    {
        static $cache = null;

        if (null === $cache) {
            $cache = yaml_parse_file($this->gameDataPath);
        }

        return $cache;
    }
}
