<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Exception;

/**
 * The machine-readable identity of a ShopException, one case per factory
 * method across the library's exception classes. The host maps each case to
 * a translation key (see App\Game\Shop\ShopExceptionTranslator) — the
 * library itself never learns what copy a reason produces.
 */
enum ShopExceptionReason: string
{
    case CartEmpty = 'cart_empty';
    case AdvanceAlreadyOwned = 'advance_already_owned';
    case WindowAlreadyValidated = 'window_already_validated';
    case OrderAlreadyValidated = 'order_already_validated';
    case OrderLinesLocked = 'order_lines_locked';
    case OrderRejectionUnavailable = 'order_rejection_unavailable';
    case PromotionInvalidGift = 'promotion_invalid_gift';
    case PromotionAllocationRequired = 'promotion_allocation_required';
    case PromotionInvalidAllocation = 'promotion_invalid_allocation';
}
