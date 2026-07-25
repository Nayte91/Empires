<?php

declare(strict_types=1);

namespace App\Shop\Doctrine;

use App\Shop\Dto\OrderLine;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDbalType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\JsonType;

/**
 * Maps Order::$lines to/from a list<OrderLine> instead of raw JSON arrays.
 *
 * Dirty-tracking note: OrderLine is a value object rebuilt from the database on
 * every hydration, so Doctrine's change tracking may see it as "changed" and
 * emit an UPDATE even when the JSON payload is byte-identical. Benign.
 */
#[AsDbalType('order_lines')]
final class OrderLinesType extends JsonType
{
    public const string NAME = 'order_lines';

    #[\Override]
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): string
    {
        // Order::$lines is always list<OrderLine>; the inherited @template T from
        // JsonTypeConvert::convertToDatabaseValue() is what phpstan is (wrongly) checking against below.
        /**
         * @var list<OrderLine> $lines
         *
         * @phpstan-ignore varTag.type
         */
        $lines = $value;

        return parent::convertToDatabaseValue(
            array_map(static fn (OrderLine $line): array => $line->toArray(), $lines),
            $platform,
        );
    }

    /** @return list<OrderLine> */
    #[\Override]
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed
    {
        /** @var null|list<array{key: string, netCost: int, promotion?: array{type: string, source: string, amount?: int, allocation?: array<string, int>}}> $decoded */
        $decoded = parent::convertToPHPValue($value, $platform);

        return array_map(OrderLine::fromArray(...), $decoded ?? []);
    }
}
