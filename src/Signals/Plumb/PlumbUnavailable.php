<?php

declare(strict_types=1);

namespace App\Signals\Plumb;

/**
 * Plumb asked us to stop, or could not answer at all.
 *
 * Its own type for the same reason PackagistRateLimited has one: a 429 or a 500 is
 * not a per-package failure to log and step over. It says the next four hundred
 * requests will fail too, and sending them anyway is useless and rude to a free
 * service that owes this project nothing.
 *
 * Missing a week of security findings costs almost nothing, because the scores the
 * sweep already stored stay on the page with the date they were taken. Being blocked
 * costs the feature.
 */
final class PlumbUnavailable extends \RuntimeException
{
}
