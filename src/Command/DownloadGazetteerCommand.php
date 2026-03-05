<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:hud:download-gazetteer',
    description: 'Downloads, unzips, and imports the Census ZCTA Gazetteer file.',
)]
class DownloadGazetteerCommand extends Command
{
    private const GAZETTEER_URL = 'https://www2.census.gov/geo/docs/maps-data/data/gazetteer/2023_Gazetteer/2023_Gaz_zcta_national.zip';
    private const ZIP_FILENAME  = '2023_Gaz_zcta_national.zip';
    private const TXT_FILENAME  = '2023_Gaz_zcta_national.txt';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'working-dir',
                'w',
                InputOption::VALUE_OPTIONAL,
                'Directory to download and extract the file into',
                '%kernel.project_dir%/var/gazetteer',
            )
            ->addOption(
                'keep-files',
                null,
                InputOption::VALUE_NONE,
                'Keep the downloaded .zip and .txt files after import (default: delete them)',
            )
            ->addOption(
                'truncate',
                null,
                InputOption::VALUE_NONE,
                'Truncate zip_code_gazetteer before importing',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Census ZCTA Gazetteer — Download & Import');

        // Resolve working directory
        $workingDir = str_replace(
            '%kernel.project_dir%',
            $this->getApplication()->getKernel()->getProjectDir(),
            $input->getOption('working-dir'),
        );

        if (!is_dir($workingDir) && !mkdir($workingDir, 0755, true)) {
            $io->error("Could not create working directory: $workingDir");
            return Command::FAILURE;
        }

        $zipPath = $workingDir . '/' . self::ZIP_FILENAME;
        $txtPath = $workingDir . '/' . self::TXT_FILENAME;

        // --- Step 1: Download ---
        $io->section('Step 1/3 — Downloading Gazetteer zip...');
        $io->text(self::GAZETTEER_URL);

        try {
            $response = $this->httpClient->request('GET', self::GAZETTEER_URL);

            $fileHandle = fopen($zipPath, 'w');
            foreach ($this->httpClient->stream($response) as $chunk) {
                fwrite($fileHandle, $chunk->getContent());
            }
            fclose($fileHandle);

            $sizeMb = round(filesize($zipPath) / 1024 / 1024, 1);
            $io->success("Downloaded ({$sizeMb}MB) → $zipPath");
        } catch (\Throwable $e) {
            $io->error('Download failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // --- Step 2: Unzip ---
        $io->section('Step 2/3 — Extracting...');

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            $io->error("Could not open zip file: $zipPath");
            return Command::FAILURE;
        }

        $zip->extractTo($workingDir);
        $zip->close();

        if (!file_exists($txtPath)) {
            $io->error([
                "Expected file not found after extraction: $txtPath",
                "Contents of $workingDir:",
                implode("\n", glob($workingDir . '/*')),
            ]);
            return Command::FAILURE;
        }

        $io->success("Extracted → $txtPath");

        // --- Step 3: Import (delegates to app:hud:import-centroids) ---
        $io->section('Step 3/3 — Importing...');

        $importCommand = $this->getApplication()->find('app:hud:import-centroids');

        $importArgs = [
            'command' => 'app:hud:import-centroids',
            'file'    => $txtPath,
        ];

        if ($input->getOption('truncate')) {
            $importArgs['--truncate'] = true;
        }

        $returnCode = $importCommand->run(new ArrayInput($importArgs), $output);

        if ($returnCode !== Command::SUCCESS) {
            $io->error('Import step failed.');
            return Command::FAILURE;
        }

        // --- Cleanup ---
        if (!$input->getOption('keep-files')) {
            $io->section('Cleaning up downloaded files...');
            @unlink($zipPath);
            @unlink($txtPath);
            $io->text('Deleted zip and txt files.');
        }

        $io->success('Gazetteer download, extraction, and import complete.');

        return Command::SUCCESS;
    }
}
