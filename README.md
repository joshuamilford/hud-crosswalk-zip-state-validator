# HUD USPS Crosswalk — Symfony Integration

This package adds ZIP code → state validation to your Symfony application
using data sourced directly from the HUD USPS Crosswalk API (a U.S. government
data source updated quarterly).

> "This product uses the HUD User Data API but is not endorsed or certified by HUD User."

---

## Files

| File | Purpose |
|---|---|
| `src/Entity/ZipCodeState.php` | Doctrine entity storing each ZIP→state mapping |
| `src/Repository/ZipCodeStateRepository.php` | Query helpers |
| `src/Service/HudCrosswalkClient.php` | HUD API HTTP client |
| `src/Service/ZipCodeStateValidator.php` | Fraud-check validator + `ValidationResult` VO |
| `src/Command/ImportHudZipStatesCommand.php` | Console import command |
| `migrations/Version20240001000000.php` | Database migration |
| `config/services_hud.yaml` | Service wiring snippet |

---

## Setup

### 1. Get a free HUD API token

Register at: https://www.huduser.gov/hudapi/public/login
Select "USPS Crosswalk" when creating your token.

### 2. Add to `.env`

```dotenv
HUD_API_TOKEN=your_token_here
```

### 3. Merge the service config

Copy the contents of `config/services_hud.yaml` into your `config/services.yaml`,
or import it:

```yaml
# config/services.yaml
imports:
    - { resource: services_hud.yaml }
```

### 4. Run the migration

```bash
php bin/console doctrine:migrations:migrate
```

### 5. Import the data

Full import (all 52 state/territory queries, ~45k rows, takes ~3–5 minutes):

```bash
php bin/console app:hud:import-zip-states --truncate
```

Targeted import (e.g. just a few states during development):

```bash
php bin/console app:hud:import-zip-states --states=TX,OK,NM --truncate
```

Refresh quarterly (re-run without `--truncate` to skip existing rows, or with it for a clean slate):

```bash
# Add to a cron — HUD publishes new data ~end of month after each quarter
0 2 1 2,5,8,11 * php /var/www/html/bin/console app:hud:import-zip-states --truncate --env=prod
```

---

## Usage in your fraud detection code

```php
use App\Service\ZipCodeStateValidator;

class FraudCheckService
{
    public function __construct(
        private readonly ZipCodeStateValidator $zipValidator,
    ) {}

    public function checkUser(string $zip, string $claimedState): void
    {
        $result = $this->zipValidator->validate($zip, $claimedState);

        if (!$result->valid) {
            // Flag as suspicious
            // $result->reason will be:
            //   'state_mismatch' — ZIP exists but not in the claimed state
            //   'zip_not_found'  — ZIP not in our dataset at all (PO Box ZIPs
            //                      are excluded by HUD; treat as inconclusive)
        }

        if ($result->isMultiState) {
            // ZIP legitimately spans multiple states — softer signal, log but
            // don't hard-block without additional corroboration.
        }

        if ($result->isMinorityMatch(threshold: 0.10)) {
            // The claimed state accounts for <10% of residential addresses in
            // this ZIP — technically valid, but unusual enough to log.
        }

        // $result->knownStates lists all valid states for this ZIP
        // $result->matchedResRatio is the share of addresses in the claimed state
    }
}
```

---

## Key design decisions

**Why type=2 (ZIP→County)?**
The county GEOID's first two digits are the state FIPS code, giving us state
membership per ZIP. Crucially, a ZIP that crosses a state border will appear
in multiple county rows with different FIPS prefixes — this is exactly how we
detect multi-state ZIPs.

**Why `res_ratio` matters**
A ZIP that straddles a state line might have 95% of its addresses in State A
and 5% in State B. Both are technically valid, but a claimed match on the 5%
side is a weaker signal worth noting. The `isMinorityMatch()` helper surfaces this.

**PO Box ZIPs**
HUD explicitly excludes ZIP codes that *only* serve PO Boxes. A `zip_not_found`
result should be treated as inconclusive rather than fraudulent — validate
through a secondary means.

**Staying current**
HUD publishes updates quarterly. The import command is idempotent (skips
existing rows) or can truncate and re-import cleanly. A quarterly cron
(see above) keeps your data fresh.
