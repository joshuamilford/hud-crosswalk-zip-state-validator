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

    #[ORM\Column(length: 5)]
    private string $zipCode;

    #[ORM\Column(length: 2)]
    private string $stateAbbr;

    #[ORM\Column(length: 2)]
    private string $stateFips;

    /**
     * Share of residential addresses in this ZIP that fall in this state.
     */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 9)]
    private string $resRatio;

    /**
     * True when this ZIP is associated with more than one state.
     */
    #[ORM\Column]
    private bool $isMultiState = false;

    #[ORM\Column]
    private \DateTimeImmutable $importedAt;

    #[ORM\Column(length: 10)]
    private string $dataQuarter;

    /**
     * Centroid latitude from Census ZCTA Gazetteer.
     */
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $latitude = null;

    /**
     * Centroid longitude from Census ZCTA Gazetteer.
     */
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $longitude = null;

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

    public function getId(): ?int                       { return $this->id; }
    public function getZipCode(): string                { return $this->zipCode; }
    public function getStateAbbr(): string              { return $this->stateAbbr; }
    public function getStateFips(): string              { return $this->stateFips; }
    public function getResRatio(): string               { return $this->resRatio; }
    public function isMultiState(): bool                { return $this->isMultiState; }
    public function getDataQuarter(): string            { return $this->dataQuarter; }
    public function getImportedAt(): \DateTimeImmutable { return $this->importedAt; }
    public function getLatitude(): ?float               { return $this->latitude; }
    public function getLongitude(): ?float              { return $this->longitude; }

    public function setIsMultiState(bool $v): static   { $this->isMultiState = $v; return $this; }
    public function setLatitude(?float $v): static     { $this->latitude = $v; return $this; }
    public function setLongitude(?float $v): static    { $this->longitude = $v; return $this; }
}
