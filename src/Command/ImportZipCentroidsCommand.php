<?php

namespace App\Command;

use App\Entity\ZipCodeGazetteer;
use App\Repository\ZipCodeGazetteerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Imports ZIP centroid data from the Census ZCTA Gazetteer file.
 *
 * Does two things in one pass:
 *   1. Populates zip_code_gazetteer (fallback for ZIPs missing from HUD)
 *   2. Backfills latitude/longitude on existing zip_code_state rows
 *
 * Download:
 *   https://www2.census.gov/geo/docs/maps-data/data/gazetteer/2023_Gazetteer/2023_Gaz_zcta_national.zip
 *
 * Run:
 *   php bin/console app:hud:import-centroids /path/to/2023_Gaz_zcta_national.txt
 */
#[AsCommand(
    name: 'app:hud:import-centroids',
    description: 'Imports ZIP centroids from Census Gazetteer into zip_code_gazetteer and backfills zip_code_state.',
)]
class ImportZipCentroidsCommand extends Command
{
    public function __construct(
        private readonly ZipCodeGazetteerRepository $gazetteerRepository,
        private readonly EntityManagerInterface     $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'file',
                InputArgument::REQUIRED,
                'Path to 2023_Gaz_zcta_national.txt',
            )
            ->addOption('truncate', null, InputOption::VALUE_NONE, 'Truncate zip_code_gazetteer before importing')
            ->addOption('batch-size', 'b', InputOption::VALUE_OPTIONAL, 'Flush batch size', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '-1');

        $io        = new SymfonyStyle($input, $output);
        $filePath  = $input->getArgument('file');
        $batchSize = (int) $input->getOption('batch-size');

        if (!file_exists($filePath)) {
            $io->error([
                "File not found: $filePath",
                'Download from: https://www2.census.gov/geo/docs/maps-data/data/gazetteer/2023_Gazetteer/2023_Gaz_zcta_national.zip',
            ]);
            return Command::FAILURE;
        }

        $io->title('Census ZCTA Gazetteer — Centroid Import');

        if ($input->getOption('truncate')) {
            $io->warning('Truncating zip_code_gazetteer...');
            $this->gazetteerRepository->truncate();
        }

        // --- Parse ---
        $handle    = fopen($filePath, 'r');
        $rawHeader = ltrim(fgets($handle), "\xEF\xBB\xBF");
        $headers   = array_map('trim', explode("\t", trim($rawHeader)));

        foreach (['GEOID', 'ALAND', 'INTPTLAT', 'INTPTLONG'] as $col) {
            if (!in_array($col, $headers, true)) {
                $io->error("Expected column '$col' not found. Headers: " . implode(', ', $headers));
                fclose($handle);
                return Command::FAILURE;
            }
        }

        $idxZip  = array_search('GEOID',     $headers, true);
        $idxArea = array_search('ALAND',     $headers, true);
        $idxLat  = array_search('INTPTLAT',  $headers, true);
        $idxLng  = array_search('INTPTLONG', $headers, true);

        $centroids = [];
        while (($line = fgets($handle)) !== false) {
            $cols = array_map('trim', explode("\t", trim($line)));
            if (count($cols) <= max($idxZip, $idxArea, $idxLat, $idxLng)) continue;

            $zip = str_pad($cols[$idxZip], 5, '0', STR_PAD_LEFT);
            $lat = (float) $cols[$idxLat];
            $lng = (float) $cols[$idxLng];

            if ($lat == 0.0 && $lng == 0.0) continue;

            $centroids[$zip] = [$lat, $lng, (int) $cols[$idxArea]];
        }
        fclose($handle);

        $io->text(sprintf('Parsed %d ZIP centroids.', count($centroids)));

        // --- Step 1: Populate zip_code_gazetteer ---
        $io->section('Step 1/2 — Populating zip_code_gazetteer...');
        $progress = new ProgressBar($output, count($centroids));
        $progress->start();

        $gazInserted = 0;
        $gazSkipped  = 0;
        $i           = 0;

        foreach ($centroids as $zip => [$lat, $lng, $area]) {
            if ($this->gazetteerRepository->findByZip($zip) !== null) {
                $gazSkipped++;
            } else {
                $this->em->persist(new ZipCodeGazetteer($zip, $lat, $lng, $area));
                $gazInserted++;
            }

            if (++$i % $batchSize === 0) {
                $this->em->flush();
                $this->em->clear();
            }
            $progress->advance();
        }

        $this->em->flush();
        $this->em->clear();
        $progress->finish();
        $output->writeln('');
        $io->text(sprintf('zip_code_gazetteer — Inserted: %d | Skipped: %d', $gazInserted, $gazSkipped));

        // --- Step 2: Backfill lat/lng on zip_code_state ---
        $io->section('Step 2/2 — Backfilling centroids on zip_code_state...');
        $conn    = $this->em->getConnection();
        $updated = 0;
        $missing = 0;

        $progress2 = new ProgressBar($output, count($centroids));
        $progress2->start();

        foreach (array_chunk($centroids, $batchSize, true) as $chunk) {
            foreach ($chunk as $zip => [$lat, $lng]) {
                $rows = $conn->executeStatement(
                    'UPDATE zip_code_state SET latitude = ?, longitude = ? WHERE zip_code = ?',
                    [$lat, $lng, $zip],
                );
                $rows > 0 ? $updated += $rows : $missing++;
            }
            $progress2->advance(count($chunk));
        }

        $progress2->finish();
        $output->writeln('');

        $io->success(sprintf(
            'Done. Gazetteer inserted: %d | zip_code_state rows updated: %d | HUD gaps (Gazetteer only): %d',
            $gazInserted,
            $updated,
            $missing,
        ));

        return Command::SUCCESS;
    }
}
