<?php

declare(strict_types=1);

namespace App\Game\Shop;

final readonly class CreditEntry
{
    public function __construct(
        public int $turn,
        public string $scope,
        public int $value,
        public CreditSource $source,
        public string $reason,
    ) {}

    /** @param array{reason: string, scope: string, source: string, turn: int, value: int} $entry */
    public static function fromArray(array $entry): self
    {
        return new self(
            $entry['turn'],
            $entry['scope'],
            $entry['value'],
            CreditSource::from($entry['source']),
            $entry['reason'],
        );
    }

    /** @return array{reason: string, scope: string, source: string, turn: int, value: int} */
    public function toArray(): array
    {
        return [
            'turn' => $this->turn,
            'scope' => $this->scope,
            'value' => $this->value,
            'source' => $this->source->value,
            'reason' => $this->reason,
        ];
    }
}
