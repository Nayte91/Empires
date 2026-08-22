<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * The dependency-direction invariant of src/ (CLAUDE.md, Project Architecture):
 * Presentation ──> Engine ──> Rules ──> State, and Rules/State never persist.
 * Not a PHPUnit test — loaded by phpstan through phpat, runs with make phpstan.
 */
final class LayerDependencies
{
    public function testRulesAndStateIgnoreUpperLayers(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Rules'), Selector::inNamespace('App\State'))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace('App\Engine'), Selector::inNamespace('App\Presentation'));
    }

    public function testRulesAndStateNeverPersist(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Rules'), Selector::inNamespace('App\State'))
            ->shouldNotDependOn()
            ->classes(
                Selector::classname(EntityManagerInterface::class),
                Selector::classname(EntityManager::class),
                Selector::classname(ObjectManager::class),
            );
    }

    public function testEngineIgnoresPresentation(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Engine'))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace('App\Presentation'));
    }
}
