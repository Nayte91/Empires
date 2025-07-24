<?php

namespace App\Repository;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Serializer\SerializerInterface;

abstract class SessionManager {
    protected const string SESSION_KEY = 'empires.game_session';

    public function __construct(
        private readonly RequestStack $requestStack,
        protected SerializerInterface $serializer,
    ) {}

    private function extractEntityClassFromDocBlock(): string
    {
        $reflection = new \ReflectionClass($this);
        $docComment = $reflection->getDocComment();

        if ($docComment === false) {
            throw new \RuntimeException('Aucun DocBlock trouvé sur la classe ' . $reflection->getName());
        }

        if (preg_match('/@extends\s+SessionManager<([^>]+)>/', $docComment, $matches)) {
            return trim($matches[1]);
        }

        throw new \RuntimeException('Pattern @extends SessionManager<EntityClass> non trouvé dans ' . $reflection->getName());
    }

    final public function save(object $object): void
    {
        $data = $this->serializer->serialize($object, 'json');

        $this->getSession()->set($this->getEntityKey(), $data);
    }

    final public function find(): mixed
    {
        $data = $this->getSession()->get($this->getEntityKey());

        if ($data === null) return null;

        return $this->serializer->deserialize($data, $this->getFullEntityClassname(), 'json');
    }

    final public function see(): mixed
    {
        return $this->getSession()->get($this->getEntityKey());

    }

    public function clear(): void
    {
        $this->getSession()->remove($this->getEntityKey());
    }

    protected function getSession(): SessionInterface
    {
        return $this->requestStack->getSession();
    }

    protected function exists(): bool
    {
        return $this->getSession()->has(self::SESSION_KEY);
    }

    private function getFullEntityClassname(): string
    {
        return $this->extractEntityClassFromDocBlock();
    }

    private function getEntityKey(): string
    {
        return strtolower(self::SESSION_KEY . $this->getFullEntityClassname());
    }
}
