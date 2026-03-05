<?php

namespace App\Service;

use App\Repository\ZipCodeGazetteerRepository;
use App\Repository\ZipCodeStateRepository;

/**
 * Validates whether a ZIP code is consistent with a claimed state.
 *
 * Validation flow:
 *   1. Look up ZIP in HUD zip_code_state table
 *   2. If found and matches claimed state → valid (or minority_match if res_ratio < 10%)
 *   3. If found but no state match → check border proximity via bounding box
 *      a. Within buffer → border_proximity (soft signal)
 *      b. Outside buffer → state_mismatch (hard signal)
 *   4. If NOT found in HUD → fall back to Census Gazetteer centroid
 *      a. Centroid inside claimed state's bounding box → valid_gazetteer_fallback
 *      b. Centroid within buffer of claimed state → border_proximity_fallback
 *      c. Centroid outside buffer → state_mismatch_fallback
 *      d. Not in Gazetteer either → zip_not_found (truly inconclusive)
 */
class ZipCodeStateValidator
{
    /** [minLat, maxLat, minLng, maxLng] — derived from Census TIGER state boundary extents */
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
        private readonly ZipCodeStateRepository    $repository,
        private readonly ZipCodeGazetteerRepository $gazetteerRepository,
        private readonly float                      $borderBufferMiles = 50.0,
    ) {}

    public function validate(string $zipCode, string $claimedStateAbbr): ValidationResult
    {
        $zipCode          = str_pad(trim($zipCode), 5, '0', STR_PAD_LEFT);
        $claimedStateAbbr = strtoupper(trim($claimedStateAbbr));

        $rows = $this->repository->findRowsByZip($zipCode);

        // ----------------------------------------------------------------
        // PATH A: ZIP found in HUD
        // ----------------------------------------------------------------
        if (!empty($rows)) {
            $knownStates  = array_map(fn($r) => $r->getStateAbbr(), $rows);
            $isMultiState = count($knownStates) > 1;
            $match        = null;

            foreach ($rows as $row) {
                if ($row->getStateAbbr() === $claimedStateAbbr) {
                    $match = $row;
                    break;
                }
            }

            // Direct match
            if ($match !== null) {
                $reason = ((float) $match->getResRatio() < 0.10)
                    ? ValidationResult::REASON_MINORITY_MATCH
                    : ValidationResult::REASON_VALID;

                return new ValidationResult(
                    valid:           true,
                    zip:             $zipCode,
                    claimedState:    $claimedStateAbbr,
                    knownStates:     $knownStates,
                    isMultiState:    $isMultiState,
                    reason:          $reason,
                    matchedResRatio: (float) $match->getResRatio(),
                );
            }

            // No match — check border proximity using centroid stored on zip_code_state
            $lat = $rows[0]->getLatitude();
            $lng = $rows[0]->getLongitude();

            if ($lat !== null && $lng !== null) {
                $distance = $this->milesFromBoundingBox($lat, $lng, $claimedStateAbbr);

                if ($distance !== null && $distance <= $this->borderBufferMiles) {
                    return new ValidationResult(
                        valid:         false,
                        zip:           $zipCode,
                        claimedState:  $claimedStateAbbr,
                        knownStates:   $knownStates,
                        isMultiState:  $isMultiState,
                        reason:        ValidationResult::REASON_BORDER_PROXIMITY,
                        distanceMiles: $distance,
                    );
                }
            }

            return new ValidationResult(
                valid:        false,
                zip:          $zipCode,
                claimedState: $claimedStateAbbr,
                knownStates:  $knownStates,
                isMultiState: $isMultiState,
                reason:       ValidationResult::REASON_STATE_MISMATCH,
            );
        }

        // ----------------------------------------------------------------
        // PATH B: ZIP not in HUD — try Gazetteer fallback
        // ----------------------------------------------------------------
        $gazetteer = $this->gazetteerRepository->findByZip($zipCode);

        if ($gazetteer !== null) {
            $lat = $gazetteer->getLatitude();
            $lng = $gazetteer->getLongitude();

            $distance = $this->milesFromBoundingBox($lat, $lng, $claimedStateAbbr);

            // Centroid is inside the claimed state's bounding box
            if ($distance !== null && $distance === 0.0) {
                return new ValidationResult(
                    valid:         true,
                    zip:           $zipCode,
                    claimedState:  $claimedStateAbbr,
                    knownStates:   [$claimedStateAbbr],
                    isMultiState:  false,
                    reason:        ValidationResult::REASON_VALID_GAZETTEER_FALLBACK,
                    distanceMiles: 0.0,
                );
            }

            // Centroid is outside the box but within the buffer
            if ($distance !== null && $distance <= $this->borderBufferMiles) {
                return new ValidationResult(
                    valid:         false,
                    zip:           $zipCode,
                    claimedState:  $claimedStateAbbr,
                    knownStates:   [],
                    isMultiState:  false,
                    reason:        ValidationResult::REASON_BORDER_PROXIMITY_FALLBACK,
                    distanceMiles: $distance,
                );
            }

            // Centroid is clearly outside — mismatch
            return new ValidationResult(
                valid:         false,
                zip:           $zipCode,
                claimedState:  $claimedStateAbbr,
                knownStates:   [],
                isMultiState:  false,
                reason:        ValidationResult::REASON_STATE_MISMATCH_FALLBACK,
                distanceMiles: $distance,
            );
        }

        // ----------------------------------------------------------------
        // PATH C: Not in HUD or Gazetteer — truly inconclusive
        // ----------------------------------------------------------------
        return new ValidationResult(
            valid:        false,
            zip:          $zipCode,
            claimedState: $claimedStateAbbr,
            knownStates:  [],
            isMultiState: false,
            reason:       ValidationResult::REASON_ZIP_NOT_FOUND,
        );
    }

    private function milesFromBoundingBox(float $lat, float $lng, string $stateAbbr): ?float
    {
        $box = self::STATE_BOUNDING_BOXES[$stateAbbr] ?? null;
        if ($box === null) return null;

        [$minLat, $maxLat, $minLng, $maxLng] = $box;

        if ($lat >= $minLat && $lat <= $maxLat && $lng >= $minLng && $lng <= $maxLng) {
            return 0.0;
        }

        $nearestLat = max($minLat, min($lat, $maxLat));
        $nearestLng = max($minLng, min($lng, $maxLng));

        return $this->haversineDistance($lat, $lng, $nearestLat, $nearestLng);
    }

    private function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r    = 3958.8;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a    = sin($dLat / 2) ** 2
              + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $r * 2 * asin(sqrt($a));
    }
}


/**
 * Value object returned by ZipCodeStateValidator::validate().
 */
class ValidationResult
{
    // HUD-sourced results
    public const REASON_VALID            = 'valid';
    public const REASON_MINORITY_MATCH   = 'minority_match';
    public const REASON_BORDER_PROXIMITY = 'border_proximity';
    public const REASON_STATE_MISMATCH   = 'state_mismatch';

    // Gazetteer fallback results (ZIP was missing from HUD)
    public const REASON_VALID_GAZETTEER_FALLBACK      = 'valid_gazetteer_fallback';
    public const REASON_BORDER_PROXIMITY_FALLBACK      = 'border_proximity_fallback';
    public const REASON_STATE_MISMATCH_FALLBACK        = 'state_mismatch_fallback';

    // Truly unknown
    public const REASON_ZIP_NOT_FOUND = 'zip_not_found';

    public function __construct(
        public readonly bool    $valid,
        public readonly string  $zip,
        public readonly string  $claimedState,
        /** @var string[] */
        public readonly array   $knownStates,
        public readonly bool    $isMultiState,
        public readonly string  $reason,
        public readonly ?float  $matchedResRatio = null,
        public readonly ?float  $distanceMiles   = null,
    ) {}

    public function isHudBacked(): bool
    {
        return in_array($this->reason, [
            self::REASON_VALID,
            self::REASON_MINORITY_MATCH,
            self::REASON_BORDER_PROXIMITY,
            self::REASON_STATE_MISMATCH,
        ], true);
    }

    public function isGazetteerFallback(): bool
    {
        return in_array($this->reason, [
            self::REASON_VALID_GAZETTEER_FALLBACK,
            self::REASON_BORDER_PROXIMITY_FALLBACK,
            self::REASON_STATE_MISMATCH_FALLBACK,
        ], true);
    }

    public function isSoftSignal(): bool
    {
        return in_array($this->reason, [
            self::REASON_MINORITY_MATCH,
            self::REASON_BORDER_PROXIMITY,
            self::REASON_BORDER_PROXIMITY_FALLBACK,
        ], true);
    }

    public function isCleanMatch(): bool
    {
        return in_array($this->reason, [
            self::REASON_VALID,
            self::REASON_VALID_GAZETTEER_FALLBACK,
        ], true);
    }

    public function toArray(): array
    {
        return [
            'valid'             => $this->valid,
            'zip'               => $this->zip,
            'claimed_state'     => $this->claimedState,
            'known_states'      => $this->knownStates,
            'is_multi_state'    => $this->isMultiState,
            'reason'            => $this->reason,
            'matched_res_ratio' => $this->matchedResRatio,
            'distance_miles'    => $this->distanceMiles,
        ];
    }
}
