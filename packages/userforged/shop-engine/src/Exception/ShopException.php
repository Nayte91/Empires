<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Exception;

/**
 * Marker + machine-readable contract for every domain exception in this
 * library. getMessage() stays developer-facing English, for logs and stack
 * traces — reason() and context() are what a host translates into
 * buyer-facing copy. The library renders nothing and owns no i18n (see
 * packages/userforged/shop-engine/README.md); it must never be the source of copy shown to a
 * buyer.
 */
interface ShopException extends \Throwable
{
    public function reason(): ShopExceptionReason;

    /** @return array<string, string> */
    public function context(): array;
}
