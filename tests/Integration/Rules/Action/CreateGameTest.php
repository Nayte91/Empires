<?php

declare(strict_types=1);

namespace App\Tests\Integration\Rules\Action;

use App\Rules\Action\CreateGame;
use App\State\Game;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The command is where the entity's declared widths become a rule anyone can act on, and the only
 * place that rule exists: every environment runs SQLite, which stores whatever it is handed
 * whatever the column says, so a value past the bound persists in silence rather than failing.
 *
 * $region is pinned here rather than on GameCreator because the component cannot show the
 * difference: the region select offers two four-letter values and onScenarioUpdated() rewrites
 * anything else back to one of them, so a sixteen- and a seventeen-character region are equally
 * unreachable through the UI and, once injected, are both drowned by the empire-scenario issues
 * they also cause. This is the level at which the two lengths still differ.
 */
final class CreateGameTest extends WebTestCase
{
    private ValidatorInterface $validator; // @phpstan-ignore property.uninitialized (initialized in setUp)

    protected function setUp(): void
    {
        self::bootKernel();

        $this->validator = self::getContainer()->get(ValidatorInterface::class);
    }

    /**
     * Asserted on the whole violation list, not on the presence of the length message: $slug carries
     * AvailableGameSlug alongside the new Length, and the two are independent top-level constraints
     * rather than a Sequentially, so both run on every value. An over-long name is not blank, not
     * reserved and — no over-long game being creatable any more — not taken either, so the operator
     * must be handed the one problem they can act on and nothing else.
     */
    #[Test]
    #[DataProvider('provideAGameNameOverTheLengthLimitReportsItsLengthAsItsOnlyProblemCases')]
    public function aGameNameOverTheLengthLimitReportsItsLengthAsItsOnlyProblem(int $length): void
    {
        $createGame = new CreateGame();
        $createGame->slug = str_repeat('a', $length);

        $messages = $this->violationMessagesFor($createGame, 'slug');

        $this->assertSame(['Game name cannot be longer than 64 characters.'], $messages);
    }

    public static function provideAGameNameOverTheLengthLimitReportsItsLengthAsItsOnlyProblemCases(): iterable
    {
        yield 'one character over the limit' => [Game::MAX_SLUG_LENGTH + 1];

        yield 'far over the limit, the length SQLite was measured storing' => [300];
    }

    /**
     * The positive control for the row above, taken at the limit rather than in the comfortable
     * middle: a bound compared with the wrong operator refuses exactly this name and nothing else.
     */
    #[Test]
    #[DataProvider('provideAGameNameUpToTheLengthLimitRaisesNoViolationCases')]
    public function aGameNameUpToTheLengthLimitRaisesNoViolation(int $length): void
    {
        $createGame = new CreateGame();
        $createGame->slug = str_repeat('a', $length);

        $messages = $this->violationMessagesFor($createGame, 'slug');

        $this->assertSame([], $messages);
    }

    public static function provideAGameNameUpToTheLengthLimitRaisesNoViolationCases(): iterable
    {
        yield 'a single character' => [1];

        yield 'one character below the limit' => [Game::MAX_SLUG_LENGTH - 1];

        yield 'exactly the limit' => [Game::MAX_SLUG_LENGTH];
    }

    #[Test]
    #[DataProvider('provideARegionOverTheLengthLimitIsRefusedCases')]
    public function aRegionOverTheLengthLimitIsRefused(int $length): void
    {
        $createGame = new CreateGame();
        $createGame->region = str_repeat('r', $length);

        $messages = $this->violationMessagesFor($createGame, 'region');

        $this->assertSame(['Region cannot be longer than 16 characters.'], $messages);
    }

    public static function provideARegionOverTheLengthLimitIsRefusedCases(): iterable
    {
        yield 'one character over the limit' => [Game::MAX_REGION_LENGTH + 1];

        yield 'far over the limit' => [300];
    }

    /**
     * "west" is the control that matters — the value the form actually sends, which a bound applied
     * to the wrong property or with the wrong operator would refuse. null is here because a game of
     * ten players or more has no region at all, and Length must let that through untouched.
     */
    #[Test]
    #[DataProvider('provideARegionUpToTheLengthLimitRaisesNoViolationCases')]
    public function aRegionUpToTheLengthLimitRaisesNoViolation(?string $region): void
    {
        $createGame = new CreateGame();
        $createGame->region = $region;

        $messages = $this->violationMessagesFor($createGame, 'region');

        $this->assertSame([], $messages);
    }

    public static function provideARegionUpToTheLengthLimitRaisesNoViolationCases(): iterable
    {
        yield 'the region the form sends' => ['west'];

        yield 'exactly the limit' => [str_repeat('r', Game::MAX_REGION_LENGTH)];

        yield 'no region, as every game of ten players or more' => [null];
    }

    /** @return list<string> */
    private function violationMessagesFor(CreateGame $createGame, string $property): array
    {
        $messages = [];

        foreach ($this->validator->validateProperty($createGame, $property) as $violation) {
            $messages[] = (string) $violation->getMessage();
        }

        return $messages;
    }
}
