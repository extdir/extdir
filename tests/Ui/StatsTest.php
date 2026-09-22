<?php

declare(strict_types=1);

namespace App\Tests\Ui;

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
use App\Stats\EcosystemStats;
use Doctrine\ORM\EntityManagerInterface;
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
