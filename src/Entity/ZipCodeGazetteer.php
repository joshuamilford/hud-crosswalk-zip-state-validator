<?php

namespace App\Entity;

use App\Repository\ZipCodeGazetteerRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Stores ZIP centroid data from the Census ZCTA Gazetteer file.
 * Used as a fallback when a ZIP is not found in the HUD dataset.
 *
 * Source: https://www2.census.gov/geo/docs/maps-data/data/gazetteer/2023_Gazetteer/2023_Gaz_zcta_national.zip
 */
#[ORM\Entity(repositoryClass: ZipCodeGazetteerRepository::class)]
#[ORM\Table(name: 'zip_code_gazetteer')]
#[ORM\Index(name: 'idx_gaz_zip_code', columns: ['zip_code'])]
class ZipCodeGazetteer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 5, unique: true)]
    private string $zipCode;

    #[ORM\Column(type: 'float')]
    private float $latitude;

    #[ORM\Column(type: 'float')]
    private float $longitude;

    /** Land area in square metres — useful for filtering PO Box / phantom ZIPs */
    #[ORM\Column(type: 'bigint')]
    private int $areaLandSqm;

    #[ORM\Column]
    private \DateTimeImmutable $importedAt;

    public function __construct(
        string $zipCode,
        float  $latitude,
        float  $longitude,
        int    $areaLandSqm,
    ) {
        $this->zipCode     = $zipCode;
        $this->latitude    = $latitude;
        $this->longitude   = $longitude;
        $this->areaLandSqm = $areaLandSqm;
        $this->importedAt  = new \DateTimeImmutable();
    }

    public function getId(): ?int                       { return $this->id; }
    public function getZipCode(): string                { return $this->zipCode; }
    public function getLatitude(): float                { return $this->latitude; }
    public function getLongitude(): float               { return $this->longitude; }
    public function getAreaLandSqm(): int               { return $this->areaLandSqm; }
    public function getImportedAt(): \DateTimeImmutable { return $this->importedAt; }
}
