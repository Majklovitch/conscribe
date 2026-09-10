<?php

/**
 * Test bootstrap.
 *
 * Deliberately not App\Bootstrap: that one boots error handling, assets,
 * security headers and a session, none of which a unit test wants and several
 * of which cannot work under the CLI SAPI at all. The tests build the objects
 * they need themselves - which is only possible because the core allows it,
 * and is worth keeping true.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Config is a static singleton reading app/Config/main.php, which is not in
// version control and differs per machine. Seeding it with an empty array
// makes every Config::get() fall back to its default, so a test never depends
// on the developer's local configuration.
\App\Core\Config::set([]);
