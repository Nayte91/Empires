<?php

namespace App\Repository;

use App\Entity\Advance;
use Symfony\Component\Asset\Packages;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

final class AdvanceRepository {
    private const string IMAGES_PATH = 'images/advances/';
    private const string IMAGE_EXTENSION = '.webp';

    private ?array $advancesData = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/game/advances.yaml')]
        private string $advancesDataPath,
        private Packages $packages,
    ) {}

    public function getAdvances(): array
    {
        $advancesData = $this->getAdvancesData();

        $advances = array_map(
            fn(string $name, array $data): Advance => $this->hydrateAdvance($name, $data),
            array_keys($advancesData),
            $advancesData
        );

        return $this->sortByCost($advances);
    }

    public function getAdvanceByName(string $name): ?Advance
    {
        $normalizedName = str_replace(' ', '_', strtolower($name));
        $advancesData = $this->getAdvancesData();

        if (!isset($advancesData[$normalizedName])) {
            return null;
        }

        return $this->hydrateAdvance($normalizedName, $advancesData[$normalizedName]);
    }

    public function getAdvancesByNames(array $names): array
    {
        return array_filter(
            array_map(
                fn(string $name): ?Advance => $this->getAdvanceByName($name),
                $names
            )
        );
    }

    public function getAdvancesByCategories(array $categories): array
    {
        $advances = $this->getAdvances();

        return array_filter(
            $advances,
            static fn(Advance $advance): bool => !empty(array_intersect($advance->categories, $categories))
        );
    }

    public function getAdvancesByCostRange(int $minCost, int $maxCost): array
    {
        $advances = $this->getAdvances();

        return array_filter(
            $advances,
            static fn(Advance $advance): bool => $advance->cost >= $minCost && $advance->cost <= $maxCost
        );
    }

    private function getAdvancesData(): array
    {
        if ($this->advancesData === null) {
            $data = Yaml::parseFile($this->advancesDataPath);

            if (!isset($data['advances']) || !is_array($data['advances'])) {
                throw new \RuntimeException('Invalid advances configuration file');
            }

            $this->advancesData = $data['advances'];
        }

        return $this->advancesData;
    }

    private function hydrateAdvance(string $key, array $data): Advance
    {
        return new Advance(
            name: str_replace('_', ' ', $key),
            fileName: $this->packages->getUrl(self::IMAGES_PATH . $key . self::IMAGE_EXTENSION),
            cost: $data['cost'],
            points: $data['points'],
            categories: $data['categories'],
            credits: $data['credits'],
            mitigations: $data['mitigations'] ?? [],
            aggravations: $data['aggravations'] ?? [],
        );
    }

    private function sortByCost(array $advances): array
    {
        usort($advances, static fn(Advance $a, Advance $b): int => $a->cost <=> $b->cost);

        return $advances;
    }
}