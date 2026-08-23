<?php

declare(strict_types=1);

namespace App\Ui\Api;

use App\Catalog\Entity\Extension;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * One extension, as JSON.
 *
 * The field names are the load-bearing part. This directory exists because "it is on
 * Packagist" and "you may install it" are different statements, and an agent reading
 * this has no page to look at, no badge to notice and no licence column to read. It has
 * only these keys.
 *
 * So `declaresSupportFor` is not `worksWith`, because nothing here has been tested
 * against a running Shopware, and `licenceRedistributable` sits beside `licence`
 * because 109 of the indexed packages declare `proprietary` and would otherwise look
 * installable to anything that only reads an SPDX string it does not recognise.
 *
 * List and detail share this so the two shapes cannot drift; detail adds the
 * compatibility matrix and nothing else.
 */
final class ExtensionSerialiser
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Extension $extension): array
    {
        return [
            'slug' => $extension->getSlug(),
            'package' => $extension->getPackageName(),
            'label' => $extension->getLabel(),
            'description' => $extension->getDescription(),
            'vendor' => $extension->getVendor()->getName(),
            'repository' => $extension->getRepositoryUrl(),

            'licence' => [
                'spdx' => $extension->getLicenseSpdx(),
                'status' => $extension->getLicenseStatus()->value,
                // The one field an installer must read. False means all rights
                // reserved or an unrecognised declaration: indexed and linked, never
                // redistributed, and not something to suggest installing.
                'redistributable' => $extension->getLicenseStatus()->isRedistributable(),
            ],

            'maintenance' => [
                'status' => $extension->getMaintenanceStatus()->value,
                'abandonedByMaintainer' => $extension->isAbandoned(),
                'lastCommit' => $extension->getLastCommitAt()?->format(\DateTimeInterface::ATOM),
                'lastRelease' => $extension->getLastReleaseAt()?->format(\DateTimeInterface::ATOM),
            ],

            'install' => [
                // Composer can only resolve it by name if Packagist carries it. When
                // this is false the extdir repository has to be added first, which is
                // what /repo documents.
                'onPackagist' => $extension->isOnPackagist(),
                'technicalName' => $extension->getTechnicalName(),
            ],

            // Shown on the site, fed into no ranking, and repeated here with the same
            // separation so nothing downstream mistakes popularity for quality.
            'popularity' => [
                'stars' => $extension->getStars(),
                'downloadsTotal' => $extension->hasPackagistStats() ? $extension->getDownloadsTotal() : null,
                'downloadsMonthly' => $extension->hasPackagistStats() ? $extension->getDownloadsMonthly() : null,
            ],

            'rankScore' => $extension->getRankScore(),

            // Absolute, and present on every record. An answer that cites this
            // directory should be able to send the reader to the page it came from,
            // which is the whole basis on which the content signals say ai-input=yes.
            'url' => $this->urls->generate(
                'extension_detail',
                ['slug' => $extension->getSlug()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
        ];
    }

    /**
     * The same record with its compatibility matrix.
     *
     * @param array<string, string> $matrix majorMinor => constraint tier
     *
     * @return array<string, mixed>
     */
    public function toArrayWithCompatibility(Extension $extension, array $matrix): array
    {
        $record = $this->toArray($extension);

        // Named for what it is. The maintainer wrote a constraint; we parsed it. No
        // release here has been installed against a running Shopware, and a key called
        // worksWith would be a claim this project has never made.
        $record['declaresSupportFor'] = $matrix;

        return $record;
    }
}
