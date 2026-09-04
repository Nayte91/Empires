<?php

declare(strict_types=1);

namespace App\Tests\Component;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class SectionSwitcherTest extends WebTestCase
{
    use InteractsWithTwigComponents;

    #[Test]
    public function everyTabBecomesAListedRadioAndItsLabelInTheGivenOrder(): void
    {
        $crawler = $this->renderSectionSwitcher($this->dashboardTabs(), 'roster');
        $radios = $crawler->filter('menu li input[type="radio"]');

        $this->assertSame(
            ['tab', 'tab', 'tab', 'tab'],
            $radios->each(static fn (Crawler $radio): ?string => $radio->attr('name')),
        );
        $this->assertSame(
            ['tab-roster', 'tab-ast', 'tab-nav', 'tab-help'],
            $radios->each(static fn (Crawler $radio): ?string => $radio->attr('id')),
        );
        $this->assertSame(
            ['roster', 'ast', 'nav', 'help'],
            $radios->each(static fn (Crawler $radio): ?string => $radio->attr('value')),
        );
        $this->assertSame(
            ['tab-roster', 'tab-ast', 'tab-nav', 'tab-help'],
            $crawler->filter('menu li label')->each(static fn (Crawler $label): ?string => $label->attr('for')),
        );
    }

    #[Test]
    #[DataProvider('provideTheOnlyCheckedRadioIsTheActiveTabCases')]
    public function theOnlyCheckedRadioIsTheActiveTab(string $active): void
    {
        $crawler = $this->renderSectionSwitcher($this->dashboardTabs(), $active);

        $this->assertCount(1, $crawler->filter('menu input[checked]'));
        $this->assertSame($active, $crawler->filter('menu input[checked]')->attr('value'));
    }

    /** @return iterable<string, array{string}> */
    public static function provideTheOnlyCheckedRadioIsTheActiveTabCases(): iterable
    {
        yield 'the first tab of the bar' => ['roster'];

        yield 'the last tab of the bar' => ['help'];
    }

    #[Test]
    public function aTabCarryingAHrefBecomesALinkInsteadOfARadio(): void
    {
        $crawler = $this->renderSectionSwitcher($this->operatorTabs(), 'board');

        $this->assertCount(0, $crawler->filter('menu input'));
        $this->assertSame(
            ['/west/operator', '/west/operator/orders', '/west/operator/calamities'],
            $crawler->filter('menu li a')->each(static fn (Crawler $link): ?string => $link->attr('href')),
        );
        $this->assertSame(
            [1, 1, 1],
            $crawler->filter('menu li a')->each(static fn (Crawler $link): int => $link->filter('svg')->count()),
        );
    }

    #[Test]
    #[DataProvider('provideTheOnlyLinkMarkedAsCurrentIsTheActiveTabCases')]
    public function theOnlyLinkMarkedAsCurrentIsTheActiveTab(string $active, string $expectedHref): void
    {
        $crawler = $this->renderSectionSwitcher($this->operatorTabs(), $active);

        $this->assertCount(1, $crawler->filter('menu a[aria-current="page"]'));
        $this->assertSame($expectedHref, $crawler->filter('menu a[aria-current="page"]')->attr('href'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideTheOnlyLinkMarkedAsCurrentIsTheActiveTabCases(): iterable
    {
        yield 'the first tab of the bar' => ['board', '/west/operator'];

        yield 'the last tab of the bar' => ['calamities', '/west/operator/calamities'];
    }

    #[Test]
    public function theLabelTextIsTheOneTheCallerGaveAndNotTheTabValue(): void
    {
        $crawler = $this->renderSectionSwitcher($this->chronicleTabs(), 'ast');

        $this->assertSame(
            ['Ranking', 'Evolution', 'Navigation'],
            $crawler->filter('menu li label')->each(static fn (Crawler $label): string => trim($label->text())),
        );
    }

    #[Test]
    public function everyLabelCarriesExactlyOneIcon(): void
    {
        $crawler = $this->renderSectionSwitcher($this->chronicleTabs(), 'ast');

        $this->assertSame(
            [1, 1, 1],
            $crawler->filter('menu li label')->each(static fn (Crawler $label): int => $label->filter('svg')->count()),
        );
    }

    #[Test]
    public function theIconIsTheOneTheCallerGaveAndNotTheTabValue(): void
    {
        $chronicle = $this->renderSectionSwitcher($this->chronicleTabs(), 'ast');
        $dashboard = $this->renderSectionSwitcher($this->dashboardTabs(), 'roster');

        $this->assertNotSame(
            $dashboard->filter('menu li label[for="tab-ast"] svg')->html(),
            $chronicle->filter('menu li label[for="tab-ast"] svg')->html(),
        );
        $this->assertSame(
            $dashboard->filter('menu li label[for="tab-nav"] svg')->html(),
            $chronicle->filter('menu li label[for="tab-nav"] svg')->html(),
        );
    }

    /** @param list<array{value: string, label: string, icon: string, href?: string}> $tabs */
    private function renderSectionSwitcher(array $tabs, string $active): Crawler
    {
        return $this->renderTwigComponent('molecules:SectionSwitcher', ['tabs' => $tabs, 'active' => $active])->crawler();
    }

    /** @return list<array{value: string, label: string, icon: string}> */
    private function dashboardTabs(): array
    {
        return [
            ['value' => 'roster', 'label' => 'Roster', 'icon' => 'lucide:users'],
            ['value' => 'ast', 'label' => 'A.S.T.', 'icon' => 'clarity:timeline-line'],
            ['value' => 'nav', 'label' => 'Navigation', 'icon' => 'lucide:menu'],
            ['value' => 'help', 'label' => 'Help', 'icon' => 'lucide:circle-question-mark'],
        ];
    }

    /** @return list<array{value: string, label: string, icon: string}> */
    private function chronicleTabs(): array
    {
        return [
            ['value' => 'ast', 'label' => 'Ranking', 'icon' => 'lucide:trophy'],
            ['value' => 'evolution', 'label' => 'Evolution', 'icon' => 'lucide:trending-up'],
            ['value' => 'nav', 'label' => 'Navigation', 'icon' => 'lucide:menu'],
        ];
    }

    /** @return list<array{value: string, label: string, icon: string, href: string}> */
    private function operatorTabs(): array
    {
        return [
            ['value' => 'board', 'label' => 'Board', 'icon' => 'lucide:layout-grid', 'href' => '/west/operator'],
            ['value' => 'orders', 'label' => 'Orders', 'icon' => 'lucide:receipt', 'href' => '/west/operator/orders'],
            ['value' => 'calamities', 'label' => 'Calam.', 'icon' => 'lucide:triangle-alert', 'href' => '/west/operator/calamities'],
        ];
    }
}
