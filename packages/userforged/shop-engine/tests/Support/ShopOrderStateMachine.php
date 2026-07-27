<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Tests\Support;

use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition;

/**
 * The library's own shop_order state machine, assembled by hand because these
 * tests run on an autoloader and nothing else — no kernel ever reads
 * config/workflow.yaml here.
 *
 * It therefore has to mirror that file exactly. A place or transition added
 * there and not here would leave the new behaviour untested while every
 * existing test stayed green, so treat the two as one edit.
 */
final class ShopOrderStateMachine
{
    public static function create(): StateMachine
    {
        return new StateMachine(
            new Definition(
                ['pending', 'validated', 'rejected'],
                [
                    new Transition('validate', 'pending', 'validated'),
                    new Transition('reject', 'pending', 'rejected'),
                    new Transition('resubmit', 'rejected', 'pending'),
                ],
                'pending',
            ),
            new MethodMarkingStore(singleState: true, property: 'marking'),
            name: 'shop_order',
        );
    }
}
