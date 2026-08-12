<?php

declare(strict_types=1);

namespace App\Tests\Integration\Presentation\Shop;

use App\Presentation\Shop\ShopExceptionTranslator;
use Userforged\ShopEngine\Exception\CartException;
use Userforged\ShopEngine\Exception\EligibilityException;
use Userforged\ShopEngine\Exception\OrderException;
use Userforged\ShopEngine\Exception\PromotionException;
use Userforged\ShopEngine\Exception\ShopException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

final class ShopExceptionTranslatorTest extends WebTestCase
{
    private ShopExceptionTranslator $translator;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->translator = self::getContainer()->get(ShopExceptionTranslator::class);
    }

    #[Test]
    public function everyShopExceptionReasonResolvesToANonEmptyTranslatedMessage(): void
    {
        foreach ($this->everyShopException() as $exception) {
            $message = $this->translator->messageFor($this->wrap($exception));

            $this->assertNotNull($message, sprintf('No translation resolved for reason "%s".', $exception->reason()->value));
            $this->assertNotSame('', $message);
            $this->assertStringNotContainsString('error.'.$exception->reason()->value, $message, 'The raw translation key leaked into the rendered message.');
        }
    }

    #[Test]
    public function theKeyParameterIsSubstitutedIntoTheTranslatedMessage(): void
    {
        $message = $this->translator->messageFor($this->wrap(EligibilityException::productAlreadyOwned('monument')));

        $this->assertStringContainsString('monument', (string) $message);
        $this->assertStringNotContainsString('{key}', (string) $message);
    }

    #[Test]
    public function aUniqueConstraintViolationIsTranslatedToAPlayerFacingRetryMessage(): void
    {
        $violation = $this->createStub(UniqueConstraintViolationException::class);
        $message = $this->translator->messageFor($this->wrap($violation));

        $this->assertNotNull($message);
    }

    #[Test]
    public function anUnrelatedExceptionResolvesToNull(): void
    {
        $message = $this->translator->messageFor($this->wrap(new \RuntimeException('boom')));

        $this->assertNull($message);
    }

    /** @return list<ShopException> */
    private function everyShopException(): array
    {
        return [
            CartException::empty(),
            EligibilityException::productAlreadyOwned('monument'),
            OrderException::windowAlreadyValidated(),
            OrderException::alreadyValidated(),
            OrderException::linesLocked(),
            OrderException::rejectionUnavailable(),
            PromotionException::invalidGift('monument'),
            PromotionException::allocationRequired('monument'),
            PromotionException::invalidAllocation('monument'),
        ];
    }

    private function wrap(\Throwable $exception): HandlerFailedException
    {
        return new HandlerFailedException(new Envelope(new \stdClass()), [$exception]);
    }
}
