<?php

namespace App\Service;

use App\Repository\ZipCodeStateRepository;

/**
 * Validates whether a ZIP code is consistent with a claimed state.
 *
 * This is intentionally lenient for multi-state ZIPs: if a ZIP legitimately
 * spans multiple states, any of those states is considered valid. You can
 * tighten the logic using the $minResRatioThreshold parameter to require
 * that the claimed state holds a meaningful share of addresses.
 */
class ZipCodeStateValidator
{
    public function __construct(
        private readonly ZipCodeStateRepository $repository,
    ) {}

    /**
     * Result object returned by validate().
     */
    public function validate(string $zipCode, string $claimedStateAbbr): ValidationResult
    {
        $zipCode          = str_pad(trim($zipCode), 5, '0', STR_PAD_LEFT);
        $claimedStateAbbr = strtoupper(trim($claimedStateAbbr));

        $rows = $this->repository->findRowsByZip($zipCode);

        if (empty($rows)) {
            return new ValidationResult(
                valid: false,
                zip: $zipCode,
                claimedState: $claimedStateAbbr,
                knownStates: [],
                isMultiState: false,
                reason: ValidationResult::REASON_ZIP_NOT_FOUND,
            );
        }

        $knownStates = array_map(fn($r) => $r->getStateAbbr(), $rows);
        $isMultiState = count($knownStates) > 1;

        $match = null;
        foreach ($rows as $row) {
            if ($row->getStateAbbr() === $claimedStateAbbr) {
                $match = $row;
                break;
            }
        }

        if ($match === null) {
            return new ValidationResult(
                valid: false,
                zip: $zipCode,
                claimedState: $claimedStateAbbr,
                knownStates: $knownStates,
                isMultiState: $isMultiState,
                reason: ValidationResult::REASON_STATE_MISMATCH,
            );
        }

        return new ValidationResult(
            valid: true,
            zip: $zipCode,
            claimedState: $claimedStateAbbr,
            knownStates: $knownStates,
            isMultiState: $isMultiState,
            reason: ValidationResult::REASON_VALID,
            matchedResRatio: (float) $match->getResRatio(),
        );
    }
}


/**
 * Simple value object for validation results.
 * Intentionally not using readonly to remain compatible with PHP 8.1.
 */
class ValidationResult
{
    public const REASON_VALID        = 'valid';
    public const REASON_STATE_MISMATCH = 'state_mismatch';
    public const REASON_ZIP_NOT_FOUND  = 'zip_not_found';

    public function __construct(
        public readonly bool $valid,
        public readonly string $zip,
        public readonly string $claimedState,
        /** @var string[] */
        public readonly array $knownStates,
        public readonly bool $isMultiState,
        public readonly string $reason,
        /** Residential address ratio for the matched state, or null on mismatch */
        public readonly ?float $matchedResRatio = null,
    ) {}

    /**
     * Returns true when the ZIP is valid for the claimed state but the match represents
     * a minority of residential addresses — a softer fraud signal worth logging.
     */
    public function isMinorityMatch(float $threshold = 0.10): bool
    {
        return $this->valid
            && $this->matchedResRatio !== null
            && $this->matchedResRatio < $threshold;
    }

    public function toArray(): array
    {
        return [
            'valid'            => $this->valid,
            'zip'              => $this->zip,
            'claimed_state'    => $this->claimedState,
            'known_states'     => $this->knownStates,
            'is_multi_state'   => $this->isMultiState,
            'reason'           => $this->reason,
            'matched_res_ratio' => $this->matchedResRatio,
        ];
    }
}
