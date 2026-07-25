<?php

declare(strict_types=1);

namespace App\Game\Shop;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Userforged\ShopEngine\Exception\ShopException;
use Userforged\ShopEngine\Exception\ShopExceptionReason;

/**
 * The Game→Shop seam, i18n side: packages/userforged/shop-engine/ throws
 * ShopException with a machine-readable reason() + context(), never
 * player-facing copy (see
 * packages/userforged/shop-engine/README.md) — this is the only place that maps a reason to a
 * translation key and turns it into copy the player actually sees, in the
 * 'shop' domain (translations/Shop/shop+intl-icu.en.xlf).
 *
 * Each trans() call below spells its key out as a literal on purpose: the
 * `bin/console debug:translation` static extractor only recognizes direct
 * `trans('literal', ...)` calls, not a key built by concatenation — so a
 * one-liner like `trans('error.'.$reason->value, ...)` would translate
 * correctly at runtime but every key would misreport as unused.
 *
 * Also the single home for unwrapping a dispatched command's
 * HandlerFailedException: App\Component\Cart and App\Component\PlayerOrders
 * used to each inline the same unwrap loop. And the single home for
 * pre-check guards that never throw at all — App\Component\Shop and
 * App\Component\PlayerOrders both refuse an already-owned advance before
 * ever dispatching a command, and call messageForReason() directly so that
 * copy stays in this one catalogue instead of drifting into a hardcoded
 * duplicate.
 */
final readonly class ShopExceptionTranslator
{
    public function __construct(private TranslatorInterface $translator) {}

    /**
     * The translated, player-facing message for a failed command dispatch,
     * or null when the failure isn't one this seam knows how to translate —
     * the caller should rethrow in that case.
     */
    public function messageFor(HandlerFailedException $exception): ?string
    {
        foreach ($exception->getWrappedExceptions() as $wrapped) {
            if ($wrapped instanceof ShopException) {
                return $this->messageForReason($wrapped->reason(), $wrapped->context());
            }

            if ($wrapped instanceof UniqueConstraintViolationException) {
                return $this->translator->trans('error.order_already_submitted', domain: 'shop');
            }
        }

        return null;
    }

    /**
     * The translated, player-facing message for a reason, independent of
     * any thrown exception — for host-side guards that reject upfront
     * without ever dispatching a command.
     *
     * @param array<string, string> $context
     */
    public function messageForReason(ShopExceptionReason $reason, array $context = []): string
    {
        return match ($reason) {
            ShopExceptionReason::CartEmpty => $this->translator->trans('error.cart_empty', $context, 'shop'),
            ShopExceptionReason::ProductAlreadyOwned => $this->translator->trans('error.advance_already_owned', $context, 'shop'),
            ShopExceptionReason::WindowAlreadyValidated => $this->translator->trans('error.window_already_validated', $context, 'shop'),
            ShopExceptionReason::OrderAlreadyValidated => $this->translator->trans('error.order_already_validated', $context, 'shop'),
            ShopExceptionReason::OrderLinesLocked => $this->translator->trans('error.order_lines_locked', $context, 'shop'),
            ShopExceptionReason::OrderRejectionUnavailable => $this->translator->trans('error.order_rejection_unavailable', $context, 'shop'),
            ShopExceptionReason::PromotionInvalidGift => $this->translator->trans('error.promotion_invalid_gift', $context, 'shop'),
            ShopExceptionReason::PromotionAllocationRequired => $this->translator->trans('error.promotion_allocation_required', $context, 'shop'),
            ShopExceptionReason::PromotionInvalidAllocation => $this->translator->trans('error.promotion_invalid_allocation', $context, 'shop'),
        };
    }
}
