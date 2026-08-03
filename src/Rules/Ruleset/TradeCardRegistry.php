<?php

declare(strict_types=1);

namespace App\Rules\Ruleset;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

final class TradeCardRegistry
{
    /** @var null|array<string, mixed> */
    private ?array $tradeCards = null;

    public function __construct(#[Autowire('%kernel.project_dir%/config/game/trade_cards.yaml')] private readonly string $tradeCardsPath) {}

    public function distributionFor(int $playerCount, ?string $region): TradeCardTable
    {
        $columns = $this->resolveColumns($playerCount, $region);

        if ([] === $columns) {
            return new TradeCardTable(columns: [], stacks: []);
        }

        $bracketKeys = array_column($columns, 'bracketKey');
        $stacks = [];

        foreach ($this->getStacks() as $number => $stackData) {
            $cards = $this->buildCards($stackData['cards'] ?? [], $bracketKeys);

            if ([] === $cards) {
                continue;
            }

            $stacks[] = new TradeCardStack(number: (int) $number, cards: $cards);
        }

        return new TradeCardTable(columns: array_column($columns, 'label'), stacks: $stacks);
    }

    /** @return list<array{label: string, bracketKey: string}> */
    private function resolveColumns(int $playerCount, ?string $region): array
    {
        if ($playerCount >= 12 && $playerCount <= 14) {
            return [
                ['label' => 'West block', 'bracketKey' => 'combined_12_14_west'],
                ['label' => 'East block', 'bracketKey' => 'combined_12_14_east'],
            ];
        }

        if ($playerCount >= 15 && $playerCount <= 18) {
            return [
                ['label' => 'West block', 'bracketKey' => 'combined_15_18_west'],
                ['label' => 'East block', 'bracketKey' => 'combined_15_18_east'],
            ];
        }

        // Upper-bounded on purpose: an unbounded ">= 10" swallows every count above 18 into this
        // bracket, disagreeing with ScenarioRegistry, which has no scenario past 18.
        if ($playerCount >= 10 && $playerCount <= 11) {
            return [['label' => '10-11 players', 'bracketKey' => 'combined_10_11']];
        }

        if (null === $region) {
            return [];
        }

        $suffix = 9 === $playerCount ? '9' : '3_8';
        $label = 9 === $playerCount ? '9 players' : '3-8 players';
        $bracketKey = "{$region}_{$suffix}";

        // The only bracket key built from caller input rather than a literal: an unknown region
        // synthesises a key no card carries. Treat that the way a query with no answer should
        // read — an empty table — never a table of empty stacks.
        if (!\in_array($bracketKey, $this->declaredBrackets(), true)) {
            return [];
        }

        return [['label' => $label, 'bracketKey' => $bracketKey]];
    }

    /** @return list<string> */
    private function declaredBrackets(): array
    {
        $brackets = $this->loadTradeCards()['trade_cards']['brackets'] ?? null;

        return is_array($brackets) ? $brackets : [];
    }

    /**
     * @param list<array{name: string, type: string, game: string, quantities: array<string, int>}> $cardsData
     * @param list<string>                                                                          $bracketKeys
     *
     * @return list<TradeCard>
     */
    private function buildCards(array $cardsData, array $bracketKeys): array
    {
        $cards = [];

        foreach ($cardsData as $cardData) {
            $quantities = array_map(
                static fn (string $bracketKey): ?int => $cardData['quantities'][$bracketKey] ?? null,
                $bracketKeys,
            );

            if ([] === array_filter($quantities, static fn (?int $quantity): bool => null !== $quantity)) {
                continue;
            }

            $cards[] = new TradeCard(
                name: $cardData['name'],
                type: $cardData['type'],
                game: $cardData['game'],
                quantities: $quantities,
            );
        }

        return $cards;
    }

    /** @return array<int, array{cards: list<array{name: string, type: string, game: string, quantities: array<string, int>}>}> */
    private function getStacks(): array
    {
        $stacks = $this->loadTradeCards()['trade_cards']['stacks'] ?? null;

        return is_array($stacks) ? $stacks : [];
    }

    /** @return array<string, mixed> */
    private function loadTradeCards(): array
    {
        if (null !== $this->tradeCards) {
            return $this->tradeCards;
        }

        $data = Yaml::parseFile($this->tradeCardsPath);

        if (!isset($data['trade_cards']) || !is_array($data['trade_cards'])) {
            throw new \RuntimeException('Invalid trade cards configuration file');
        }

        $this->assertEveryCardBracketIsDeclared($data['trade_cards']);

        $this->tradeCards = $data;

        return $this->tradeCards;
    }

    // A typo'd key in a card's quantities map (`wost_9` for `west_9`) would otherwise just read as
    // an absent card in that pool — silently, forever. Unlike a caller asking for a bracket that
    // doesn't exist, this is a mistake in data the game depends on, so it fails loudly: same
    // precedent as ScenarioRegistry::getScenarios() throwing on a malformed ruleset file.
    /** @param array<string, mixed> $tradeCards */
    private function assertEveryCardBracketIsDeclared(array $tradeCards): void
    {
        $declared = $tradeCards['brackets'] ?? null;

        if (!is_array($declared)) {
            throw new \RuntimeException('Invalid trade cards configuration file');
        }

        foreach ($tradeCards['stacks'] ?? [] as $stack) {
            foreach ($stack['cards'] ?? [] as $card) {
                foreach (array_keys($card['quantities'] ?? []) as $bracketKey) {
                    if (!\in_array($bracketKey, $declared, true)) {
                        throw new \RuntimeException("Undeclared trade card bracket: {$bracketKey}");
                    }
                }
            }
        }
    }
}
