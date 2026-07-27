<?php

declare(strict_types=1);

namespace App\Rules\Ruleset;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads the `effects:` a advance declares in config/game/advances.yaml, and answers the only two
 * questions the calculators ask of it: which advances grant an effect, and whether a given player's
 * advances are among them.
 *
 * Separate from AdvanceCatalog on purpose: that one hydrates advances as shop products and needs
 * the asset Packages to do it, which is a dependency the rule layer has no business carrying.
 */
final class AdvanceEffectCatalog
{
    /** @var null|array<string, list<string>> */
    private ?array $keysByEffect = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/game/advances.yaml')]
        private readonly string $advancesDataPath,
    ) {}

    /** @return list<string> */
    public function keysGranting(AdvanceEffect $effect): array
    {
        return $this->index()[$effect->value] ?? [];
    }

    /**
     * Which of the advances a player owns grant the effect — empty when none do.
     *
     * @param list<string> $ownedKeys
     *
     * @return list<string>
     */
    public function owned(array $ownedKeys, AdvanceEffect $effect): array
    {
        return array_values(array_intersect($ownedKeys, $this->keysGranting($effect)));
    }

    /** @param list<string> $ownedKeys */
    public function grants(array $ownedKeys, AdvanceEffect $effect): bool
    {
        return [] !== $this->owned($ownedKeys, $effect);
    }

    /** @return array<string, list<string>> */
    private function index(): array
    {
        if (null !== $this->keysByEffect) {
            return $this->keysByEffect;
        }

        /** @var array{advances?: array<string, array{effects?: list<string>}>} $data */
        $data = Yaml::parseFile($this->advancesDataPath);
        $index = [];

        foreach ($data['advances'] ?? [] as $key => $advance) {
            foreach ($advance['effects'] ?? [] as $effect) {
                $index[$effect][] = $key;
            }
        }

        return $this->keysByEffect = $index;
    }
}
