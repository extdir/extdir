<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// The recipe guards this with method_exists() to support Dotenv versions that
// predate bootEnv(). We require symfony/dotenv 7.4, where it always exists.
(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
