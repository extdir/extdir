<?php

declare(strict_types=1);

namespace App\Signals\Forge;

use App\Catalog\Entity\Extension;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Reads maintenance signals from a forge that is not GitHub.
 *
 * One implementation per forge, selected by SourceHost. Each returns null rather
 * than throwing when a repository is private, deleted or on an instance that is
 * simply down, all three are ordinary states for a corpus assembled from a decade
 * of Packagist metadata, and none of them is an application error.
 */
#[AutoconfigureTag('app.forge_client')]
interface ForgeClient
{
    public function supports(Extension $extension): bool;

    public function fetch(Extension $extension): ?ForgeSignals;
}
