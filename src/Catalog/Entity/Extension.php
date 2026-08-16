<?php

declare(strict_types=1);

namespace App\Catalog\Entity;

use App\Catalog\Enum\IndexStatus;
use App\Catalog\Enum\SourceHost;
use App\Catalog\Repository\ExtensionRepository;
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
 * rewritten by the signals pass after each crawl and are never authoritative — the
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
// doctrine:schema:validate stays green — otherwise every future diff would try to
// drop an index it does not know about. MariaDB's innodb_ft_min_token_size of 3
// applies and is not changeable on shared hosting: two-letter terms will not match.
#[ORM\Index(name: 'ft_extension_search', columns: ['package_name', 'label', 'description'], flags: ['fulltext'])]
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
     * Falls back to the package name — never to a README heading, which would mean
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

    #[ORM\Column(length: 16, enumType: IndexStatus::class)]
    private IndexStatus $indexStatus = IndexStatus::Pending;

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
     * must hold — a delisted extension stays unbuilt even if perfectly licensed.
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

    public function applyLicense(?string $spdx, LicenseStatus $status): void
    {
        $this->licenseSpdx = $spdx;
        $this->licenseStatus = $status;
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
