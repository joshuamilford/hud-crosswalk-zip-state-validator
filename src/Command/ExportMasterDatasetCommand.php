<?php

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Exports a fully standalone master ZIP→state dataset including lat/lng centroids,
 * so the 50-mile border buffer can be applied without any database dependency.
 *
 * Sources:
 *   1. zip_code_state     — HUD data (lat/lng backfilled from Gazetteer import)
 *   2. zip_code_gazetteer — Census centroid fallback for HUD gaps
 *
 * Output files (written to var/master-dataset/):
 *   - zip_state_master.sql  — INSERT statements, works on any SQL database
 *   - zip_state_master.php  — PHP array, require and use with no dependencies
 *   - zip_state_master.csv  — flat CSV, one row per ZIP+state combination
 *
 * PHP array structure:
 *   'zip' => [
 *       'states'         => ['TX'],
 *       'is_multi_state' => false,
 *       'lat'            => 30.1234,
 *       'lng'            => -97.5678,
 *       'source'         => 'hud',   // 'hud' or 'gazetteer'
 *   ]
 *
 * Usage:
 *   php bin/console app:hud:export-master-dataset
 */
#[AsCommand(
    name: 'app:hud:export-master-dataset',
    description: 'Exports a standalone master ZIP→state dataset with lat/lng for buffer validation.',
)]
class ExportMasterDatasetCommand extends Command
{
    /** [minLat, maxLat, minLng, maxLng] */
    private const STATE_BOUNDING_BOXES = [
        'AL' => [30.14,  35.01,  -88.47, -84.89],
        'AK' => [51.21,  71.37, -179.15, -129.97],
        'AZ' => [31.33,  37.00, -114.82, -109.04],
        'AR' => [33.00,  36.50,  -94.62,  -89.64],
        'CA' => [32.53,  42.01, -124.41, -114.13],
        'CO' => [36.99,  41.00, -109.06, -102.04],
        'CT' => [40.95,  42.05,  -73.73,  -71.78],
        'DC' => [38.79,  38.99,  -77.12,  -76.91],
        'DE' => [38.45,  39.84,  -75.79,  -74.98],
        'FL' => [24.39,  31.00,  -87.63,  -79.97],
        'GA' => [30.36,  35.00,  -85.61,  -80.84],
        'HI' => [18.86,  22.24, -160.25, -154.75],
        'ID' => [41.99,  49.00, -117.24, -111.04],
        'IL' => [36.97,  42.51,  -91.51,  -87.02],
        'IN' => [37.77,  41.76,  -88.10,  -84.78],
        'IA' => [40.38,  43.50,  -96.64,  -90.14],
        'KS' => [36.99,  40.00, -102.05,  -94.59],
        'KY' => [36.50,  39.15,  -89.57,  -81.96],
        'LA' => [28.85,  33.02,  -94.04,  -88.82],
        'ME' => [42.98,  47.46,  -71.08,  -66.95],
        'MD' => [37.89,  39.72,  -79.49,  -74.98],
        'MA' => [41.24,  42.89,  -73.51,  -69.93],
        'MI' => [41.70,  48.18,  -90.42,  -82.41],
        'MN' => [43.50,  49.38,  -97.24,  -89.49],
        'MS' => [30.17,  35.01,  -91.65,  -88.10],
        'MO' => [35.99,  40.61,  -95.77,  -89.10],
        'MT' => [44.36,  49.00, -116.05, -104.04],
        'NE' => [39.99,  43.00, -104.05,  -95.31],
        'NV' => [35.00,  42.00, -120.00, -114.04],
        'NH' => [42.70,  45.31,  -72.56,  -70.61],
        'NJ' => [38.93,  41.36,  -75.56,  -73.89],
        'NM' => [31.33,  37.00, -109.05, -103.00],
        'NY' => [40.50,  45.02,  -79.76,  -71.86],
        'NC' => [33.75,  36.59,  -84.32,  -75.46],
        'ND' => [45.93,  49.00, -104.05,  -96.55],
        'OH' => [38.40,  41.98,  -84.82,  -80.52],
        'OK' => [33.62,  37.00, -103.00,  -94.43],
        'OR' => [41.99,  46.26, -124.57, -116.46],
        'PA' => [39.72,  42.27,  -80.52,  -74.69],
        'PR' => [17.88,  18.52,  -67.27,  -65.22],
        'RI' => [41.15,  42.02,  -71.86,  -71.12],
        'SC' => [32.05,  35.22,  -83.35,  -78.54],
        'SD' => [42.48,  45.95, -104.06,  -96.44],
        'TN' => [34.98,  36.68,  -90.31,  -81.65],
        'TX' => [25.84,  36.50, -106.65,  -93.51],
        'UT' => [36.99,  42.00, -114.05, -109.04],
        'VT' => [42.73,  45.02,  -73.44,  -71.46],
        'VA' => [36.54,  39.47,  -83.68,  -75.24],
        'WA' => [45.54,  49.00, -124.73, -116.92],
        'WV' => [37.20,  40.64,  -82.64,  -77.72],
        'WI' => [42.49,  47.08,  -92.89,  -86.25],
        'WY' => [40.99,  45.01, -111.05, -104.05],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'output-dir',
            'o',
            InputOption::VALUE_OPTIONAL,
            'Directory to write output files into',
            'var/master-dataset',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '-1');

        $io        = new SymfonyStyle($input, $output);
        $outputDir = $input->getOption('output-dir');

        if (!str_starts_with($outputDir, '/')) {
            $outputDir = $this->getProjectDir() . '/' . $outputDir;
        }

        if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true)) {
            $io->error("Could not create output directory: $outputDir");
            return Command::FAILURE;
        }

        $io->title('Exporting Master ZIP→State Dataset (with lat/lng)');

        $conn = $this->em->getConnection();

        // --- Load HUD data with centroids ---
        $io->text('Loading HUD data...');
        $hudRows = $conn->fetchAllAssociative(
            'SELECT zip_code, state_abbr, is_multi_state, latitude, longitude
             FROM zip_code_state
             ORDER BY zip_code, state_abbr'
        );

        // Build master map: zip => [states, is_multi_state, lat, lng, source]
        $master = [];
        foreach ($hudRows as $row) {
            $zip = $row['zip_code'];
            if (!isset($master[$zip])) {
                $master[$zip] = [
                    'states'         => [],
                    'is_multi_state' => (bool) $row['is_multi_state'],
                    'lat'            => $row['latitude']  !== null ? round((float) $row['latitude'],  7) : null,
                    'lng'            => $row['longitude'] !== null ? round((float) $row['longitude'], 7) : null,
                    'source'         => 'hud',
                ];
            }
            $master[$zip]['states'][] = $row['state_abbr'];
        }

        $hudCount = count($master);
        $io->text(sprintf('Loaded %d ZIPs from HUD.', $hudCount));

        // --- Load Gazetteer fallback for HUD gaps ---
        $io->text('Loading Gazetteer fallback data...');
        $gazRows = $conn->fetchAllAssociative(
            'SELECT zip_code, latitude, longitude FROM zip_code_gazetteer ORDER BY zip_code'
        );

        $gazAdded = 0;
        foreach ($gazRows as $row) {
            $zip = $row['zip_code'];
            if (isset($master[$zip])) continue;

            $lat   = (float) $row['latitude'];
            $lng   = (float) $row['longitude'];
            $state = $this->stateFromCoordinates($lat, $lng);

            if ($state === null) continue;

            $master[$zip] = [
                'states'         => [$state],
                'is_multi_state' => false,
                'lat'            => round($lat, 7),
                'lng'            => round($lng, 7),
                'source'         => 'gazetteer',
            ];
            $gazAdded++;
        }

        ksort($master);

        $total = count($master);
        $noLatLng = count(array_filter($master, fn($r) => $r['lat'] === null));

        $io->text(sprintf('Added %d Gazetteer-only ZIPs.', $gazAdded));
        $io->text(sprintf('Total: %d ZIPs (%d missing lat/lng — centroid import may not have run yet).', $total, $noLatLng));

        if ($noLatLng > 0) {
            $io->warning(sprintf(
                '%d HUD ZIPs are missing lat/lng. The buffer will not work for these. ' .
                'Run app:hud:import-centroids first for full coverage.',
                $noLatLng,
            ));
        }

        // --- Write files ---
        $io->section('Writing SQL...');
        $this->writeSql($master, $outputDir . '/zip_state_master.sql');

        $io->section('Writing PHP array...');
        $this->writePhp($master, $outputDir . '/zip_state_master.php');

        $io->section('Writing CSV...');
        $this->writeCsv($master, $outputDir . '/zip_state_master.csv');

        $io->success(sprintf(
            "Master dataset written to %s\n  %d total ZIPs (%d HUD, %d Gazetteer fallback, %d missing lat/lng)",
            $outputDir,
            $total,
            $hudCount,
            $gazAdded,
            $noLatLng,
        ));

        return Command::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Writers
    // -------------------------------------------------------------------------

    private function writeSql(array $master, string $path): void
    {
        $fh = fopen($path, 'w');

        fwrite($fh, "-- Master ZIP→State dataset with lat/lng centroids\n");
        fwrite($fh, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
        fwrite($fh, "-- Sources: HUD USPS Crosswalk + Census ZCTA Gazetteer\n");
        fwrite($fh, "-- Supports 50-mile border buffer validation via Haversine distance\n\n");

        fwrite($fh, "CREATE TABLE IF NOT EXISTS zip_state_master (\n");
        fwrite($fh, "    zip_code        VARCHAR(5)        NOT NULL,\n");
        fwrite($fh, "    state_abbr      VARCHAR(2)        NOT NULL,\n");
        fwrite($fh, "    is_multi_state  BOOLEAN           NOT NULL DEFAULT 0,\n");
        fwrite($fh, "    lat             DOUBLE PRECISION  DEFAULT NULL,\n");
        fwrite($fh, "    lng             DOUBLE PRECISION  DEFAULT NULL,\n");
        fwrite($fh, "    source          VARCHAR(10)       NOT NULL DEFAULT 'hud',\n");
        fwrite($fh, "    PRIMARY KEY (zip_code, state_abbr)\n");
        fwrite($fh, ");\n\n");
        fwrite($fh, "CREATE INDEX IF NOT EXISTS idx_zsm_zip ON zip_state_master (zip_code);\n\n");

        $rows = [];
        foreach ($master as $zip => $data) {
            $lat     = $data['lat'] !== null ? $data['lat'] : 'NULL';
            $lng     = $data['lng'] !== null ? $data['lng'] : 'NULL';
            $isMulti = $data['is_multi_state'] ? 1 : 0;
            $source  = $data['source'];

            foreach ($data['states'] as $state) {
                $rows[] = "('$zip','$state',$isMulti,$lat,$lng,'$source')";

                if (count($rows) >= 500) {
                    fwrite($fh, "INSERT OR IGNORE INTO zip_state_master (zip_code,state_abbr,is_multi_state,lat,lng,source) VALUES\n");
                    fwrite($fh, implode(",\n", $rows) . ";\n\n");
                    $rows = [];
                }
            }
        }

        if (!empty($rows)) {
            fwrite($fh, "INSERT OR IGNORE INTO zip_state_master (zip_code,state_abbr,is_multi_state,lat,lng,source) VALUES\n");
            fwrite($fh, implode(",\n", $rows) . ";\n\n");
        }

        fclose($fh);
    }

    private function writePhp(array $master, string $path): void
    {
        $fh = fopen($path, 'w');

        fwrite($fh, "<?php\n\n");
        fwrite($fh, "/**\n");
        fwrite($fh, " * Master ZIP→State dataset with lat/lng centroids\n");
        fwrite($fh, " * Generated: " . date('Y-m-d H:i:s') . "\n");
        fwrite($fh, " * Sources: HUD USPS Crosswalk + Census ZCTA Gazetteer\n");
        fwrite($fh, " *\n");
        fwrite($fh, " * Structure:\n");
        fwrite($fh, " *   'zip' => [\n");
        fwrite($fh, " *       'states'         => ['TX'],     // all valid states for this ZIP\n");
        fwrite($fh, " *       'is_multi_state'  => false,      // true if ZIP spans multiple states\n");
        fwrite($fh, " *       'lat'             => 30.1234,    // centroid latitude  (null if unavailable)\n");
        fwrite($fh, " *       'lng'             => -97.5678,   // centroid longitude (null if unavailable)\n");
        fwrite($fh, " *       'source'          => 'hud',      // 'hud' or 'gazetteer'\n");
        fwrite($fh, " *   ]\n");
        fwrite($fh, " *\n");
        fwrite($fh, " * Basic usage (no buffer):\n");
        fwrite($fh, " *   \$data    = require 'zip_state_master.php';\n");
        fwrite($fh, " *   \$entry   = \$data[\$zip] ?? null;\n");
        fwrite($fh, " *   \$isValid = \$entry && in_array(\$claimedState, \$entry['states']);\n");
        fwrite($fh, " *\n");
        fwrite($fh, " * With 50-mile buffer (see ZipStateValidator below):\n");
        fwrite($fh, " *   \$validator = new ZipStateValidator(\$data);\n");
        fwrite($fh, " *   \$result    = \$validator->validate(\$zip, \$claimedState);\n");
        fwrite($fh, " */\n\n");

        // Embed the standalone validator class directly in the file
        fwrite($fh, $this->standaloneValidatorClass());

        fwrite($fh, "\nreturn [\n");

        foreach ($master as $zip => $data) {
            $states  = "['" . implode("','", $data['states']) . "']";
            $isMulti = $data['is_multi_state'] ? 'true' : 'false';
            $lat     = $data['lat'] !== null ? $data['lat'] : 'null';
            $lng     = $data['lng'] !== null ? $data['lng'] : 'null';
            $source  = $data['source'];
            fwrite($fh, "    '$zip'=>['states'=>$states,'is_multi_state'=>$isMulti,'lat'=>$lat,'lng'=>$lng,'source'=>'$source'],\n");
        }

        fwrite($fh, "];\n");
        fclose($fh);
    }

    private function writeCsv(array $master, string $path): void
    {
        $fh = fopen($path, 'w');
        fputcsv($fh, ['zip_code', 'state_abbr', 'is_multi_state', 'lat', 'lng', 'source']);

        foreach ($master as $zip => $data) {
            foreach ($data['states'] as $state) {
                fputcsv($fh, [
                    $zip,
                    $state,
                    $data['is_multi_state'] ? 1 : 0,
                    $data['lat'] ?? '',
                    $data['lng'] ?? '',
                    $data['source'],
                ]);
            }
        }

        fclose($fh);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Returns a standalone ZipStateValidator class as a string, embedded directly
     * into the PHP array file so the file is fully self-contained.
     */
    private function standaloneValidatorClass(): string
    {
        return <<<'PHP'
<?php if (!class_exists('ZipStateValidator')):

/**
 * Standalone ZIP→state validator.
 * No framework dependencies — works anywhere PHP runs.
 *
 * Usage:
 *   $data      = require 'zip_state_master.php';
 *   $validator = new ZipStateValidator($data);
 *   $result    = $validator->validate('20705', 'VA');
 *
 *   $result['valid']          // bool
 *   $result['reason']         // string — see REASON_* constants
 *   $result['known_states']   // array of valid states for this ZIP
 *   $result['is_multi_state'] // bool
 *   $result['distance_miles'] // float|null — distance to claimed state border
 */
class ZipStateValidator
{
    public const REASON_VALID                    = 'valid';
    public const REASON_MINORITY_MATCH           = 'minority_match';
    public const REASON_BORDER_PROXIMITY         = 'border_proximity';
    public const REASON_STATE_MISMATCH           = 'state_mismatch';
    public const REASON_ZIP_NOT_FOUND            = 'zip_not_found';

    private const BUFFER_MILES = 50.0;

    private const STATE_BOUNDING_BOXES = [
        'AL'=>[30.14,35.01,-88.47,-84.89],'AK'=>[51.21,71.37,-179.15,-129.97],
        'AZ'=>[31.33,37.00,-114.82,-109.04],'AR'=>[33.00,36.50,-94.62,-89.64],
        'CA'=>[32.53,42.01,-124.41,-114.13],'CO'=>[36.99,41.00,-109.06,-102.04],
        'CT'=>[40.95,42.05,-73.73,-71.78],'DC'=>[38.79,38.99,-77.12,-76.91],
        'DE'=>[38.45,39.84,-75.79,-74.98],'FL'=>[24.39,31.00,-87.63,-79.97],
        'GA'=>[30.36,35.00,-85.61,-80.84],'HI'=>[18.86,22.24,-160.25,-154.75],
        'ID'=>[41.99,49.00,-117.24,-111.04],'IL'=>[36.97,42.51,-91.51,-87.02],
        'IN'=>[37.77,41.76,-88.10,-84.78],'IA'=>[40.38,43.50,-96.64,-90.14],
        'KS'=>[36.99,40.00,-102.05,-94.59],'KY'=>[36.50,39.15,-89.57,-81.96],
        'LA'=>[28.85,33.02,-94.04,-88.82],'ME'=>[42.98,47.46,-71.08,-66.95],
        'MD'=>[37.89,39.72,-79.49,-74.98],'MA'=>[41.24,42.89,-73.51,-69.93],
        'MI'=>[41.70,48.18,-90.42,-82.41],'MN'=>[43.50,49.38,-97.24,-89.49],
        'MS'=>[30.17,35.01,-91.65,-88.10],'MO'=>[35.99,40.61,-95.77,-89.10],
        'MT'=>[44.36,49.00,-116.05,-104.04],'NE'=>[39.99,43.00,-104.05,-95.31],
        'NV'=>[35.00,42.00,-120.00,-114.04],'NH'=>[42.70,45.31,-72.56,-70.61],
        'NJ'=>[38.93,41.36,-75.56,-73.89],'NM'=>[31.33,37.00,-109.05,-103.00],
        'NY'=>[40.50,45.02,-79.76,-71.86],'NC'=>[33.75,36.59,-84.32,-75.46],
        'ND'=>[45.93,49.00,-104.05,-96.55],'OH'=>[38.40,41.98,-84.82,-80.52],
        'OK'=>[33.62,37.00,-103.00,-94.43],'OR'=>[41.99,46.26,-124.57,-116.46],
        'PA'=>[39.72,42.27,-80.52,-74.69],'PR'=>[17.88,18.52,-67.27,-65.22],
        'RI'=>[41.15,42.02,-71.86,-71.12],'SC'=>[32.05,35.22,-83.35,-78.54],
        'SD'=>[42.48,45.95,-104.06,-96.44],'TN'=>[34.98,36.68,-90.31,-81.65],
        'TX'=>[25.84,36.50,-106.65,-93.51],'UT'=>[36.99,42.00,-114.05,-109.04],
        'VT'=>[42.73,45.02,-73.44,-71.46],'VA'=>[36.54,39.47,-83.68,-75.24],
        'WA'=>[45.54,49.00,-124.73,-116.92],'WV'=>[37.20,40.64,-82.64,-77.72],
        'WI'=>[42.49,47.08,-92.89,-86.25],'WY'=>[40.99,45.01,-111.05,-104.05],
    ];

    public function __construct(private readonly array $dataset) {}

    public function validate(string $zip, string $state): array
    {
        $zip   = str_pad(trim($zip), 5, '0', STR_PAD_LEFT);
        $state = strtoupper(trim($state));

        $entry = $this->dataset[$zip] ?? null;

        if ($entry === null) {
            return $this->result(false, $zip, $state, [], false, self::REASON_ZIP_NOT_FOUND);
        }

        $knownStates  = $entry['states'];
        $isMultiState = $entry['is_multi_state'];
        $lat          = $entry['lat'];
        $lng          = $entry['lng'];

        if (in_array($state, $knownStates, true)) {
            return $this->result(true, $zip, $state, $knownStates, $isMultiState, self::REASON_VALID);
        }

        // Not a direct match — check border proximity if we have coordinates
        if ($lat !== null && $lng !== null) {
            $distance = $this->milesFromBoundingBox((float)$lat, (float)$lng, $state);
            if ($distance !== null && $distance <= self::BUFFER_MILES) {
                return $this->result(false, $zip, $state, $knownStates, $isMultiState, self::REASON_BORDER_PROXIMITY, $distance);
            }
        }

        return $this->result(false, $zip, $state, $knownStates, $isMultiState, self::REASON_STATE_MISMATCH);
    }

    private function milesFromBoundingBox(float $lat, float $lng, string $state): ?float
    {
        $box = self::STATE_BOUNDING_BOXES[$state] ?? null;
        if ($box === null) return null;
        [$minLat, $maxLat, $minLng, $maxLng] = $box;
        if ($lat >= $minLat && $lat <= $maxLat && $lng >= $minLng && $lng <= $maxLng) return 0.0;
        return $this->haversine($lat, $lng, max($minLat, min($lat, $maxLat)), max($minLng, min($lng, $maxLng)));
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 3958.8;
        $a = sin(deg2rad($lat2-$lat1)/2)**2 + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin(deg2rad($lng2-$lng1)/2)**2;
        return $r * 2 * asin(sqrt($a));
    }

    private function result(bool $valid, string $zip, string $state, array $known, bool $multi, string $reason, ?float $distance = null): array
    {
        return ['valid'=>$valid,'zip'=>$zip,'claimed_state'=>$state,'known_states'=>$known,'is_multi_state'=>$multi,'reason'=>$reason,'distance_miles'=>$distance];
    }
}

endif;

PHP;
    }

    private function stateFromCoordinates(float $lat, float $lng): ?string
    {
        foreach (self::STATE_BOUNDING_BOXES as $state => [$minLat, $maxLat, $minLng, $maxLng]) {
            if ($lat >= $minLat && $lat <= $maxLat && $lng >= $minLng && $lng <= $maxLng) {
                return $state;
            }
        }
        return null;
    }

    private function getProjectDir(): string
    {
        $dir = __DIR__;
        while (!file_exists($dir . '/composer.json')) {
            $dir = dirname($dir);
            if ($dir === '/') break;
        }
        return $dir;
    }
}
