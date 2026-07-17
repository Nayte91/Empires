<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (file_exists(dirname(__DIR__).'/config/bootstrap.php')) {
    require dirname(__DIR__).'/config/bootstrap.php';
} elseif (method_exists(Dotenv::class, 'bootEnv')) {
    new Dotenv()->bootEnv(dirname(__DIR__).'/.env');
}

if ('test' === ($_SERVER['APP_ENV'] ?? null)) {
    $testSchemaBootstrapper = new class('bootstrap') extends KernelTestCase {
        public static function createTestSchema(): void
        {
            // Container compilation walks FrameworkBundle's config tree (prototype
            // nodes built via NodeBuilder::node(null, ...)), which triggers a "null
            // as array offset" deprecation in symfony/config on PHP 8.5. Pre-existing
            // symfony/config vs. PHP 8.5 issue, unrelated to DAMA/Doctrine — it fires
            // during bootstrap, outside any test, so failOnDeprecation never catches
            // it; it only pollutes stderr. Scoped suppression, kernel boot only.
            $previousErrorReporting = error_reporting(E_ALL & ~E_DEPRECATED);

            try {
                self::bootKernel();
            } finally {
                error_reporting($previousErrorReporting);
            }

            $entityManager = self::getContainer()->get(EntityManagerInterface::class);
            if (!$entityManager instanceof EntityManagerInterface) {
                throw new \LogicException('Expected an instance of EntityManagerInterface.');
            }

            $schemaTool = new SchemaTool($entityManager);
            $metadata = $entityManager->getMetadataFactory()->getAllMetadata();

            $schemaTool->dropDatabase();
            if ([] !== $metadata) {
                $schemaTool->createSchema($metadata);
            }

            self::ensureKernelShutdown();
        }
    };

    $testSchemaBootstrapper::createTestSchema();
}
