<?php

use App\Kernel;

/*
 * Deploy gate, deliberately the first thing in the file.
 *
 * Deploying is not atomic: rsync puts new code on disk, then composer runs, then
 * migrations. Anything served in between is new code against the old schema. On
 * 22 August at 21:25:32, 44 seconds after a release, Googlebot got a 500 from an
 * extension page because the entity already knew about extension.downloads_total
 * and the column did not exist yet.
 *
 * 503 with Retry-After is the right answer to "come back shortly": a crawler waits
 * and keeps the page, where a 500 says the page is broken and eventually costs it.
 *
 * This lives here rather than in .htaccess because Apache on this host does not
 * resolve %{DOCUMENT_ROOT} to the project directory, so a RewriteCond file test
 * silently never matches. Measured, not assumed: a probe testing DOCUMENT_ROOT
 * against a file that certainly exists did not fire, while the same test with an
 * absolute path did. __DIR__ has no such ambiguity.
 *
 * Before the autoloader, because the autoloader is one of the things a half-copied
 * deploy can break. All this needs is index.php itself, and rsync replaces that
 * whole or not at all.
 */
if (is_file(dirname(__DIR__).'/.deploying')) {
    http_response_code(503);
    header('Retry-After: 120');
    header('Cache-Control: no-store');
    header('Content-Type: text/html; charset=UTF-8');

    $page = dirname(__DIR__).'/maintenance.html';
    echo is_file($page) ? file_get_contents($page) : 'Back shortly.';

    exit;
}

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
