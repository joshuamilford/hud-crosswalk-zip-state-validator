#!/usr/bin/env php
<?php

/**
 * Validate production city/state/zip data against the HUD zip_code_state table.
 *
 * Usage:
 *   php bin/validate-zips.php /path/to/state-zip.txt
 *
 * Output:
 *   - Summary printed to console
 *   - var/zip-validation-results.csv written with full detail
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

$kernel = new \App\Kernel($_SERVER['APP_ENV'] ?? 'dev', (bool) ($_SERVER['APP_DEBUG'] ?? true));
$kernel->boot();

/** @var \Doctrine\DBAL\Connection $conn */
$conn = $kernel->getContainer()->get('doctrine')->getConnection();

$BORDER_BUFFER_MILES = 50.0;

// ---------------------------------------------------------------------------
// Bounding boxes — must match ZipCodeStateValidator::STATE_BOUNDING_BOXES
// [minLat, maxLat, minLng, maxLng]
// ---------------------------------------------------------------------------
$STATE_BOUNDING_BOXES = [
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

$haversine = function(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $earthRadiusMiles = 3958.8;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a    = sin($dLat / 2) ** 2
          + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $earthRadiusMiles * 2 * asin(sqrt($a));
};

$distanceToBox = function(float $lat, float $lng, string $state) use ($STATE_BOUNDING_BOXES, $haversine): ?float {
    $box = $STATE_BOUNDING_BOXES[$state] ?? null;
    if ($box === null) return null;
    [$minLat, $maxLat, $minLng, $maxLng] = $box;
    if ($lat >= $minLat && $lat <= $maxLat && $lng >= $minLng && $lng <= $maxLng) return 0.0;
    $nearestLat = max($minLat, min($lat, $maxLat));
    $nearestLng = max($minLng, min($lng, $maxLng));
    return $haversine($lat, $lng, $nearestLat, $nearestLng);
};

// ---------------------------------------------------------------------------
// 1. Parse input file
// ---------------------------------------------------------------------------

$inputFile = $argv[1] ?? null;
if (!$inputFile || !file_exists($inputFile)) {
    fwrite(STDERR, "Usage: php bin/validate-zips.php /path/to/state-zip.txt\n");
    exit(1);
}

echo "Parsing input file...\n";
$records = [];
$skipped = 0;

$handle = fopen($inputFile, 'r');
while (($line = fgets($handle)) !== false) {
    if (!str_starts_with(trim($line), '|')) continue;
    if (str_contains($line, '---'))         continue;
    if (str_contains(strtolower($line), 'city')) continue;

    $parts = array_map('trim', explode('|', trim($line, " |\n\r")));
    if (count($parts) < 3) { $skipped++; continue; }

    [$city, $state, $zip] = [$parts[0], $parts[1], $parts[2]];

    if (str_contains($zip, '-')) $zip = explode('-', $zip)[0];
    if (!$zip || !$state || strtolower($state) === 'null') { $skipped++; continue; }
    if (!ctype_digit($zip))                                { $skipped++; continue; }

    $zip   = str_pad($zip, 5, '0', STR_PAD_LEFT);
    $state = strtoupper($state);

    if (strlen($zip) !== 5 || strlen($state) !== 2) { $skipped++; continue; }

    $records[] = [$city, $state, $zip];
}
fclose($handle);

echo sprintf("Parsed %d clean records (%d skipped as malformed).\n", count($records), $skipped);

// ---------------------------------------------------------------------------
// 2. Pre-load HUD data into memory
// ---------------------------------------------------------------------------

echo "Loading HUD ZIP data from database...\n";

$rows = $conn->fetchAllAssociative(
    'SELECT zip_code, state_abbr, res_ratio, is_multi_state, latitude, longitude
     FROM zip_code_state
     ORDER BY zip_code, res_ratio DESC'
);

$hudMap = [];
foreach ($rows as $row) {
    $hudMap[$row['zip_code']][] = $row;
}

echo sprintf("Loaded %d ZIP codes from HUD data.\n", count($hudMap));

$hasCentroids = count(array_filter($rows, fn($r) => $r['latitude'] !== null)) > 0;
if (!$hasCentroids) {
    echo "WARNING: No centroid data found. Border proximity check will be skipped.\n";
    echo "         Run: php bin/console app:hud:import-centroids /path/to/2023_Gaz_zcta_national.txt\n\n";
}

// ---------------------------------------------------------------------------
// 3. Validate
// ---------------------------------------------------------------------------

echo "Validating records...\n";

$results = [
    'valid'            => [],
    'minority_match'   => [],
    'border_proximity' => [],
    'mismatch'         => [],
    'not_found'        => [],
];

foreach ($records as [$city, $state, $zip]) {
    if (!isset($hudMap[$zip])) {
        $results['not_found'][] = [
            'city' => $city, 'claimed_state' => $state, 'zip' => $zip,
            'known_states' => '', 'is_multi_state' => 0,
            'res_ratio' => '', 'distance_miles' => '', 'reason' => 'zip_not_found',
        ];
        continue;
    }

    $zipRows     = $hudMap[$zip];
    $knownStates = implode(',', array_column($zipRows, 'state_abbr'));
    $isMulti     = (bool) $zipRows[0]['is_multi_state'];
    $lat         = $zipRows[0]['latitude']  !== null ? (float) $zipRows[0]['latitude']  : null;
    $lng         = $zipRows[0]['longitude'] !== null ? (float) $zipRows[0]['longitude'] : null;

    $match = null;
    foreach ($zipRows as $zipRow) {
        if ($zipRow['state_abbr'] === $state) { $match = $zipRow; break; }
    }

    $base = [
        'city'           => $city,
        'claimed_state'  => $state,
        'zip'            => $zip,
        'known_states'   => $knownStates,
        'is_multi_state' => $isMulti ? 1 : 0,
        'res_ratio'      => $match ? $match['res_ratio'] : '',
        'distance_miles' => '',
    ];

    if ($match !== null) {
        $reason = ((float) $match['res_ratio'] < 0.10) ? 'minority_match' : 'valid';
        $results[$reason][] = array_merge($base, ['reason' => $reason]);
        continue;
    }

    // No match — check border proximity
    if ($lat !== null && $lng !== null) {
        $distance = $distanceToBox($lat, $lng, $state);
        if ($distance !== null && $distance <= $BORDER_BUFFER_MILES) {
            $results['border_proximity'][] = array_merge($base, [
                'distance_miles' => round($distance, 1),
                'reason'         => 'border_proximity',
            ]);
            continue;
        }
        $base['distance_miles'] = $distance !== null ? round($distance, 1) : '';
    }

    $results['mismatch'][] = array_merge($base, ['reason' => 'state_mismatch']);
}

// ---------------------------------------------------------------------------
// 4. Write CSV
// ---------------------------------------------------------------------------

$outputFile = __DIR__ . '/../var/zip-validation-results.csv';
$fh = fopen($outputFile, 'w');
fputcsv($fh, ['city', 'claimed_state', 'zip', 'known_states', 'is_multi_state', 'res_ratio', 'distance_miles', 'reason']);

foreach (['mismatch', 'border_proximity', 'minority_match', 'not_found', 'valid'] as $bucket) {
    foreach ($results[$bucket] as $row) {
        fputcsv($fh, array_values($row));
    }
}
fclose($fh);

// ---------------------------------------------------------------------------
// 5. Summary
// ---------------------------------------------------------------------------

$total     = count($records);
$valid     = count($results['valid']);
$minority  = count($results['minority_match']);
$border    = count($results['border_proximity']);
$mismatch  = count($results['mismatch']);
$notFound  = count($results['not_found']);

echo "\n";
echo "=================================================================\n";
echo " VALIDATION SUMMARY\n";
echo "=================================================================\n";
echo sprintf(" Total records validated    : %d\n",   $total);
echo sprintf(" ✓ Valid (clean match)      : %d (%.1f%%)\n", $valid,    ($valid    / $total) * 100);
echo sprintf(" ~ Minority match  (<10%%)  : %d (%.1f%%)\n", $minority, ($minority / $total) * 100);
echo sprintf(" ~ Border proximity (%dmiles): %d (%.1f%%)\n", $BORDER_BUFFER_MILES, $border, ($border / $total) * 100);
echo sprintf(" ✗ State mismatch           : %d (%.1f%%)\n", $mismatch, ($mismatch / $total) * 100);
echo sprintf(" ? ZIP not found            : %d (%.1f%%)\n", $notFound, ($notFound / $total) * 100);
echo "=================================================================\n\n";

if ($mismatch > 0) {
    echo "Top hard mismatch patterns (claimed → actual):\n";
    $patterns = [];
    foreach ($results['mismatch'] as $r) {
        $key = $r['claimed_state'] . ' → ' . $r['known_states'];
        $patterns[$key] = ($patterns[$key] ?? 0) + 1;
    }
    arsort($patterns);
    foreach (array_slice($patterns, 0, 10, true) as $pattern => $count) {
        echo sprintf("  %-30s %d\n", $pattern, $count);
    }
    echo "\n";
}

if ($border > 0) {
    echo "Sample border proximity cases (state mismatch but within {$BORDER_BUFFER_MILES} miles):\n";
    foreach (array_slice($results['border_proximity'], 0, 5) as $r) {
        echo sprintf("  %s, %s %s — actual: %s, %.1f miles from %s border\n",
            $r['city'], $r['claimed_state'], $r['zip'],
            $r['known_states'], $r['distance_miles'], $r['claimed_state']
        );
    }
    echo "\n";
}

echo "Full results written to: $outputFile\n";
