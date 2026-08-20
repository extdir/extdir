<?php

declare(strict_types=1);

namespace App\Catalog\Entity;

use App\Catalog\Repository\ExtensionReleaseRepository;
use App\Compatibility\Enum\ConstraintSource;
use App\Compatibility\Enum\ConstraintTier;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One published version of an extension.
 *
 * The full composer.json is kept as a snapshot rather than only its parsed fields.
 * Parsing rules will change — new `extra` keys appear, the constraint tiering gets
 * refined — and re-fetching every version of every package to backfill would cost
 * an entire crawl budget. Packagist's p2 endpoint hands us every version's complete
 * require block in one request, so storing it is nearly free and lets the whole
 * corpus be reprocessed offline.
 */
#[ORM\Entity(repositoryClass: ExtensionReleaseRepository::class)]
#[ORM\Table(name: 'extension_release')]
#[ORM\UniqueConstraint(name: 'uniq_release_version', columns: ['extension_id', 'version'])]
#[ORM\Index(name: 'idx_release_released_at', columns: ['released_at'])]
class ExtensionRelease
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Extension::class, inversedBy: 'releases')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Extension $extension;

    /** Normalised version, e.g. `2.1.0.0`. */
    #[ORM\Column(length: 64)]
    private string $version;

    /** The tag as published, e.g. `v2.1.0`. Needed to build zipball URLs. */
    #[ORM\Column(length: 64)]
    private string $versionRaw;

    /**
     * Whether this is a stable release. Pre-release and branch aliases are kept
     * (they are sometimes the only thing supporting a new Shopware major) but are
     * excluded from the headline matrix so a dev-branch does not imply support.
     */
    #[ORM\Column(options: ['default' => true])]
    private bool $stable = true;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $composerJson = [];

    /** The winning constraint string exactly as the maintainer wrote it, e.g. `^6.5`. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $shopwareConstraint = null;

    #[ORM\Column(length: 32, enumType: ConstraintSource::class)]
    private ConstraintSource $constraintSource = ConstraintSource::None;

    #[ORM\Column(length: 16, enumType: ConstraintTier::class)]
    private ConstraintTier $constraintTier = ConstraintTier::Absent;

    /**
     * Where Composer should fetch this version from. Populated by the resolver
     * chain, which prefers a maintainer-attached ZIP, then GitHub's zipball, and
     * only builds as a last resort — hosting is the exception, not the default.
     */
    #[ORM\Column(length: 512, nullable: true)]
    private ?string $distUrl = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $distSource = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $sourceReference = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $releasedAt = null;

    public function __construct(Extension $extension, string $version, string $versionRaw)
    {
        $this->extension = $extension;
        $this->version = $version;
        $this->versionRaw = $versionRaw;
        $extension->addRelease($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getExtension(): Extension
    {
        return $this->extension;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getVersionRaw(): string
    {
        return $this->versionRaw;
    }

    /**
     * The tag as the maintainer wrote it, which can be corrected on a later crawl.
     *
     * Not immutable, because it has been wrong: a manifest carrying a stale hardcoded
     * `version` used to override the tag it was read from, and existing rows kept that
     * value even after the reading was fixed — the row said 1.0.0 while its normalised
     * version said 1.0.1. Re-crawling has to be able to correct what it once got wrong.
     */
    public function setVersionRaw(string $versionRaw): void
    {
        $this->versionRaw = $versionRaw;
    }

    public function isStable(): bool
    {
        return $this->stable;
    }

    public function setStable(bool $stable): void
    {
        $this->stable = $stable;
    }

    /** @return array<string, mixed> */
    public function getComposerJson(): array
    {
        return $this->composerJson;
    }

    /** @param array<string, mixed> $composerJson */
    public function setComposerJson(array $composerJson): void
    {
        $this->composerJson = $composerJson;
    }

    public function getShopwareConstraint(): ?string
    {
        return $this->shopwareConstraint;
    }

    public function getConstraintSource(): ConstraintSource
    {
        return $this->constraintSource;
    }

    public function getConstraintTier(): ConstraintTier
    {
        return $this->constraintTier;
    }

    public function applyConstraint(?string $constraint, ConstraintSource $source, ConstraintTier $tier): void
    {
        $this->shopwareConstraint = $constraint;
        $this->constraintSource = $source;
        $this->constraintTier = $tier;
    }

    public function getDistUrl(): ?string
    {
        return $this->distUrl;
    }

    public function setDist(?string $url, ?string $source): void
    {
        $this->distUrl = $url;
        $this->distSource = $source;
    }

    public function getDistSource(): ?string
    {
        return $this->distSource;
    }

    public function getSourceReference(): ?string
    {
        return $this->sourceReference;
    }

    public function setSourceReference(?string $sourceReference): void
    {
        $this->sourceReference = $sourceReference;
    }

    public function getReleasedAt(): ?\DateTimeImmutable
    {
        return $this->releasedAt;
    }

    public function setReleasedAt(?\DateTimeImmutable $releasedAt): void
    {
        $this->releasedAt = $releasedAt;
    }
}
