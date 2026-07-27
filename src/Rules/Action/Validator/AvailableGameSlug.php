<?php

declare(strict_types=1);

namespace App\Rules\Action\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class AvailableGameSlug extends Constraint
{
    /**
     * Governance rule: every first-level static path (e.g. /rules, /stats)
     * must be added here BEFORE its route is created, and checked against
     * existing game slugs in the database — otherwise it either shadows, or
     * is shadowed by, the /{slug} wildcard (GameController::dashboard()).
     *
     * @var list<string>
     */
    public const array RESERVED_SLUGS = ['create'];

    public string $blankMessage = 'Game name is required.';
    public string $reservedMessage = 'This name is reserved.';
    public string $takenMessage = 'Slug "{{ slug }}" is not available.';
}
