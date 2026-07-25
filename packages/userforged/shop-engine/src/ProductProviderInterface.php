<?php

declare(strict_types=1);

namespace Userforged\ShopEngine;

/**
 * The catalog's source of truth (port). The library never persists or
 * loads products itself — the host adapts whatever storage it has
 * (config file, Doctrine, remote API) behind this interface.
 *
 * Ordering is the provider's responsibility: products() returns its
 * products in the order the host wants them rendered (e.g. sorted by
 * cost), and callers must not re-sort.
 */
interface ProductProviderInterface
{
    /** @return list<ProductInterface> */
    public function products(): array;

    /**
     * @param list<string> $keys
     *
     * @return list<ProductInterface>
     */
    public function productsByKeys(array $keys): array;
}
