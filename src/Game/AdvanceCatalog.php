<?php

declare(strict_types=1);

namespace App\Game;

use App\Game\Dto\Advance;
use Symfony\Component\Asset\Packages;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;
use Userforged\ShopEngine\Promotion\ElectiveBenefit;
use Userforged\ShopEngine\Promotion\ProductPromotion;

final class AdvanceCatalog
{
    private const string IMAGES_PATH = 'images/advances/';
    private const string IMAGE_EXTENSION = '.webp';

    /**
     * @var null|array<string, array{
     *     cost: int,
     *     points: int,
     *     categories: list<string>,
     *     credits: array<string, int>,
     *     mitigation?: list<string>,
     *     aggravation?: list<string>,
     *     promotion?: array{gift?: array<string, int>, discount?: array<string, int>, option?: array{budget: int, step: int}},
     * }>
     */
    private ?array $advancesData = null;

    /** @var null|array<string, string> */
    private ?array $categoryColors = null;

    /** @var null|array{advances?: mixed, categories?: mixed} */
    private ?array $rawData = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/game/advances.yaml')]
        private readonly string $advancesDataPath,
        private readonly Packages $packages,
    ) {}

    /** @return list<Advance> */
    public function getAdvances(): array
    {
        $advancesData = $this->getAdvancesData();

        $advances = array_map(
            fn (string $name, array $data): Advance => $this->hydrateAdvance($name, $data),
            array_keys($advancesData),
            $advancesData
        );

        return $this->sortByCost($advances);
    }

    public function getAdvanceByName(string $name): ?Advance
    {
        $key = str_replace(' ', '_', strtolower($name));
        $advancesData = $this->getAdvancesData();

        if (!isset($advancesData[$key])) {
            return null;
        }

        return $this->hydrateAdvance($key, $advancesData[$key]);
    }

    /**
     * @param list<string> $names
     *
     * @return array<int, Advance>
     */
    public function getAdvancesByNames(array $names): array
    {
        return array_filter(
            array_map(
                $this->getAdvanceByName(...),
                $names
            )
        );
    }

    /** @return array<string, string> */
    public function getCategoryColors(): array
    {
        if (null === $this->categoryColors) {
            $data = $this->getRootData();

            if (!isset($data['categories']) || !is_array($data['categories'])) {
                throw new \RuntimeException('Invalid advances configuration file');
            }

            /** @var array<string, array{color: string}> $categories */
            $categories = $data['categories'];
            $this->categoryColors = array_map(
                static fn (array $category): string => $category['color'],
                $categories
            );
        }

        return $this->categoryColors;
    }

    /**
     * @return array<string, array{
     *     cost: int,
     *     points: int,
     *     categories: list<string>,
     *     credits: array<string, int>,
     *     mitigation?: list<string>,
     *     aggravation?: list<string>,
     *     promotion?: array{gift?: array<string, int>, discount?: array<string, int>, option?: array{budget: int, step: int}},
     * }>
     */
    private function getAdvancesData(): array
    {
        if (null === $this->advancesData) {
            $data = $this->getRootData();

            if (!isset($data['advances']) || !is_array($data['advances'])) {
                throw new \RuntimeException('Invalid advances configuration file');
            }

            /**
             * @var array<string, array{
             *     cost: int,
             *     points: int,
             *     categories: list<string>,
             *     credits: array<string, int>,
             *     mitigation?: list<string>,
             *     aggravation?: list<string>,
             *     promotion?: array{gift?: array<string, int>, discount?: array<string, int>, option?: array{budget: int, step: int}},
             * }> $advances
             */
            $advances = $data['advances'];
            $this->advancesData = $advances;
        }

        return $this->advancesData;
    }

    /** @return array{advances?: mixed, categories?: mixed} */
    private function getRootData(): array
    {
        if (null === $this->rawData) {
            /** @var array{advances?: mixed, categories?: mixed} $data */
            $data = Yaml::parseFile($this->advancesDataPath);
            $this->rawData = $data;
        }

        return $this->rawData;
    }

    /**
     * @param array{
     *     cost: int,
     *     points: int,
     *     categories: list<string>,
     *     credits: array<string, int>,
     *     mitigation?: list<string>,
     *     aggravation?: list<string>,
     *     promotion?: array{gift?: array<string, int>, discount?: array<string, int>, option?: array{budget: int, step: int}},
     * } $data
     */
    private function hydrateAdvance(string $key, array $data): Advance
    {
        // The 'payment' YAML key exists but is intentionally not read (out of scope for v1 shop).
        $promotion = isset($data['promotion'])
            ? new ProductPromotion(
                gift: $data['promotion']['gift'] ?? [],
                discount: $data['promotion']['discount'] ?? [],
                option: isset($data['promotion']['option'])
                    ? new ElectiveBenefit(
                        budget: $data['promotion']['option']['budget'],
                        step: $data['promotion']['option']['step'],
                    )
                    : null,
            )
            : null;

        return new Advance(
            key: $key,
            name: str_replace('_', ' ', $key),
            fileName: $this->packages->getUrl(self::IMAGES_PATH.$key.self::IMAGE_EXTENSION),
            cost: $data['cost'],
            points: $data['points'],
            facets: $data['categories'],
            credits: $data['credits'],
            mitigations: $data['mitigation'] ?? [],
            aggravations: $data['aggravation'] ?? [],
            promotion: $promotion,
        );
    }

    /**
     * @param list<Advance> $advances
     *
     * @return list<Advance>
     */
    private function sortByCost(array $advances): array
    {
        usort($advances, static fn (Advance $a, Advance $b): int => $a->cost <=> $b->cost);

        return $advances;
    }
}
