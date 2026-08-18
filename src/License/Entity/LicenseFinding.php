<?php

declare(strict_types=1);

namespace App\License\Entity;

use App\Catalog\Entity\Extension;
use App\License\Enum\FindingSource;
use App\License\Enum\LicenseStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single piece of evidence about an extension's license.
 *
 * Kept as an append-only evidence trail rather than a string overwritten on the
 * extension. If a maintainer ever disputes a badge — or worse, if we are asked to
 * justify why we redistributed something — the answer needs to be "this detector,
 * at this version, on this commit, produced this identifier at this confidence",
 * not "the database currently says MIT". The verifiable-build rule asks for that standard of
 * evidence for builds; licensing deserves the same.
 */
#[ORM\Entity]
#[ORM\Table(name: 'license_finding')]
#[ORM\Index(name: 'idx_finding_extension', columns: ['extension_id', 'detected_at'])]
class LicenseFinding
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Extension::class, inversedBy: 'licenseFindings')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Extension $extension;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $spdx = null;

    #[ORM\Column(length: 16, enumType: LicenseStatus::class)]
    private LicenseStatus $status;

    #[ORM\Column(length: 32, enumType: FindingSource::class)]
    private FindingSource $source;

    /** Detector confidence in [0,1]. Null for declarations, which have no score. */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $confidence = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $detectorName = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $detectorVersion = null;

    /** The commit the detector actually looked at. Meaningless evidence without it. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $commitSha = null;

    /** Whatever was read, verbatim — an unnormalised `license` value, a file path. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rawValue = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $detectedAt;

    public function __construct(
        Extension $extension,
        LicenseStatus $status,
        FindingSource $source,
        ?string $spdx = null,
    ) {
        $this->extension = $extension;
        $this->status = $status;
        $this->source = $source;
        $this->spdx = $spdx;
        $this->detectedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getExtension(): Extension
    {
        return $this->extension;
    }

    public function getSpdx(): ?string
    {
        return $this->spdx;
    }

    public function getStatus(): LicenseStatus
    {
        return $this->status;
    }

    public function getSource(): FindingSource
    {
        return $this->source;
    }

    public function getConfidence(): ?float
    {
        return $this->confidence;
    }

    public function getDetectorName(): ?string
    {
        return $this->detectorName;
    }

    public function getDetectorVersion(): ?string
    {
        return $this->detectorVersion;
    }

    public function withDetector(string $name, string $version, ?float $confidence, ?string $commitSha): self
    {
        $this->detectorName = $name;
        $this->detectorVersion = $version;
        $this->confidence = $confidence;
        $this->commitSha = $commitSha;

        return $this;
    }

    public function getCommitSha(): ?string
    {
        return $this->commitSha;
    }

    public function getRawValue(): ?string
    {
        return $this->rawValue;
    }

    public function setRawValue(?string $rawValue): self
    {
        $this->rawValue = $rawValue;

        return $this;
    }

    public function getDetectedAt(): \DateTimeImmutable
    {
        return $this->detectedAt;
    }
}
