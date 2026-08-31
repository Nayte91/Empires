<?php

declare(strict_types=1);

namespace App\Rules\Ruleset;

use App\State\Region;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

final class RulebookRegistry
{
    private const string SCENARIOS_KEY = 'scenarios';

    /** @var null|array<string, array{label: string, caption: string, url: string}> */
    private ?array $rulebooks = null;

    public function __construct(#[Autowire('%kernel.project_dir%/config/game/rulebooks.yaml')] private readonly string $rulebooksPath) {}

    public function forRegion(Region $region): Rulebook
    {
        return $this->read($region->value);
    }

    public function scenarios(): Rulebook
    {
        return $this->read(self::SCENARIOS_KEY);
    }

    private function read(string $key): Rulebook
    {
        $entry = $this->load()[$key] ?? throw new \RuntimeException(sprintf('No rulebook declared under "%s" in %s.', $key, $this->rulebooksPath));

        return new Rulebook($entry['label'], $entry['caption'], $entry['url']);
    }

    /** @return array<string, array{label: string, caption: string, url: string}> */
    private function load(): array
    {
        if (null !== $this->rulebooks) {
            return $this->rulebooks;
        }

        /** @var array{rulebooks?: array<string, array{label: string, caption: string, url: string}>} $data */
        $data = Yaml::parseFile($this->rulebooksPath);

        return $this->rulebooks = $data['rulebooks'] ?? [];
    }
}
