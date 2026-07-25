<?php

declare(strict_types=1);

foreach ([__DIR__.'/../../vendor/autoload.php', __DIR__.'/../../../../../vendor/autoload.php'] as $file) {
    if (is_file($file)) {
        $loader = require $file;

        // The host's root autoloader never merges a dependency's autoload-dev
        // (Composer only does that for the root package), so when this bootstrap
        // falls back to the host's vendor/, the package's own test namespace is
        // otherwise unresolvable. A standalone install of this package doesn't
        // need this line — its own autoload-dev already covers it — but the
        // call is harmless in that case too.
        $loader->addPsr4('Userforged\\ShopEngine\\Tests\\', __DIR__.'/../../tests/');

        return;
    }
}

throw new RuntimeException('Unable to find an autoloader — install the package standalone or from the host application.');
