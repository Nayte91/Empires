<?php

namespace App\Component;

use App\DTO\UserSettings;
use App\Manager\SettingsValidator;
use App\Mapper\GameMapper;
use App\Repository\CivilizationRepository;
use App\Repository\GameRepository;
use App\Repository\RegionRepository;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PreMount;

#[AsLiveComponent(template: 'organisms/gameInfo.html.twig')]
class GameInfo
{
    use DefaultActionTrait;

    #[LiveProp(writable: ['playerName', 'civilizationPosition', 'playerCount', 'currentTurn', 'astType', 'region'], onUpdated: [
        'playerName' => 'onSettingsUpdated',
        'civilizationPosition' => 'onSettingsUpdated',
        'playerCount' => 'onSettingsUpdated',
        'currentTurn' => 'onSettingsUpdated',
        'astType' => 'onSettingsUpdated',
        'region' => 'onSettingsUpdated',
    ])]
    public ?UserSettings $settings = null;

    public array $civilizations = [];

    public array $regions = [];

    public function __construct(
        private readonly RegionRepository $regionRepository,
        private readonly CivilizationRepository $civRepository,
        private readonly GameRepository $gameRepository,
        private readonly GameMapper $mapper,
        private readonly SettingsValidator $settingsValidator,
    ) {}

    #[PreMount]
    public function preMount(): void
    {
        if ($this->settings === null) {
            $game = $this->gameRepository->findOrCreate();
            $this->settings = $this->mapper->mapETTtoDTO($game);
        }

        $this->defineRegions();
        $this->defineAvailableCivilizations();
    }

    public function onSettingsUpdated(): void
    {
        $this->settings = $this->settingsValidator->validateSettings($this->settings);
        $game = $this->mapper->mapDTOtoETT($this->settings);
        $this->gameRepository->save($game);
        $this->settings = $this->mapper->mapETTtoDTO($game);
        $this->defineAvailableCivilizations();
        $this->defineRegions();
    }

    private function defineAvailableCivilizations(): void
    {
        $this->civilizations = $this->civRepository->findByPlayerCountAndRegion($this->settings->playerCount, $this->settings->region);
    }

    private function defineRegions(): void
    {
        $this->regions = $this->regionRepository->findByPlayerCount($this->settings->playerCount);
    }
}
