<?php

declare(strict_types=1);

namespace App\Tests\Support\Workflow;

use Symfony\Component\Workflow\DefinitionBuilder;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition;

/**
 * Builds the shop_order state machine manually — mirrors config/packages/workflow.yaml
 * without booting the kernel, so unit-level tests need no kernel-registered listeners.
 */
final class ShopOrderStateMachine
{
    public static function create(): StateMachine
    {
        $definition = (new DefinitionBuilder(
            places: ['pending', 'validated', 'rejected'],
            transitions: [
                new Transition('validate', 'pending', 'validated'),
                new Transition('reject', 'pending', 'rejected'),
                new Transition('resubmit', 'rejected', 'pending'),
            ],
        ))
            ->setInitialPlaces('pending')
            ->build();

        return new StateMachine($definition, new MethodMarkingStore(singleState: true, property: 'marking'), name: 'shop_order');
    }
}
