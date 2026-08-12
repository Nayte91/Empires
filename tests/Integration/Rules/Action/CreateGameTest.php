<?php

declare(strict_types=1);

namespace App\Tests\Integration\Rules\Action;

use App\Rules\Action\CreateGame;
use App\State\Game;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class CreateGameTest extends WebTestCase
{
    private ValidatorInterface $validator; // @phpstan-ignore property.uninitialized (initialized in setUp)

    protected function setUp(): void
    {
        self::bootKernel();

        $this->validator = self::getContainer()->get(ValidatorInterface::class);
    }

    #[Test]
    #[DataProvider('provideAGameNameOverTheLengthLimitReportsItsLengthAsItsOnlyProblemCases')]
    public function aGameNameOverTheLengthLimitReportsItsLengthAsItsOnlyProblem(int $length): void
    {
        $createGame = new CreateGame();
        $createGame->slug = str_repeat('a', $length);

        $codes = $this->violationCodesFor($createGame, 'slug');

        $this->assertSame([Length::TOO_LONG_ERROR], $codes);
    }

    public static function provideAGameNameOverTheLengthLimitReportsItsLengthAsItsOnlyProblemCases(): iterable
    {
        yield 'one character over the limit' => [Game::MAX_SLUG_LENGTH + 1];

        yield 'far over the limit, the length SQLite was measured storing' => [300];
    }

    #[Test]
    #[DataProvider('provideAGameNameUpToTheLengthLimitRaisesNoViolationCases')]
    public function aGameNameUpToTheLengthLimitRaisesNoViolation(int $length): void
    {
        $createGame = new CreateGame();
        $createGame->slug = str_repeat('a', $length);

        $codes = $this->violationCodesFor($createGame, 'slug');

        $this->assertSame([], $codes);
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

        $codes = $this->violationCodesFor($createGame, 'region');

        $this->assertSame([Length::TOO_LONG_ERROR], $codes);
    }

    public static function provideARegionOverTheLengthLimitIsRefusedCases(): iterable
    {
        yield 'one character over the limit' => [Game::MAX_REGION_LENGTH + 1];

        yield 'far over the limit' => [300];
    }

    #[Test]
    #[DataProvider('provideARegionUpToTheLengthLimitRaisesNoViolationCases')]
    public function aRegionUpToTheLengthLimitRaisesNoViolation(?string $region): void
    {
        $createGame = new CreateGame();
        $createGame->region = $region;

        $codes = $this->violationCodesFor($createGame, 'region');

        $this->assertSame([], $codes);
    }

    public static function provideARegionUpToTheLengthLimitRaisesNoViolationCases(): iterable
    {
        yield 'the region the form sends' => ['west'];

        yield 'exactly the limit' => [str_repeat('r', Game::MAX_REGION_LENGTH)];

        yield 'no region, as every game of ten players or more' => [null];
    }

    /** @return list<?string> */
    private function violationCodesFor(CreateGame $createGame, string $property): array
    {
        $codes = [];

        foreach ($this->validator->validateProperty($createGame, $property) as $violation) {
            $codes[] = $violation->getCode();
        }

        return $codes;
    }
}
