<?php

declare(strict_types=1);

namespace App\Rules\Action\Validator;

use App\Rules\Action\CreateGame;
use App\Rules\Ruleset\Scenario;
use App\Rules\Ruleset\ScenarioRegistry;
use App\State\Region;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class KnownScenarioValidator extends ConstraintValidator
{
    public function __construct(private readonly ScenarioRegistry $scenarioRegistry) {}

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof KnownScenario) {
            throw new UnexpectedTypeException($constraint, KnownScenario::class);
        }

        if (!$value instanceof CreateGame) {
            throw new UnexpectedValueException($value, CreateGame::class);
        }

        $region = null === $value->region ? null : Region::tryFrom($value->region);

        if (null !== $value->region && null === $region) {
            return;
        }

        if ($this->scenarioRegistry->find($value->playerCount, $region) instanceof Scenario) {
            return;
        }

        $this->context->buildViolation($constraint->message)
            ->setParameter('{{ count }}', (string) $value->playerCount)
            ->atPath('region')
            ->addViolation()
        ;
    }
}
