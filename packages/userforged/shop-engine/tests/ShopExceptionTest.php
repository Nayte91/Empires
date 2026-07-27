<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Tests;

use Userforged\ShopEngine\Exception\CartException;
use Userforged\ShopEngine\Exception\EligibilityException;
use Userforged\ShopEngine\Exception\OrderException;
use Userforged\ShopEngine\Exception\PromotionException;
use Userforged\ShopEngine\Exception\ShopException;
use Userforged\ShopEngine\Exception\ShopExceptionReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The host-facing half of every domain exception. getMessage() stays
 * developer English for logs; reason() and context() are what a host maps to
 * buyer-facing copy, so they are the closest thing this package has to a
 * published translation API.
 *
 * Two of these factories — alreadyValidated() and linesLocked() — have no
 * caller anywhere inside the package: they exist for a host implementing
 * OrderInterface to throw from its own entity. This file is therefore the
 * only thing pinning their reason and context at all.
 *
 * The ShopException parameter type is itself an assertion: a factory that
 * stopped implementing the interface would fail before any assert runs.
 */
final class ShopExceptionTest extends TestCase
{
    /** @param array<string, string> $context */
    #[Test]
    #[DataProvider('provideEveryFactoryCarriesItsReasonAndContextCases')]
    public function everyFactoryCarriesItsReasonAndContext(ShopException $exception, ShopExceptionReason $reason, array $context): void
    {
        $this->assertSame($reason, $exception->reason());
        $this->assertSame($context, $exception->context());
    }

    public static function provideEveryFactoryCarriesItsReasonAndContextCases(): iterable
    {
        yield 'an empty cart cannot be submitted' => [CartException::empty(), ShopExceptionReason::CartEmpty, []];

        yield 'a product the buyer already owns' => [EligibilityException::productAlreadyOwned('pottery'), ShopExceptionReason::ProductAlreadyOwned, ['key' => 'pottery']];

        yield 'the window already holds a validated order' => [OrderException::windowAlreadyValidated(), ShopExceptionReason::WindowAlreadyValidated, []];

        yield 'the order itself is already validated' => [OrderException::alreadyValidated(), ShopExceptionReason::OrderAlreadyValidated, []];

        yield 'a validated orders lines can no longer be replaced' => [OrderException::linesLocked(), ShopExceptionReason::OrderLinesLocked, []];

        yield 'the order is not in a state that allows rejection' => [OrderException::rejectionUnavailable(), ShopExceptionReason::OrderRejectionUnavailable, []];

        yield 'a gift chosen outside the granted candidates' => [PromotionException::invalidGift('coinage'), ShopExceptionReason::PromotionInvalidGift, ['key' => 'coinage']];

        yield 'an elective budget not yet fully spent' => [PromotionException::allocationRequired('monument'), ShopExceptionReason::PromotionAllocationRequired, ['key' => 'monument']];

        yield 'an elective allocation only a bypassed client could produce' => [PromotionException::invalidAllocation('monument'), ShopExceptionReason::PromotionInvalidAllocation, ['key' => 'monument']];
    }

    /**
     * ShopExceptionReason documents a one-case-per-factory invariant. A host
     * writes an exhaustive match() over these cases to produce its copy, so a
     * case reachable from no factory is dead translation work, and a case
     * added without a row here would silently escape this file's coverage.
     */
    #[Test]
    public function everyReasonIsReachableFromAFactory(): void
    {
        $covered = array_map(
            static fn (array $case): ShopExceptionReason => $case[1],
            iterator_to_array(self::provideEveryFactoryCarriesItsReasonAndContextCases()),
        );

        $this->assertEqualsCanonicalizing(
            array_column(ShopExceptionReason::cases(), 'value'),
            array_column(array_values($covered), 'value'),
        );
    }
}
