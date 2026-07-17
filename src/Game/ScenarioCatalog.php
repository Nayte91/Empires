<?php

declare(strict_types=1);

namespace App\Game;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

final class ScenarioCatalog
{
    private const string CREDITS_KEY = 'credits';

    /** @var null|array<int|string, mixed> */
    private ?array $scenarios = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/game/scenarios.yaml')]
        private readonly string $scenariosPath,
    ) {}

    /** @return list<string> */
    public function empiresFor(int $playerCount, ?string $region): array
    {
        if ($playerCount >= 10) {
            return $this->normalizeToFlatList($this->getScenarios()[$playerCount] ?? null);
        }

        if (null === $region) {
            return [];
        }

        return $this->normalizeToFlatList($this->getScenarios()[$playerCount.'_'.$region] ?? null);
    }

    /** @return list<int> */
    public function playerCounts(): array
    {
        $counts = [];

        foreach (array_keys($this->getScenarios()) as $key) {
            $count = $this->extractPlayerCount($key);

            if (null !== $count) {
                $counts[$count] = $count;
            }
        }

        ksort($counts);

        return array_values($counts);
    }

    /** @return list<string> */
    public function regionsFor(int $playerCount): array
    {
        $scenarios = $this->getScenarios();
        $regions = [];

        foreach (['east', 'west'] as $region) {
            if (isset($scenarios[$playerCount.'_'.$region])) {
                $regions[] = $region;
            }
        }

        return $regions;
    }

    /** @return array<string, int> */
    public function startingCreditsFor(int $playerCount): array
    {
        $credits = $this->getScenarios()[$playerCount][self::CREDITS_KEY] ?? null;

        if (!is_array($credits)) {
            return [];
        }

        $result = [];

        foreach ($credits as $category => $amount) {
            if (is_string($category) && is_int($amount)) {
                $result[$category] = $amount;
            }
        }

        return $result;
    }

    /** @return list<string> */
    private function normalizeToFlatList(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }

        if (array_is_list($data)) {
            return array_values(array_filter($data, is_string(...)));
        }

        $flat = [];

        foreach ($data as $group) {
            if (!is_array($group)) {
                continue;
            }

            foreach ($group as $item) {
                if (is_string($item)) {
                    $flat[] = $item;
                }
            }
        }

        return $flat;
    }

    private function extractPlayerCount(int|string $key): ?int
    {
        if (is_int($key)) {
            return $key;
        }

        if (1 === preg_match('/^(\d+)_(?:east|west)$/', $key, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /** @return array<int|string, mixed> */
    private function getScenarios(): array
    {
        if (null !== $this->scenarios) {
            return $this->scenarios;
        }

        $data = Yaml::parseFile($this->scenariosPath);

        if (!isset($data['scenarios']) || !is_array($data['scenarios'])) {
            throw new \RuntimeException('Invalid scenarios configuration file');
        }

        /** @var array<int|string, mixed> $scenarios */
        $scenarios = $data['scenarios'];
        $this->scenarios = $scenarios;

        return $this->scenarios;
    }
}
