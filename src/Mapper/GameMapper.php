<?php

namespace App\Mapper;

use App\DTO\UserSettings;
use App\Entity\Game;
use App\Enumeration\ASTType;
use App\Repository\CivilizationRepository;

readonly class GameMapper
{
    public function __construct(private CivilizationRepository $civilizationRepository) {}

    public function mapDTOtoETT(UserSettings $userSettingsDTO): Game
    {
        return new Game(
            playerName: $userSettingsDTO->playerName,
            civilization: $userSettingsDTO->civilizationPosition ? $this->civilizationRepository->findByPosition($userSettingsDTO->civilizationPosition) : null,
            astType: ASTType::tryFrom($userSettingsDTO->astType),
            playerCount: $userSettingsDTO->playerCount,
            region: $userSettingsDTO->region,
            currentTurn: $userSettingsDTO->currentTurn,
        );
    }

    public function mapETTtoDTO(Game $gameETT): UserSettings
    {
        $settings = new UserSettings;
        $settings->playerName = $gameETT->playerName;
        $settings->civilizationName = $gameETT->civilization?->name;
        $settings->civilizationPosition = $gameETT->civilization?->position;
        $settings->civilizationColor = $gameETT->civilization?->color;
        $settings->playerCount = $gameETT->playerCount;
        $settings->region = $gameETT->region;
        $settings->astType = $gameETT->astType->value;
        $settings->currentTurn = $gameETT->currentTurn;

        return $settings;
    }
}
