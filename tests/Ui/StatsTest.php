<?php

declare(strict_types=1);

namespace App\Tests\Ui;

use App\Catalog\Entity\Category;
use App\Catalog\Entity\Extension;
use App\Catalog\Entity\ExtensionRelease;
use App\Catalog\Entity\Vendor;
use App\Catalog\Enum\IndexStatus;
use App\Catalog\Search\ExtensionSearch;
use App\Catalog\Search\SearchCriteria;
use App\Compatibility\Enum\ConstraintSource;
use App\Compatibility\Enum\ConstraintTier;
use App\License\Enum\FindingSource;
use App\License\Enum\LicenseStatus;
use App\Signals\Enum\MaintenanceStatus;
use App\Stats\EcosystemStats;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The statistics page, and the promises attached to it.
 *
 * A chart is an ordering with the numbers taken off the axis, so the conflict-of-
 * interest rule reaches it the same way it reaches a leaderboard: the expression
 * behind every chart has to be printed under it, and nothing a takedown removed may
 * be counted into a total.
 *
 * The other half of what is tested here is honesty about the data rather than about
 * the drawing. These charts are read by people deciding whether to trust the
 * ecosystem, and the two ways this page could mislead are showing a partial year as
 * though it were finished, and quietly counting delisted work.
 */
final class StatsTest extends WebTestCase
{
    public function testThePageRenders(): void
    {
        $client = static::createClient();
        $this->seed();

        $crawler = $client->request('GET', '/stats');

        self::assertResponseIsSuccessful();
        self::assertGreaterThanOrEqual(9, $crawler->filter('.chart')->count(), 'nine charts are expected');
    }

    /**
     * The rule is what makes a chart a measurement rather than an assertion.
     *
     * Same requirement the boards carry, for the same reason: a reader who doubts a
     * bar has to be able to find out what it counted without reading the source.
     */
    public function testEveryChartPublishesTheRuleThatProducedIt(): void
    {
        $client = static::createClient();
        $this->seed();

        $crawler = $client->request('GET', '/stats');

        self::assertSame(
            $crawler->filter('.chart')->count(),
            $crawler->filter('.chart .board-rule')->count(),
            'every chart must print the expression behind it',
        );
    }

    /**
     * No value may be reachable only by hovering.
     *
     * The column charts hide their numbers until the pointer arrives, which is fine
     * only because the same numbers sit in a table underneath. Without that, the
     * values would be unreachable by keyboard, by screen reader, and by a crawler,
     * and the chart would be a picture of data rather than data.
     */
    public function testEveryChartCarriesItsNumbersInText(): void
    {
        $client = static::createClient();
        $this->seed();

        $crawler = $client->request('GET', '/stats');

        $charts = $crawler->filter('.chart');

        self::assertSame(
            $charts->count(),
            $charts->reduce(static fn ($chart): bool => $chart->filter('.chart-numbers, .chart-legend, table.data')->count() > 0)->count(),
            'every chart needs a table or a legend carrying its values as text',
        );
    }

    /**
     * A takedown that still shows up in a total is a takedown that half happened, and
     * a total is the easiest place in the site for one to survive unnoticed.
     */
    public function testDelistedWorkIsNeverCounted(): void
    {
        self::bootKernel();
        $this->seed();

        $stats = static::getContainer()->get(EcosystemStats::class);
        $before = $stats->headline();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $vendor = new Vendor('ghost', 'ghost');
        $removed = new Extension($vendor, 'ghost/removed', 'ghost-removed', 'Removed');
        $removed->setIndexStatus(IndexStatus::Delisted);
        $removed->forceLicense('MIT', LicenseStatus::Permissive, FindingSource::ComposerJson);
        $em->persist($vendor);
        $em->persist($removed);

        $release = new ExtensionRelease($removed, '1.0.0.0', 'v1.0.0');
        $release->setReleasedAt(new \DateTimeImmutable('2021-01-01'));
        $release->applyConstraint('^6.4', ConstraintSource::Core, ConstraintTier::Caret);
        $em->persist($release);
        $em->flush();

        self::assertSame($before, $stats->headline(), 'a delisted extension must change no total');
    }

    /**
     * The page and the listing must agree about how many are permissive.
     *
     * The licence and maintenance charts read the same facet counts the catalogue
     * uses. A second implementation would agree with the first until the day it did
     * not, and nothing on either page would say which was right.
     */
    public function testTheLicenceChartCountsWhatTheCatalogueCounts(): void
    {
        $client = static::createClient();
        $this->seed();

        $facets = static::getContainer()->get(ExtensionSearch::class)->facets(new SearchCriteria());
        $crawler = $client->request('GET', '/stats');

        $permissive = $facets['licence']['permissive'] ?? 0;
        $legend = $crawler->filter('#chart-licence-mix .chart-legend')->text();

        self::assertStringContainsString((string) $permissive, $legend);
    }

    /**
     * A year that is still running is short for a reason that has nothing to do with
     * the ecosystem. Dropping it would hide real work; drawing it plain would invite
     * reading a calendar as a decline.
     */
    public function testTheCurrentYearIsMarkedUnfinished(): void
    {
        self::bootKernel();
        $this->seed();

        $years = static::getContainer()->get(EcosystemStats::class)->newExtensionsPerYear();
        $last = end($years);

        self::assertNotFalse($last);
        self::assertSame(date('Y'), $last['label']);
        self::assertTrue($last['partial'], 'the running year has to be flagged');

        foreach (\array_slice($years, 0, -1) as $year) {
            self::assertFalse($year['partial'], $year['label'].' has finished');
        }
    }

    /**
     * The page says where its own numbers are thin.
     *
     * Repositories that are not on Packagist are read from their newest thirty tags,
     * so the early years are lighter than the ecosystem really was. Stating that is
     * the difference between a measurement and a claim.
     */
    public function testThePageDisclosesTheTruncatedHistory(): void
    {
        $client = static::createClient();
        $this->seed();

        $client->request('GET', '/stats');

        self::assertStringContainsString('thirty', (string) $client->getResponse()->getContent());
    }

    /**
     * The aggregates are computed once and shared, not once per visitor.
     *
     * A dozen GROUP BY queries over every release in the catalogue cost about a second
     * and a half, and none of the answers differ between two readers.
     */
    public function testTheAggregatesAreCachedAfterTheFirstVisit(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->seed();

        $pool = static::getContainer()->get('cache.stats');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        self::assertFalse($pool->getItem('stats.payload')->isHit(), 'nothing should be cached yet');

        $client->request('GET', '/stats');

        self::assertTrue($pool->getItem('stats.payload')->isHit(), 'the payload must survive the request');
    }

    /**
     * The freshness badge is never served from the cache.
     *
     * Everything else on this page may be an hour old without harm. The badge saying
     * how old the catalogue is may not: cached, it would go on reporting the data
     * stale for an hour after a crawl had already fixed it, and the one claim a
     * reader checks before trusting any of the rest would be the only false one.
     */
    public function testTheFreshnessBadgeIsNotCachedWithTheCharts(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->seed();

        $client->request('GET', '/stats');

        $pool = static::getContainer()->get('cache.stats');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);

        $cached = $pool->getItem('stats.payload')->get();

        self::assertIsArray($cached);
        self::assertArrayHasKey('charts', $cached, 'the charts belong in the cache');
        self::assertArrayNotHasKey('catalogueStatus', $cached, 'the freshness badge does not');
    }

    /**
     * The page must never be offered to a shared cache.
     *
     * The masthead renders a moderator's navigation for a moderator, so a shared cache
     * holding this response would hand it to everybody.
     *
     * What this catches is the combination, not either half. The controller used to
     * call setPublic and setMaxAge, and they did nothing at all, because reading
     * app.user starts a session and the session listener rewrites the header to
     * private. Re-adding them alone would still not fail here. What would fail is the
     * day someone adds them back and the masthead stops reading app.user, or a header
     * is forced late enough to survive the listener. That pairing is the actual leak,
     * and it is the one nobody would notice by looking at either file on its own.
     */
    public function testThePageIsNeverOfferedToASharedCache(): void
    {
        $client = static::createClient();
        $this->seed();

        $client->request('GET', '/stats');

        self::assertStringNotContainsString(
            'public',
            (string) $client->getResponse()->headers->get('Cache-Control'),
            'a page carrying signed-in navigation must not be offered to a shared cache',
        );
    }

    /**
     * A percentage is measured against a hundred, never against the best row present.
     *
     * Scaled against its own tallest bar, a category at forty percent would paint a
     * full-width bar the moment it happened to be the healthiest one on the page, and
     * the chart would quietly stop answering "how many are maintained" and start
     * answering "compared to the best of these", without a word of it changing.
     */
    public function testAShareChartIsScaledAgainstAHundred(): void
    {
        $client = static::createClient();
        $this->seedCategories();

        $crawler = $client->request('GET', '/stats');
        $fills = $crawler->filter('#chart-category-health .chart-bar-fill');

        self::assertGreaterThan(0, $fills->count(), 'the category chart must draw something');

        // Half the healthy category is maintained, so its bar is half the track. If
        // the ceiling were the largest value present it would be the whole track.
        self::assertStringContainsString('width: 50%', (string) $fills->first()->attr('style'));
    }

    /**
     * A category of one at a hundred percent is not a finding, and on a chart sorted
     * by share it would be the first thing anybody read. It stays in the table, where
     * the denominator is in the next column and cannot be missed.
     */
    public function testATinyCategoryIsTabulatedButNotDrawn(): void
    {
        $client = static::createClient();
        $this->seedCategories();

        $crawler = $client->request('GET', '/stats');
        $chart = $crawler->filter('#chart-category-health');

        self::assertStringNotContainsString('Tiny', $chart->filter('.chart-bars')->text());
        self::assertStringContainsString('Tiny', $chart->filter('.chart-numbers')->text());
    }

    /**
     * The chart must carry the denominator next to the share.
     *
     * A share without the count it came from is not a measurement, and this chart is
     * the one place on the site where the two are most tempting to separate.
     */
    public function testEveryShareIsPrintedWithTheCountItCameFrom(): void
    {
        $client = static::createClient();
        $this->seedCategories();

        $crawler = $client->request('GET', '/stats');

        self::assertStringContainsString(
            '3 of 6',
            $crawler->filter('#chart-category-health .chart-bars')->text(),
            'the chart must show how many of how many',
        );
    }

    /**
     * Two copies of the visibility rule exist, and this is the test the docblock in
     * EcosystemStats promises. ExtensionSearch keeps its copy private, so the honest
     * options were to duplicate it and check, or to widen an API for one caller.
     */
    public function testTheVisibilityGateMatchesTheCatalogueSearch(): void
    {
        $stats = new \ReflectionClass(EcosystemStats::class);
        $search = new \ReflectionClass(ExtensionSearch::class);

        self::assertSame(
            $search->getConstant('VISIBLE_STATUSES'),
            $stats->getConstant('VISIBLE'),
            'the statistics page and the catalogue must agree on what is public',
        );
    }

    /**
     * Two categories either side of the threshold.
     *
     * "Healthy" holds six, three of them current, which is a share of fifty against a
     * ceiling of a hundred and a bar of exactly half the track. "Tiny" holds one, so
     * it is above every other row on share and below the minimum to be drawn at all.
     */
    private function seedCategories(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $vendor = new Vendor('cats', 'cats');
        $em->persist($vendor);

        $big = new Category('healthy', 'Healthy');
        $small = new Category('tiny', 'Tiny');
        $em->persist($big);
        $em->persist($small);

        foreach (range(1, 6) as $n) {
            $extension = new Extension($vendor, 'cats/big-'.$n, 'cats-big-'.$n, 'Big '.$n);
            $extension->setIndexStatus(IndexStatus::Listed);
            $extension->forceLicense('MIT', LicenseStatus::Permissive, FindingSource::ComposerJson);
            $extension->setMaintenanceStatus($n <= 3 ? MaintenanceStatus::Current : MaintenanceStatus::Dormant);
            $extension->addCategory($big);
            $em->persist($extension);
        }

        $only = new Extension($vendor, 'cats/tiny', 'cats-tiny', 'Tiny one');
        $only->setIndexStatus(IndexStatus::Listed);
        $only->forceLicense('MIT', LicenseStatus::Permissive, FindingSource::ComposerJson);
        $only->setMaintenanceStatus(MaintenanceStatus::Current);
        $only->addCategory($small);
        $em->persist($only);

        $em->flush();
    }

    private function seed(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $vendor = new Vendor('acme', 'acme');
        $em->persist($vendor);

        $open = new Extension($vendor, 'acme/open', 'acme-open', 'Acme Open');
        $open->setIndexStatus(IndexStatus::Listed);
        $open->forceLicense('MIT', LicenseStatus::Permissive, FindingSource::ComposerJson);
        $em->persist($open);

        $closed = new Extension($vendor, 'acme/closed', 'acme-closed', 'Acme Closed');
        $closed->setIndexStatus(IndexStatus::IndexOnly);
        $closed->forceLicense(null, LicenseStatus::Rejected, FindingSource::ComposerJson);
        $em->persist($closed);

        // One release apiece, dated in a finished year, so the partial-year assertion
        // is testing the current year rather than the only year with data in it.
        foreach ([[$open, '2021-06-01'], [$closed, '2022-09-01']] as [$extension, $date]) {
            $release = new ExtensionRelease($extension, '1.0.0.0', 'v1.0.0');
            $release->setStable(true);
            $release->setReleasedAt(new \DateTimeImmutable($date));
            $release->applyConstraint('~6.5.0', ConstraintSource::Core, ConstraintTier::Explicit);
            $em->persist($release);
        }

        $em->flush();
    }
}
