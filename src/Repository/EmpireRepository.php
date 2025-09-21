<?php

declare(strict_types=1);

namespace App\Repository;

use App\DTO\Empire;
use App\Game\ScenarioCatalog;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

class EmpireRepository
{
    public const string SORT_BY_POSITION = 'position';
    public const string SORT_BY_NAME = 'name';

    /**
     * @var null|array<string, array{
     *     position: int,
     *     name: string,
     *     demonym: string,
     *     adjective: string,
     *     people_icon: ?string,
     *     ship_icon: ?string,
     *     city_icon: ?string,
     *     color: string,
     * }>
     */
    private ?array $empiresData = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/game/empires.yaml')]
        private readonly string $empiresConfigPath,
        private readonly ScenarioCatalog $scenarioCatalog,
    ) {}

    /** @return array<string, Empire> */
    public function findAll(string $sortBy = self::SORT_BY_POSITION): array
    {
        $empiresArray = $this->getEmpiresData();

        if ('name' === $sortBy) {
            uasort($empiresArray, static fn ($a, $b): int => $a['name'] <=> $b['name']);
        }

        return array_map(
            $this->hydrateEmpire(...),
            $empiresArray
        );
    }

    public function findByPosition(int $position): ?Empire
    {
        $empiresArray = $this->getEmpiresData();

        foreach ($empiresArray as $empireData) {
            if ($empireData['position'] === $position) {
                return $this->hydrateEmpire($empireData);
            }
        }

        return null;
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

    /** @return list<Empire> */
    public function findByPlayerCountAndRegion(int $playerCount, ?string $region): array
    {
        $slugs = $this->scenarioCatalog->empiresFor($playerCount, $region);

        return array_values(array_filter(
            array_map($this->findByName(...), $slugs),
            static fn (?Empire $empire): bool => $empire instanceof Empire,
        ));
    }

    /**
     * @return array<string, array{
     *     position: int,
     *     name: string,
     *     demonym: string,
     *     adjective: string,
     *     people_icon: ?string,
     *     ship_icon: ?string,
     *     city_icon: ?string,
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
             *     people_icon: ?string,
             *     ship_icon: ?string,
             *     city_icon: ?string,
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
     *     people_icon: ?string,
     *     ship_icon: ?string,
     *     city_icon: ?string,
     *     color: string,
     * } $data
     */
    private function hydrateEmpire(array $data): Empire
    {
        $empire = new Empire();
        $empire->position = $data['position'];
        $empire->name = $data['name'];
        $empire->demonym = $data['demonym'];
        $empire->adjective = $data['adjective'];
        $empire->peopleIcon = $data['people_icon'];
        $empire->shipIcon = $data['ship_icon'];
        $empire->cityIcon = $data['city_icon'];
        $empire->color = $data['color'];

        return $empire;
    }
}
