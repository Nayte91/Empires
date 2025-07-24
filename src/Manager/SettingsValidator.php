<?php

namespace App\Manager;

use App\DTO\UserSettings;
use App\Repository\RegionRepository;

final readonly class SettingsValidator
{
    public function __construct(
        private RegionRepository $regionRepository,
    ) {}

    public function validateSettings(UserSettings $settings): UserSettings
    {
        $settings->region = $this->resolveRegion($settings);

        return $settings;
    }

    private function resolveRegion(UserSettings $settings): ?string
    {
        if ($this->shouldClearRegion($settings)) return null;

        if ($this->shouldAutoAssignRegion($settings)) {
            $region = $this->regionRepository->findByCivilization($settings->civilizationName);
            return $region->name;
        }

        return $settings->region;
    }

    private function shouldClearRegion(UserSettings $settings): bool
    {
        return $settings->playerCount >= 10 && $settings->region !== '';
    }

    private function shouldAutoAssignRegion(UserSettings $settings): bool
    {
        return $settings->playerCount <= 9 && $settings->region === '';
    }
}
