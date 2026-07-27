<?php

declare(strict_types=1);

namespace App\Rules\Action\Validator;

use App\Infrastructure\Repository\GameRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class AvailableGameSlugValidator extends ConstraintValidator
{
    public function __construct(private readonly GameRepository $gameRepository) {}

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof AvailableGameSlug) {
            throw new UnexpectedTypeException($constraint, AvailableGameSlug::class);
        }

        if (!\is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        if ('' === $value) {
            $this->context->buildViolation($constraint->blankMessage)->addViolation();

            return;
        }

        if (\in_array($value, AvailableGameSlug::RESERVED_SLUGS, true)) {
            $this->context->buildViolation($constraint->reservedMessage)->addViolation();

            return;
        }

        if (null !== $this->gameRepository->findOneBy(['slug' => $value])) {
            $this->context->buildViolation($constraint->takenMessage)
                ->setParameter('{{ slug }}', $value)
                ->addViolation()
            ;
        }
    }
}
