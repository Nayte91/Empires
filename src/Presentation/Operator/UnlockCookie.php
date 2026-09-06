<?php

declare(strict_types=1);

namespace App\Presentation\Operator;

use App\State\Game;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

final readonly class UnlockCookie
{
    private const string COOKIE_NAME = 'empires_operator';

    public function __construct(#[Autowire(param: 'kernel.secret')] private string $secret) {}

    public function grant(Game $game): Cookie
    {
        return Cookie::create(
            name: self::COOKIE_NAME,
            value: $this->expectedValue($game),
            expire: new \DateTimeImmutable('+30 days'),
            path: $this->path($game),
        );
    }

    public function revoke(Game $game): Cookie
    {
        return Cookie::create(
            name: self::COOKIE_NAME,
            expire: 1,
            path: $this->path($game),
        );
    }

    public function isValid(Request $request, Game $game): bool
    {
        if (!$game->locked) {
            return false;
        }

        $cookie = $request->cookies->get(self::COOKIE_NAME);

        return null !== $cookie && hash_equals($this->expectedValue($game), $cookie);
    }

    private function expectedValue(Game $game): string
    {
        return hash_hmac('sha256', $game->id->toRfc4122().'|'.$game->operatorPinHash, $this->secret);
    }

    private function path(Game $game): string
    {
        return '/game/'.$game->slug.'/operator';
    }
}
