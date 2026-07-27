<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Rules\Ruleset\Advance;
use App\Rules\Ruleset\AdvanceCatalog;
use Userforged\ShopEngine\Dto\OrderLine;

/**
 * Pairs each priced line with the advance it names, dropping any line the
 * catalog no longer knows, and totals the result — the {advance, line} rows
 * every cart and order template iterates.
 *
 * A trait rather than a service on purpose: nothing here decides a rule of
 * the game, it only shapes what App\Presentation\Component\Shop and App\Presentation\Component\Cart
 * hand to their own templates (same reasoning as HasIncompleteAllocationsTrait,
 * whose AdvanceCatalog parameter it also copies rather than reaching for a
 * property of the using class).
 */
trait OrderRowsTrait
{
    /**
     * @param list<OrderLine> $lines
     *
     * @return list<array{advance: Advance, line: OrderLine}>
     */
    protected function toRows(array $lines, AdvanceCatalog $advanceCatalog): array
    {
        return array_map(
            static function (OrderLine $line) use ($advanceCatalog): ?array {
                $advance = $advanceCatalog->getAdvanceByName($line->key);

                return $advance instanceof Advance ? ['advance' => $advance, 'line' => $line] : null;
            },
            $lines,
        )
                |> array_filter(...)
                |> array_values(...);
    }

    /** @param list<array{advance: Advance, line: OrderLine}> $rows */
    protected function sumNetCost(array $rows): int
    {
        return array_sum(array_map(static fn (array $row): int => $row['line']->netCost, $rows));
    }
}
