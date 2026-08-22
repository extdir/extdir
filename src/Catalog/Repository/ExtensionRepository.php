<?php

declare(strict_types=1);

namespace App\Catalog\Repository;

use App\Catalog\Entity\Extension;
use App\Catalog\Entity\Vendor;
use App\Catalog\Enum\DiscoverySource;
use App\Catalog\Enum\IndexStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Extension>
 */
class ExtensionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Extension::class);
    }

    public function findOneByPackageName(string $packageName): ?Extension
    {
        return $this->findOneBy(['packageName' => $packageName]);
    }

    public function findOneBySlug(string $slug): ?Extension
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * Package names already known, as a lookup set.
     *
     * Discovery compares a ~1,500-entry Packagist list against what we hold; doing
     * that with one findOneBy per package is 1,500 round trips for a set difference
     * that fits comfortably in memory.
     *
     * @return array<string, true>
     */
    public function findAllPackageNames(): array
    {
        /** @var list<array{packageName: string}> $rows */
        $rows = $this->createQueryBuilder('e')
            ->select('e.packageName')
            ->getQuery()
            ->getArrayResult();

        $names = [];
        foreach ($rows as $row) {
            $names[$row['packageName']] = true;
        }

        return $names;
    }

    /**
     * Discovery source per package name.
     *
     * Tells the GitHub sweep whether Packagist owns a package's release data — in
     * which case re-reading a bounded window of tags would replace complete metadata
     * with a worse copy — or whether this sweep is the only thing that will ever
     * refresh it.
     *
     * @return array<string, DiscoverySource>
     */
    public function findAllPackageSources(): array
    {
        /** @var list<array{packageName: string, discoverySource: DiscoverySource}> $rows */
        $rows = $this->createQueryBuilder('e')
            ->select('e.packageName', 'e.discoverySource')
            ->getQuery()
            ->getArrayResult();

        $sources = [];

        foreach ($rows as $row) {
            $sources[$row['packageName']] = $row['discoverySource'];
        }

        return $sources;
    }

    /**
     * Known repositories, keyed by lowercased "owner/repo".
     *
     * Discovery works in repository names while the catalogue is keyed by package
     * name, and the two only connect after composer.json has been read. Without this
     * map, deciding whether a candidate is already indexed means assembling it first —
     * two GraphQL round trips per repository, spent mostly on extensions we already
     * have.
     *
     * The value is the discovery source, because the decision is not simply "skip if
     * known": an extension that arrived from Packagist has its releases refreshed
     * nightly from there, but one found on GitHub is refreshed only by re-running that
     * discovery, so skipping it would freeze it at the tags it had when first seen.
     *
     * @return array<string, DiscoverySource>
     */
    public function findAllRepositorySlugs(): array
    {
        /** @var list<array{repositoryUrl: string|null, discoverySource: DiscoverySource}> $rows */
        $rows = $this->createQueryBuilder('e')
            ->select('e.repositoryUrl', 'e.discoverySource')
            ->where('e.repositoryUrl IS NOT NULL')
            ->getQuery()
            ->getArrayResult();

        $slugs = [];

        foreach ($rows as $row) {
            $slug = self::slugFromUrl($row['repositoryUrl']);

            if (null !== $slug) {
                $slugs[$slug] = $row['discoverySource'];
            }
        }

        return $slugs;
    }

    /**
     * The "owner/repo" inside a repository URL, lowercased, or null.
     *
     * Only ever used to skip work, never to decide correctness: a URL shape this does
     * not recognise costs one redundant fetch, which the package-name dedupe then
     * catches. That asymmetry is why it can afford to be simple.
     */
    public static function slugFromUrl(?string $url): ?string
    {
        if (null === $url || '' === trim($url)) {
            return null;
        }

        $path = parse_url(trim($url), \PHP_URL_PATH);

        if (!\is_string($path)) {
            return null;
        }

        $path = preg_replace('/\.git$/i', '', trim($path, '/')) ?? '';
        $parts = explode('/', $path);

        // Exactly two segments. A deeper path is a GitLab subgroup or a link to a file,
        // neither of which is a repository root we can compare against.
        if (2 !== \count($parts) || '' === $parts[0] || '' === $parts[1]) {
            return null;
        }

        return strtolower($parts[0].'/'.$parts[1]);
    }

    /**
     * Everything that may appear publicly, ordered for stable output.
     *
     * Shares the visibility rule with search rather than restating it: a delisted
     * extension that vanished from the listing but lingered in the sitemap would
     * keep being re-crawled and re-surfaced, which is a takedown that only half
     * happened.
     *
     * @return list<Extension>
     */
    public function findPubliclyVisible(): array
    {
        /** @var list<Extension> $result */
        $result = $this->createQueryBuilder('e')
            ->where('e.indexStatus IN (:visible)')
            ->setParameter('visible', [IndexStatus::Listed, IndexStatus::IndexOnly])
            ->orderBy('e.rankScore', 'DESC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Most installed of all time, per Packagist.
     *
     * Restricted to extensions Packagist has actually answered for. A zero download
     * count means one of two completely different things — nobody installs it, or it is
     * one of the 170 indexed extensions that are not on Packagist at all — and letting
     * the second masquerade as the first would quietly libel exactly the agency-built
     * repositories the search channel was added to find.
     *
     * @return list<Extension>
     */
    public function topByDownloads(int $limit): array
    {
        return $this->popularityBoard('e.downloadsTotal', $limit, measuredOnly: true);
    }

    /**
     * Most installed over the trailing thirty days.
     *
     * The board that corrects the one above. A lifetime total only ever accumulates, so
     * on its own it ranks by age as much as by use and an extension nobody has installed
     * since 2021 can still sit near the top. This one cannot say that.
     *
     * @return list<Extension>
     */
    public function topByMonthlyDownloads(int $limit): array
    {
        return $this->popularityBoard('e.downloadsMonthly', $limit, measuredOnly: true);
    }

    /**
     * Most starred on the forge.
     *
     * The weakest signal on the page, and the page says so. It earns its place only
     * because it is the one popularity measure that reaches the extensions Packagist
     * cannot see — without it, a third of the catalogue appears on no board at all.
     *
     * @return list<Extension>
     */
    public function topByStars(int $limit): array
    {
        return $this->popularityBoard('e.stars', $limit, measuredOnly: false);
    }

    /**
     * One extension board.
     *
     * Same visibility rule as the public listing, deliberately: a board is a placement
     * surface, and anything shown here must already be shown in the catalogue.
     *
     * @return list<Extension>
     */
    private function popularityBoard(string $field, int $limit, bool $measuredOnly): array
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.indexStatus IN (:visible)')
            ->andWhere($field.' > 0')
            ->setParameter('visible', [IndexStatus::Listed, IndexStatus::IndexOnly])
            ->orderBy($field, 'DESC')
            // Reproducible ties, for the same reason the vendor boards break theirs on
            // name: an order nobody can predict is not a published rule.
            ->addOrderBy('e.packageName', 'ASC')
            ->setMaxResults($limit);

        if ($measuredOnly) {
            $qb->andWhere('e.packagistCheckedAt IS NOT NULL');
        }

        /** @var list<Extension> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * How many visible extensions Packagist has given us counts for.
     *
     * The boards print this beside the download tables. Derived rather than written
     * down, because a hardcoded coverage figure is wrong the first time an extension is
     * indexed and nobody notices for months.
     *
     * @return array{measured: int, total: int}
     */
    public function packagistCoverage(): array
    {
        /** @var array{measured: string|int, total: string|int} $row */
        $row = $this->createQueryBuilder('e')
            ->select('COUNT(e.id) AS total', 'SUM(CASE WHEN e.packagistCheckedAt IS NULL THEN 0 ELSE 1 END) AS measured')
            ->where('e.indexStatus IN (:visible)')
            ->setParameter('visible', [IndexStatus::Listed, IndexStatus::IndexOnly])
            ->getQuery()
            ->getSingleResult();

        return ['measured' => (int) $row['measured'], 'total' => (int) $row['total']];
    }

    /**
     * Everything visible from one vendor, best first.
     *
     * @return list<Extension>
     */
    public function findVisibleForVendor(Vendor $vendor): array
    {
        /** @var list<Extension> $result */
        $result = $this->createQueryBuilder('e')
            ->where('e.vendor = :vendor')
            ->andWhere('e.indexStatus IN (:visible)')
            ->setParameter('vendor', $vendor)
            ->setParameter('visible', [IndexStatus::Listed, IndexStatus::IndexOnly])
            ->orderBy('e.rankScore', 'DESC')
            ->addOrderBy('e.label', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Extensions whose metadata is stalest, for incremental crawling.
     *
     * @return list<Extension>
     */
    public function findStalest(int $limit, ?\DateTimeImmutable $olderThan = null): array
    {
        $qb = $this->createQueryBuilder('e')
            ->orderBy('e.lastCrawledAt', 'ASC')
            ->setMaxResults($limit);

        // NULLs sort first in MariaDB ascending order, so never-crawled extensions
        // are picked up before stale ones without any special casing.
        if (null !== $olderThan) {
            $qb->andWhere('e.lastCrawledAt IS NULL OR e.lastCrawledAt < :cutoff')
                ->setParameter('cutoff', $olderThan);
        }

        /** @var list<Extension> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }
}
