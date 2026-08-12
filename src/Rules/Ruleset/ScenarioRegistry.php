<?php

declare(strict_types=1);

namespace App\Rules\Ruleset;

use App\State\Region;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

final class ScenarioRegistry
{
    private const string CREDITS_KEY = 'credits';

    /** @var null|array<int|string, mixed> */
    private ?array $scenarios = null;

    public function __construct(#[Autowire('%kernel.project_dir%/config/game/scenarios.yaml')] private readonly string $scenariosPath) {}

    public function find(int $playerCount, ?Region $region): ?Scenario
    {
        $key = $region instanceof Region ? $playerCount.'_'.$region->value : $playerCount;
        $empires = $this->normalizeToFlatList($this->getScenarios()[$key] ?? null);

        if ([] === $empires) {
            return null;
        }

        return new Scenario(
            playerCount: $playerCount,
            blocks: $region instanceof Region ? [$region] : Region::cases(),
            empires: $empires,
            startingCredits: $this->startingCreditsFor($playerCount),
        );
    }

    /** @return list<Scenario> */
    public function forPlayerCount(int $playerCount): array
    {
        $combined = $this->find($playerCount, null);

        if ($combined instanceof Scenario) {
            return [$combined];
        }

        return array_values(array_filter(array_map(
            fn (Region $region): ?Scenario => $this->find($playerCount, $region),
            Region::cases(),
        )));
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

    /** @return array<string, int> */
    private function startingCreditsFor(int $playerCount): array
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
