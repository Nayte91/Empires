<?php

declare(strict_types=1);

namespace App\Rules\Action\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class KnownScenario extends Constraint
{
    public string $message = 'No scenario is played by {{ count }} players in this region.';

    #[\Override]
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
