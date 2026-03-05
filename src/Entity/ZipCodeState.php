<?php

namespace App\Entity;

use App\Repository\ZipCodeStateRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ZipCodeStateRepository::class)]
#[ORM\Table(name: 'zip_code_state')]
#[ORM\UniqueConstraint(name: 'uq_zip_state', columns: ['zip_code', 'state_abbr'])]
#[ORM\Index(name: 'idx_zip_code', columns: ['zip_code'])]
class ZipCodeState
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * 5-digit ZIP code
     */
    #[ORM\Column(length: 5)]
    private string $zipCode;

    /**
     * 2-letter state abbreviation (e.g. "TX")
     */
    #[ORM\Column(length: 2)]
    private string $stateAbbr;

    /**
     * 2-digit state FIPS code (e.g. "48")
     */
    #[ORM\Column(length: 2)]
    private string $stateFips;

    /**
     * Residential address ratio — share of residential addresses in this zip that fall in this state.
     * A zip with res_ratio < 1.0 for any single state spans multiple states.
     */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 9)]
    private string $resRatio;

    /**
     * True when this ZIP code is associated with more than one state in our dataset.
     */
    #[ORM\Column]
    private bool $isMultiState = false;

    #[ORM\Column]
    private \DateTimeImmutable $importedAt;

    #[ORM\Column(length: 6)]
    private string $dataQuarter; // e.g. "2024Q4"

    public function __construct(
        string $zipCode,
        string $stateAbbr,
        string $stateFips,
        string $resRatio,
        string $dataQuarter,
    ) {
        $this->zipCode     = $zipCode;
        $this->stateAbbr   = $stateAbbr;
        $this->stateFips   = $stateFips;
        $this->resRatio    = $resRatio;
        $this->dataQuarter = $dataQuarter;
        $this->importedAt  = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getZipCode(): string { return $this->zipCode; }
    public function getStateAbbr(): string { return $this->stateAbbr; }
    public function getStateFips(): string { return $this->stateFips; }
    public function getResRatio(): string { return $this->resRatio; }
    public function isMultiState(): bool { return $this->isMultiState; }
    public function getDataQuarter(): string { return $this->dataQuarter; }
    public function getImportedAt(): \DateTimeImmutable { return $this->importedAt; }

    public function setIsMultiState(bool $isMultiState): static
    {
        $this->isMultiState = $isMultiState;
        return $this;
    }
}
