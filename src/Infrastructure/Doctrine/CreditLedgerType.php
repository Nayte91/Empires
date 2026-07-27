<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\State\CreditEntry;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDbalType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\JsonType;

/**
 * Maps Player::$creditLedger to/from a list<CreditEntry> instead of raw JSON arrays.
 *
 * Dirty-tracking note: CreditEntry is a value object rebuilt from the database on
 * every hydration, so Doctrine's change tracking may see it as "changed" and
 * emit an UPDATE even when the JSON payload is byte-identical. Benign.
 */
#[AsDbalType('credit_ledger')]
final class CreditLedgerType extends JsonType
{
    public const string NAME = 'credit_ledger';

    #[\Override]
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): string
    {
        // Player::$creditLedger is always list<CreditEntry>; the inherited @template T from
        // JsonTypeConvert::convertToDatabaseValue() is what phpstan is (wrongly) checking against below.
        /**
         * @var list<CreditEntry> $entries
         *
         * @phpstan-ignore varTag.type
         */
        $entries = $value;

        return parent::convertToDatabaseValue(
            array_map(static fn (CreditEntry $entry): array => $entry->toArray(), $entries),
            $platform,
        );
    }

    /** @return list<CreditEntry> */
    #[\Override]
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed
    {
        /** @var null|list<array{reason: string, scope: string, source: string, turn: int, value: int}> $decoded */
        $decoded = parent::convertToPHPValue($value, $platform);

        return array_map(CreditEntry::fromArray(...), $decoded ?? []);
    }
}
