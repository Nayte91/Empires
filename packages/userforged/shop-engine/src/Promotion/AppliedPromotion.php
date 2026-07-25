<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Promotion;

final readonly class AppliedPromotion
{
    /** @param array<string, int> $allocation */
    public function __construct(
        public PromotionType $type,
        public string $source,
        public int $amount = 0,
        public array $allocation = [],
    ) {}

    /** @param array{type: string, source: string, amount?: int, allocation?: array<string, int>} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            type: PromotionType::from($data['type']),
            source: $data['source'],
            amount: $data['amount'] ?? 0,
            allocation: $data['allocation'] ?? [],
        );
    }

    /** @return array{type: string, source: string, amount?: int, allocation?: array<string, int>} */
    public function toArray(): array
    {
        $data = ['type' => $this->type->value, 'source' => $this->source];

        if (0 !== $this->amount) {
            $data['amount'] = $this->amount;
        }

        if ([] !== $this->allocation) {
            $data['allocation'] = $this->allocation;
        }

        return $data;
    }
}
