<?php

declare(strict_types=1);

namespace App\Distribution\Entity;

use App\Catalog\Entity\ExtensionRelease;
use App\Distribution\Repository\ArtifactRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The provenance record for one downloadable archive.
 *
 * The verifiable-build rule requires that every artifact we host publishes its source commit,
 * build log, SHA-256, the shopware-cli version used and an SBOM, so that a third
 * party can rebuild the ZIP and get the same bytes. This row *is* that publication:
 * if a field here is empty, the corresponding claim cannot be made on the site.
 *
 * Rows also exist for archives we merely link to. Those carry no build metadata,
 * and that difference is the point, it makes "we built this" and "the maintainer
 * built this" structurally distinguishable rather than a matter of wording.
 */
#[ORM\Entity(repositoryClass: ArtifactRepository::class)]
#[ORM\Table(name: 'artifact')]
#[ORM\UniqueConstraint(name: 'uniq_artifact_release', columns: ['release_id'])]
class Artifact
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: ExtensionRelease::class)]
    #[ORM\JoinColumn(name: 'release_id', nullable: false, onDelete: 'CASCADE')]
    private ExtensionRelease $release;

    #[ORM\Column(length: 512)]
    private string $downloadUrl;

    #[ORM\Column(length: 32)]
    private string $source;

    /** The commit the archive was produced from. Without it, nothing is reproducible. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $commitSha = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $sha256 = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $sizeBytes = null;

    /** Publicly readable CI log. Auditability is the reason builds run on GitHub Actions at all. */
    #[ORM\Column(length: 512, nullable: true)]
    private ?string $buildLogUrl = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $sbomUrl = null;

    /** Pinned per the shopware-cli notes, an unpinned CLI makes the build unreproducible by definition. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $shopwareCliVersion = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $recordedAt;

    public function __construct(ExtensionRelease $release, string $downloadUrl, string $source)
    {
        $this->release = $release;
        $this->downloadUrl = $downloadUrl;
        $this->source = $source;
        $this->recordedAt = new \DateTimeImmutable();
    }

    /**
     * Whether this artifact carries everything the verifiable-build rule demands.
     *
     * Used to decide what the verification page may claim. An artifact we host
     * without complete provenance is a promise we cannot keep, so the page says so
     * rather than showing blanks.
     */
    public function hasCompleteProvenance(): bool
    {
        return null !== $this->commitSha
            && null !== $this->sha256
            && null !== $this->buildLogUrl
            && null !== $this->shopwareCliVersion;
    }

    public function recordBuild(
        string $commitSha,
        string $sha256,
        string $buildLogUrl,
        string $shopwareCliVersion,
        ?string $sbomUrl,
        ?int $sizeBytes,
    ): void {
        $this->commitSha = $commitSha;
        $this->sha256 = $sha256;
        $this->buildLogUrl = $buildLogUrl;
        $this->shopwareCliVersion = $shopwareCliVersion;
        $this->sbomUrl = $sbomUrl;
        $this->sizeBytes = $sizeBytes;
    }

    public function updateLink(string $downloadUrl, string $source): void
    {
        $this->downloadUrl = $downloadUrl;
        $this->source = $source;
        $this->recordedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRelease(): ExtensionRelease
    {
        return $this->release;
    }

    public function getDownloadUrl(): string
    {
        return $this->downloadUrl;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getCommitSha(): ?string
    {
        return $this->commitSha;
    }

    public function getSha256(): ?string
    {
        return $this->sha256;
    }

    public function getSizeBytes(): ?int
    {
        return $this->sizeBytes;
    }

    public function getBuildLogUrl(): ?string
    {
        return $this->buildLogUrl;
    }

    public function getSbomUrl(): ?string
    {
        return $this->sbomUrl;
    }

    public function getShopwareCliVersion(): ?string
    {
        return $this->shopwareCliVersion;
    }

    public function getRecordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }
}
