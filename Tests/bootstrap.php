<?php

declare(strict_types=1);

// The bundle has no dev dependencies of its own: PHPUnit and the Oro platform come from the
// application it is installed into. Find that application's autoloader — AAXIS_AUTOLOAD wins, then
// the vendor/ directory this package sits in (vendor/aaxisdigital/oro-ontology/Tests).
$autoload = null;
foreach ([getenv('AAXIS_AUTOLOAD') ?: null, \dirname(__DIR__, 3) . '/autoload.php'] as $candidate) {
    if ($candidate !== null && is_file($candidate)) {
        $autoload = $candidate;
        break;
    }
}
if ($autoload === null) {
    fwrite(STDERR, "Cannot find an Oro application autoloader. Set AAXIS_AUTOLOAD=/path/to/vendor/autoload.php.\n");
    exit(1);
}

$loader = require $autoload;

// Always exercise the code in this working copy, not a vendor/ copy of the same package.
$loader->addPsr4('Aaxis\\Bundle\\OntologyBundle\\', \dirname(__DIR__), true);

// Every Aaxis bundle builds on AaxisCommonBundle; take it from the sibling checkout (or the
// AAXIS_COMMON_DIR override) so tests never depend on a stale vendor/ copy.
$common = getenv('AAXIS_COMMON_DIR') ?: \dirname(__DIR__, 2) . '/oro-common';
if (is_dir($common)) {
    $loader->addPsr4('Aaxis\\Bundle\\CommonBundle\\', $common, true);
}
