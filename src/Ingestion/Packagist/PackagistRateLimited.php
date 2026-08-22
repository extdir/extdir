<?php

declare(strict_types=1);

namespace App\Ingestion\Packagist;

/**
 * Packagist asked us to stop.
 *
 * Its own type, because a 429 is the one failure in a sweep that must not be treated
 * like the others. Every other error affects a single package and the right response is
 * to log it and carry on; a 429 says the next several hundred requests will fail too,
 * and continuing to send them is both useless and rude to a free service this project
 * depends on entirely.
 */
final class PackagistRateLimited extends \RuntimeException
{
}
