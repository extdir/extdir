<?php

declare(strict_types=1);

namespace App\Catalog\Entity;

use App\Catalog\Enum\DiscoverySource;
use App\Catalog\Enum\IndexStatus;
use App\Catalog\Enum\SourceHost;
use App\Catalog\Repository\ExtensionRepository;
use App\License\Entity\LicenseFinding;
use App\License\Enum\FindingSource;
use App\License\Enum\LicenseStatus;
use App\Signals\Enum\MaintenanceStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One indexed Shopware extension, keyed by its Composer package name.
 *
 * Several fields here are denormalised copies of things derivable from the release
 * and snapshot tables (maintenance status, rank score, latest release). That is
 * intentional: the primary UI is a faceted list filtered by Shopware version,
 * category, license and maintenance simultaneously, and recomputing those per
 * request would turn the one page that matters into the slow one. They are
 * rewritten by the signals pass after each crawl and are never authoritative, the
 * evidence tables are.
 */
#[ORM\Entity(repositoryClass: ExtensionRepository::class)]
#[ORM\Table(name: 'extension')]
#[ORM\UniqueConstraint(name: 'uniq_extension_package', columns: ['package_name'])]
#[ORM\UniqueConstraint(name: 'uniq_extension_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_extension_facets', columns: ['index_status', 'license_status', 'maintenance_status'])]
#[ORM\Index(name: 'idx_extension_rank', columns: ['rank_score'])]
#[ORM\Index(name: 'idx_extension_crawl', columns: ['last_crawled_at'])]
// Declared through the ORM rather than raw migration SQL so that
// doctrine:schema:validate stays green, otherwise every future diff would try to
// drop an index it does not know about. MariaDB's innodb_ft_min_token_size of 3
// applies and is not changeable on shared hosting: two-letter terms will not match.
//
// Indexes search_text rather than label/description directly: those hold only the
// English-preferred strings, which hid every German-only plugin from search.
#[ORM\Index(name: 'ft_extension_search', columns: ['search_text'], flags: ['fulltext'])]
class Extension
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Vendor::class, inversedBy: 'extensions')]
    #[ORM\JoinColumn(nullable: false)]
    private Vendor $vendor;

    /** Full Composer name, e.g. `frosh/tools`. */
    #[ORM\Column(length: 191)]
    private string $packageName;

    #[ORM\Column(length: 191)]
    private string $slug;

    /**
     * Human-readable name from `extra.label`, resolved to English where available.
     * Falls back to the package name, never to a README heading, which would mean
     * scraping content we have no license to reuse.
     */
    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * All translations of `extra.label`, keyed by locale (`en-GB`, `de-DE`, ...).
     * Shopware plugins commonly ship German-only metadata; keeping every locale
     * means a German merchant sees the maintainer's own words rather than a blank.
     *
     * @var array<string, string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $labels = [];

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $descriptions = [];

    /**
     * composer.json `keywords`, kept so categories can be recomputed offline.
     *
     * The taxonomy rules will change as gaps show up in the corpus. Storing the
     * inputs means re-categorising 422 extensions is a local pass over the database
     * rather than another full crawl of Packagist.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $keywords = [];

    /**
     * Every locale's label and description, the package name and the keywords,
     * flattened into the one column the FULLTEXT index covers.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $searchText = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $repositoryUrl = null;

    #[ORM\Column(length: 16, enumType: SourceHost::class)]
    private SourceHost $sourceHost = SourceHost::Other;

    /**
     * From `extra.shopware-plugin-class`, e.g. `Frosh\Tools\FroshTools`.
     *
     * The basename is the technical name required by `bin/console plugin:install
     * --activate <Name>`. Without it the install snippet on the detail page would
     * have to print a placeholder, which is precisely the kind of almost-useful
     * documentation this directory exists to replace.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $pluginClass = null;

    /** From `extra.plugin-icon`, else the conventional src/Resources/config/plugin.png. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $iconPath = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $manufacturerLink = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $supportLink = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $licenseSpdx = null;

    #[ORM\Column(length: 16, enumType: LicenseStatus::class)]
    private LicenseStatus $licenseStatus = LicenseStatus::Unknown;

    /**
     * Which kind of evidence the effective licence rests on.
     *
     * A detector reading the repository's LICENSE file outranks a composer.json
     * declaration, so the page can say where the answer came from rather than
     * asking the reader to take it on trust.
     */
    #[ORM\Column(length: 32, enumType: FindingSource::class, options: ['default' => 'composer_json'])]
    private FindingSource $licenseEvidence = FindingSource::ComposerJson;

    #[ORM\Column(length: 16, enumType: IndexStatus::class)]
    private IndexStatus $indexStatus = IndexStatus::Pending;

    /**
     * Which crawler found this. Decides whether it is published in our Composer
     * repository, see DiscoverySource.
     */
    #[ORM\Column(length: 16, enumType: DiscoverySource::class, options: ['default' => 'packagist'])]
    private DiscoverySource $discoverySource = DiscoverySource::Packagist;

    #[ORM\Column(length: 16, enumType: MaintenanceStatus::class)]
    private MaintenanceStatus $maintenanceStatus = MaintenanceStatus::Unknown;

    /** Packagist's own abandonment marker, and the replacement package if given. */
    #[ORM\Column(options: ['default' => false])]
    private bool $abandoned = false;

    #[ORM\Column(length: 191, nullable: true)]
    private ?string $replacementPackage = null;

    // No DB-level default: MariaDB reports a float default as '0' while Doctrine
    // expects 0, which leaves schema:validate permanently dirty. The PHP default
    // covers it, and every insert goes through the ORM anyway.
    #[ORM\Column(type: Types::FLOAT)]
    private float $rankScore = 0.0;

    /**
     * Latest star and fork counts, denormalised from RepositorySnapshot.
     *
     * Shown on the card and offered as a sort option, but never fed into the
     * ranking score, the ranking guidance is explicit that popularity misleads in this ecosystem.
     * Kept here only so listing and sorting do not need a join per row.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $stars = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $forks = 0;

    /**
     * Packagist install counts: all time, and the trailing thirty days.
     *
     * Shown on the boards and nowhere in the score, for exactly the reason stars
     * are not scored. The two are kept separately because a single total is a
     * misleading number on its own: it only accumulates, so an extension published
     * in 2019 outranks a better one published last year on age alone. The monthly
     * figure is the one that answers "is anyone installing this now".
     *
     * Zero is ambiguous by itself and must never be read as unpopular, 170 of the
     * indexed extensions are not on Packagist at all and cannot be measured this
     * way. packagistCheckedAt is what distinguishes the two: null means we have
     * never had a number, not that the number is nought.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $downloadsTotal = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $downloadsMonthly = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $packagistCheckedAt = null;

    /**
     * The repository's default branch, denormalised from RepositorySnapshot.
     *
     * Only used to build raw-file URLs for icons. Guessing `main` and falling back to
     * `master` was measured against the corpus and found the icon for 23 of 40
     * sampled repositories; the stored branch is right for all of them, and the
     * catalogue contains `develop`, `trunk`, `stable` and `main_65` besides. Kept
     * here rather than joined per row for the same reason stars are.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $defaultBranch = null;

    /**
     * When the icon was last confirmed to exist at its declared path.
     *
     * Null means unchecked or absent, and no URL is offered to the browser. Most
     * icon paths are a convention filled in when composer.json declares none, and
     * about a third of those point at nothing, offering them unverified would spend
     * a reader's consent on a few hundred requests that can only 404.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $iconVerifiedAt = null;

    /**
     * Screenshots, as URLs on the extension's own forge.
     *
     * Never fetched, resized or stored here, §4.3 prefers linking, and an extension's
     * screenshots are the vendor's material rather than ours. The list is handed to
     * the browser only after the reader allows remote media, exactly like the icon.
     *
     * Restricted at collection time to hosts the forge itself serves. Sampled READMEs
     * link to imgur, giphy, cloudinary and vendors' own marketing servers; loading
     * those would quietly widen consent given for "icons from their forges".
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $galleryImages = [];

    /** Reason recorded when index status becomes Delisted. Required by the takedown policy. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $delistReason = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastCrawledAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastCommitAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastReleaseAt = null;

    /** @var Collection<int, ExtensionRelease> */
    #[ORM\OneToMany(targetEntity: ExtensionRelease::class, mappedBy: 'extension', cascade: ['persist', 'remove'])]
    private Collection $releases;

    /** @var Collection<int, LicenseFinding> */
    #[ORM\OneToMany(targetEntity: LicenseFinding::class, mappedBy: 'extension')]
    private Collection $licenseFindings;

    /** @var Collection<int, Category> */
    #[ORM\ManyToMany(targetEntity: Category::class)]
    #[ORM\JoinTable(name: 'extension_category')]
    private Collection $categories;

    public function __construct(Vendor $vendor, string $packageName, string $slug, string $label)
    {
        $this->vendor = $vendor;
        $this->packageName = $packageName;
        $this->slug = $slug;
        $this->label = $label;
        $this->firstSeenAt = new \DateTimeImmutable();
        $this->releases = new ArrayCollection();
        $this->categories = new ArrayCollection();
        $this->licenseFindings = new ArrayCollection();
    }

    /**
     * The technical plugin name for install commands, derived from the FQCN.
     */
    public function getTechnicalName(): ?string
    {
        if (null === $this->pluginClass) {
            return null;
        }

        $parts = explode('\\', $this->pluginClass);

        return end($parts) ?: null;
    }

    /**
     * Whether we may host or mirror an artifact for this extension. Both conditions
     * must hold, a delisted extension stays unbuilt even if perfectly licensed.
     */
    public function isRedistributable(): bool
    {
        return $this->licenseStatus->isRedistributable()
            && IndexStatus::Delisted !== $this->indexStatus;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVendor(): Vendor
    {
        return $this->vendor;
    }

    public function getPackageName(): string
    {
        return $this->packageName;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    /** @return array<string, string> */
    public function getLabels(): array
    {
        return $this->labels;
    }

    /** @param array<string, string> $labels */
    public function setLabels(array $labels): void
    {
        $this->labels = $labels;
    }

    /** @return array<string, string> */
    public function getDescriptions(): array
    {
        return $this->descriptions;
    }

    /** @param array<string, string> $descriptions */
    public function setDescriptions(array $descriptions): void
    {
        $this->descriptions = $descriptions;
    }

    public function getSearchText(): ?string
    {
        return $this->searchText;
    }

    public function setSearchText(?string $searchText): void
    {
        $this->searchText = $searchText;
    }

    /** @return list<string> */
    public function getKeywords(): array
    {
        return $this->keywords;
    }

    /** @param list<string> $keywords */
    public function setKeywords(array $keywords): void
    {
        $this->keywords = $keywords;
    }

    public function getRepositoryUrl(): ?string
    {
        return $this->repositoryUrl;
    }

    public function setRepositoryUrl(?string $repositoryUrl): void
    {
        $this->repositoryUrl = $repositoryUrl;
        $this->sourceHost = SourceHost::fromRepositoryUrl($repositoryUrl);
    }

    public function getSourceHost(): SourceHost
    {
        return $this->sourceHost;
    }

    public function getPluginClass(): ?string
    {
        return $this->pluginClass;
    }

    public function setPluginClass(?string $pluginClass): void
    {
        $this->pluginClass = $pluginClass;
    }

    public function getIconPath(): ?string
    {
        return $this->iconPath;
    }

    public function setIconPath(?string $iconPath): void
    {
        $this->iconPath = $iconPath;
    }

    public function getDefaultBranch(): ?string
    {
        return $this->defaultBranch;
    }

    public function setDefaultBranch(?string $defaultBranch): void
    {
        $this->defaultBranch = $defaultBranch;
    }

    public function getIconVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->iconVerifiedAt;
    }

    public function setIconVerifiedAt(?\DateTimeImmutable $iconVerifiedAt): void
    {
        $this->iconVerifiedAt = $iconVerifiedAt;
    }

    public function hasVerifiedIcon(): bool
    {
        return null !== $this->iconVerifiedAt;
    }

    /**
     * @return list<string>
     */
    public function getGalleryImages(): array
    {
        return $this->galleryImages;
    }

    /**
     * array_values, not a plain assignment: a JSON column encodes a gapped array as
     * an object rather than a list, and the template iterates it expecting a list.
     *
     * @param array<string> $galleryImages
     */
    public function setGalleryImages(array $galleryImages): void
    {
        $this->galleryImages = array_values($galleryImages);
    }

    public function getManufacturerLink(): ?string
    {
        return $this->manufacturerLink;
    }

    public function setManufacturerLink(?string $manufacturerLink): void
    {
        $this->manufacturerLink = $manufacturerLink;
    }

    public function getSupportLink(): ?string
    {
        return $this->supportLink;
    }

    public function setSupportLink(?string $supportLink): void
    {
        $this->supportLink = $supportLink;
    }

    public function getLicenseSpdx(): ?string
    {
        return $this->licenseSpdx;
    }

    public function getLicenseStatus(): LicenseStatus
    {
        return $this->licenseStatus;
    }

    public function applyLicense(
        ?string $spdx,
        LicenseStatus $status,
        FindingSource $evidence = FindingSource::ComposerJson,
    ): void {
        // A weaker source must not overwrite a stronger one. Without this, every
        // Packagist re-crawl would reset an extension back to the manifest's
        // "proprietary" after the licence file had already corrected it.
        if (!$evidence->isAuthoritative() && $this->licenseEvidence->isAuthoritative()) {
            return;
        }

        $this->licenseSpdx = $spdx;
        $this->licenseStatus = $status;
        $this->licenseEvidence = $evidence;
    }

    /**
     * Sets the licence unconditionally. Only LicenseResolver::reconcile() may use
     * this: it is the one place that has looked at every finding and can therefore
     * overrule an earlier conclusion in either direction.
     */
    public function forceLicense(?string $spdx, LicenseStatus $status, FindingSource $evidence): void
    {
        $this->licenseSpdx = $spdx;
        $this->licenseStatus = $status;
        $this->licenseEvidence = $evidence;
    }

    public function getLicenseEvidence(): FindingSource
    {
        return $this->licenseEvidence;
    }

    /**
     * The licence string the maintainer actually wrote, before any interpretation.
     *
     * Shown wherever the conclusion is "not open source", because that verdict is
     * otherwise unfalsifiable from the page. A reader who sees a repository
     * advertising a "Free license" and a badge saying the opposite has no way to
     * tell whether the index is wrong or the word "free" means price rather than
     * freedom, and the answer is sitting in a column nobody displays.
     *
     * Naming the raw value turns an accusation into a citation. It also puts the
     * disagreement where the maintainer can see it, which is the only way a wrong
     * classification ever gets reported.
     */
    public function getDeclaredLicenseRaw(): ?string
    {
        foreach ($this->licenseFindings as $finding) {
            $raw = $finding->getRawValue();

            if (FindingSource::ComposerJson === $finding->getSource() && \is_string($raw) && '' !== trim($raw)) {
                return trim($raw);
            }
        }

        return null;
    }

    public function getDiscoverySource(): DiscoverySource
    {
        return $this->discoverySource;
    }

    /**
     * Packagist is authoritative once a package appears there, so a GitHub-only
     * extension that later gets published upgrades rather than staying pinned to
     * how it was first found.
     */
    public function setDiscoverySource(DiscoverySource $source): void
    {
        if (DiscoverySource::Packagist === $source || DiscoverySource::Packagist !== $this->discoverySource) {
            $this->discoverySource = $source;
        }
    }

    /**
     * Sets the source unconditionally, including downgrading away from Packagist.
     * Only the Packagist crawl may do this, because only it knows what the list
     * actually contains.
     */
    public function forceDiscoverySource(DiscoverySource $source): void
    {
        $this->discoverySource = $source;
    }

    public function isOnPackagist(): bool
    {
        return $this->discoverySource->isOnPackagist();
    }

    public function getIndexStatus(): IndexStatus
    {
        return $this->indexStatus;
    }

    public function setIndexStatus(IndexStatus $indexStatus): void
    {
        $this->indexStatus = $indexStatus;
    }

    public function getMaintenanceStatus(): MaintenanceStatus
    {
        return $this->maintenanceStatus;
    }

    public function setMaintenanceStatus(MaintenanceStatus $maintenanceStatus): void
    {
        $this->maintenanceStatus = $maintenanceStatus;
    }

    public function isAbandoned(): bool
    {
        return $this->abandoned;
    }

    public function setAbandoned(bool $abandoned, ?string $replacementPackage = null): void
    {
        $this->abandoned = $abandoned;
        $this->replacementPackage = $replacementPackage;
    }

    public function getReplacementPackage(): ?string
    {
        return $this->replacementPackage;
    }

    public function getStars(): int
    {
        return $this->stars;
    }

    public function getForks(): int
    {
        return $this->forks;
    }

    public function setPopularity(int $stars, int $forks): void
    {
        $this->stars = $stars;
        $this->forks = $forks;
    }

    public function getDownloadsTotal(): int
    {
        return $this->downloadsTotal;
    }

    public function getDownloadsMonthly(): int
    {
        return $this->downloadsMonthly;
    }

    public function getPackagistCheckedAt(): ?\DateTimeImmutable
    {
        return $this->packagistCheckedAt;
    }

    /**
     * True once Packagist has actually answered for this package.
     *
     * The boards use this rather than a non-zero count, so an extension that is on
     * Packagist and genuinely has few installs is still shown as measured.
     */
    public function hasPackagistStats(): bool
    {
        return null !== $this->packagistCheckedAt;
    }

    public function setPackagistDownloads(int $total, int $monthly, \DateTimeImmutable $checkedAt): void
    {
        $this->downloadsTotal = $total;
        $this->downloadsMonthly = $monthly;
        $this->packagistCheckedAt = $checkedAt;
    }

    public function getRankScore(): float
    {
        return $this->rankScore;
    }

    public function setRankScore(float $rankScore): void
    {
        $this->rankScore = $rankScore;
    }

    public function getDelistReason(): ?string
    {
        return $this->delistReason;
    }

    public function delist(string $reason): void
    {
        $this->indexStatus = IndexStatus::Delisted;
        $this->delistReason = $reason;
    }

    /**
     * Undoes a delisting.
     *
     * Delisting existed without this for longer than it should have, which meant a
     * removal made in error, a complaint that turned out to be unfounded, a
     * maintainer who changed their mind, a wrong slug clicked in a hurry, had no
     * remedy short of editing the database by hand. An irreversible destructive
     * action is a worse problem than the mistake it was meant to prevent.
     *
     * The delist reason is cleared rather than kept: it described a state the
     * extension is no longer in, and leaving it behind would show a stale
     * explanation on a listed entry. The history is not lost, because every
     * delisting and every relisting is written to the moderation log with its own
     * reason and actor.
     */
    public function relist(): void
    {
        $this->indexStatus = IndexStatus::Listed;
        $this->delistReason = null;
    }

    public function getFirstSeenAt(): \DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function getLastCrawledAt(): ?\DateTimeImmutable
    {
        return $this->lastCrawledAt;
    }

    public function markCrawled(?\DateTimeImmutable $at = null): void
    {
        $this->lastCrawledAt = $at ?? new \DateTimeImmutable();
    }

    public function getLastCommitAt(): ?\DateTimeImmutable
    {
        return $this->lastCommitAt;
    }

    public function setLastCommitAt(?\DateTimeImmutable $lastCommitAt): void
    {
        $this->lastCommitAt = $lastCommitAt;
    }

    public function getLastReleaseAt(): ?\DateTimeImmutable
    {
        return $this->lastReleaseAt;
    }

    public function setLastReleaseAt(?\DateTimeImmutable $lastReleaseAt): void
    {
        $this->lastReleaseAt = $lastReleaseAt;
    }

    /** @return Collection<int, ExtensionRelease> */
    public function getReleases(): Collection
    {
        return $this->releases;
    }

    public function addRelease(ExtensionRelease $release): void
    {
        if (!$this->releases->contains($release)) {
            $this->releases->add($release);
        }
    }

    /** @return Collection<int, LicenseFinding> */
    public function getLicenseFindings(): Collection
    {
        return $this->licenseFindings;
    }

    /** @return Collection<int, Category> */
    public function getCategories(): Collection
    {
        return $this->categories;
    }

    public function addCategory(Category $category): void
    {
        if (!$this->categories->contains($category)) {
            $this->categories->add($category);
        }
    }

    public function clearCategories(): void
    {
        $this->categories->clear();
    }
}
