<?php

declare(strict_types=1);

namespace App\Shop\Dto;

use App\Shop\Promotion\AppliedPromotion;

final readonly class OrderLine
{
    public function __construct(
        public string $key,
        public int $netCost,
        public ?AppliedPromotion $promotion = null,
    ) {}

    /** @param array{key: string, netCost: int, promotion?: array{type: string, source: string, amount?: int, allocation?: array<string, int>}} $line */
    public static function fromArray(array $line): self
    {
        return new self(
            $line['key'],
            $line['netCost'],
            isset($line['promotion']) ? AppliedPromotion::fromArray($line['promotion']) : null,
        );
    }

    /** @return array{key: string, netCost: int, promotion?: array{type: string, source: string, amount?: int, allocation?: array<string, int>}} */
    public function toArray(): array
    {
        $data = ['key' => $this->key, 'netCost' => $this->netCost];

        if ($this->promotion instanceof AppliedPromotion) {
            $data['promotion'] = $this->promotion->toArray();
        }

        return $data;
    }
}
