<?php

declare(strict_types=1);

namespace App\Shop\Dto;

final readonly class LineIntent
{
    /** @param array<string, int> $allocation */
    public function __construct(
        public string $key,
        public ?string $gift = null,
        public array $allocation = [],
    ) {}

    /** @param array{key: string, gift?: string, allocation?: array<string, int>} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            key: $data['key'],
            gift: $data['gift'] ?? null,
            allocation: $data['allocation'] ?? [],
        );
    }

    /** @return array{key: string, gift?: string, allocation?: array<string, int>} */
    public function toArray(): array
    {
        $data = ['key' => $this->key];

        if (null !== $this->gift) {
            $data['gift'] = $this->gift;
        }

        if ([] !== $this->allocation) {
            $data['allocation'] = $this->allocation;
        }

        return $data;
    }
}
