<?php

declare(strict_types=1);

namespace App\Tests\Component;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class ModalTest extends WebTestCase
{
    use InteractsWithTwigComponents;

    #[Test]
    public function theDialogCarriesTheIdItWasGiven(): void
    {
        $crawler = $this->renderTwigComponent('molecules:Modal', ['id' => 'rename-player-7'])->crawler();

        $this->assertCount(1, $crawler->filter('dialog#rename-player-7'));
    }

    #[Test]
    public function aCallersOwnClassJoinsTheSharedOneRatherThanReplacingIt(): void
    {
        $crawler = $this->renderTwigComponent('molecules:Modal', ['id' => 'x', 'class' => 'pos-dialog'])->crawler();

        $this->assertSame('modal pos-dialog', $crawler->filter('dialog')->attr('class'));
    }

    #[Test]
    public function lightDismissIsOnUnlessTheCallerRefusesIt(): void
    {
        $default = $this->renderTwigComponent('molecules:Modal', ['id' => 'x'])->crawler();
        $refused = $this->renderTwigComponent('molecules:Modal', ['id' => 'x', 'closedby' => false])->crawler();

        $this->assertSame('any', $default->filter('dialog')->attr('closedby'));
        $this->assertNull($refused->filter('dialog')->attr('closedby'));
    }

    #[Test]
    public function refusingLightDismissWithNullFailsLoudlyRatherThanSilently(): void
    {
        $this->expectException(\Twig\Error\RuntimeError::class);

        $this->renderTwigComponent('molecules:Modal', ['id' => 'x', 'closedby' => null]);
    }

    #[Test]
    public function aServerOpenedDialogKeepsItsBareOpenAttribute(): void
    {
        $crawler = $this->renderTwigComponent('molecules:Modal', ['id' => 'x', 'open' => true])->crawler();

        $this->assertSame('', $crawler->filter('dialog')->attr('open'));
    }
}
