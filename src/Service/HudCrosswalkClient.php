<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin wrapper around the HUD USER USPS Crosswalk API.
 *
 * API docs: https://www.huduser.gov/portal/dataset/uspszip-api.html
 *
 * Register for a free token at: https://www.huduser.gov/hudapi/public/login
 * Add to your .env:  HUD_API_TOKEN=your_token_here
 */
class HudCrosswalkClient
{
    private const BASE_URL = 'https://www.huduser.gov/hudapi/public/usps';

    /**
     * type=2 → ZIP to County crosswalk.
     * Each result row contains a county GEOID whose first 2 chars are the state FIPS code,
     * which we use to derive the state. This is the most useful type for our purpose because
     * a single ZIP can appear in multiple county rows across different states.
     */
    private const TYPE_ZIP_TO_COUNTY = 2;

    /** All US state abbreviations + DC + PR that HUD recognises as query values */
    public const ALL_STATE_QUERIES = [
        'AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA',
        'HI','ID','IL','IN','IA','KS','KY','LA','ME','MD',
        'MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ',
        'NM','NY','NC','ND','OH','OK','OR','PA','RI','SC',
        'SD','TN','TX','UT','VT','VA','WA','WV','WI','WY',
        'DC','PR',
    ];

    /** Maps 2-digit state FIPS → 2-letter abbreviation */
    private const FIPS_TO_ABBR = [
        '01'=>'AL','02'=>'AK','04'=>'AZ','05'=>'AR','06'=>'CA',
        '08'=>'CO','09'=>'CT','10'=>'DE','11'=>'DC','12'=>'FL',
        '13'=>'GA','15'=>'HI','16'=>'ID','17'=>'IL','18'=>'IN',
        '19'=>'IA','20'=>'KS','21'=>'KY','22'=>'LA','23'=>'ME',
        '24'=>'MD','25'=>'MA','26'=>'MI','27'=>'MN','28'=>'MS',
        '29'=>'MO','30'=>'MT','31'=>'NE','32'=>'NV','33'=>'NH',
        '34'=>'NJ','35'=>'NM','36'=>'NY','37'=>'NC','38'=>'ND',
        '39'=>'OH','40'=>'OK','41'=>'OR','42'=>'PA','44'=>'RI',
        '45'=>'SC','46'=>'SD','47'=>'TN','48'=>'TX','49'=>'UT',
        '50'=>'VT','51'=>'VA','53'=>'WA','54'=>'WV','55'=>'WI',
        '56'=>'WY','72'=>'PR',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[\SensitiveParameter]
        private readonly string $apiToken,
    ) {}

    /**
     * Fetches all ZIP→county rows for a given state abbreviation from the HUD API.
     *
     * Returns an array of normalised rows:
     * [
     *   'zip'       => '12345',
     *   'state_abbr' => 'NY',
     *   'state_fips' => '36',
     *   'res_ratio'  => '0.850000000',
     * ]
     *
     * @return array<int, array{zip: string, state_abbr: string, state_fips: string, res_ratio: string}>
     * @throws \RuntimeException on HTTP or parse error
     */
    public function fetchZipsForState(string $stateAbbr): array
    {
        $this->logger->debug('HUD API: fetching ZIP→county for state', ['state' => $stateAbbr]);

        $response = $this->httpClient->request('GET', self::BASE_URL, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiToken,
                'Accept'        => 'application/json',
            ],
            'query' => [
                'type'  => self::TYPE_ZIP_TO_COUNTY,
                'query' => $stateAbbr,
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(sprintf(
                'HUD API returned HTTP %d for state %s',
                $response->getStatusCode(),
                $stateAbbr,
            ));
        }

        $body = $response->toArray();
        $results = $body['data']['results'] ?? [];

        if (empty($results)) {
            $this->logger->warning('HUD API: empty results for state', ['state' => $stateAbbr]);
            return [];
        }

        $rows = [];
        foreach ($results as $row) {
            $geoid     = (string) ($row['geoid'] ?? '');
            $stateFips = substr($geoid, 0, 2);
            $stateAbbrResolved = self::FIPS_TO_ABBR[$stateFips] ?? null;

            if ($stateAbbrResolved === null) {
                $this->logger->warning('Unknown FIPS code in HUD result', [
                    'geoid' => $geoid,
                    'state_query' => $stateAbbr,
                ]);
                continue;
            }

            $rows[] = [
                'zip'        => str_pad((string) ($row['zip'] ?? $row['input'] ?? ''), 5, '0', STR_PAD_LEFT),
                'state_abbr' => $stateAbbrResolved,
                'state_fips' => $stateFips,
                'res_ratio'  => number_format((float) ($row['res_ratio'] ?? 0), 9, '.', ''),
            ];
        }

        $this->logger->info('HUD API: fetched rows', ['state' => $stateAbbr, 'count' => count($rows)]);

        return $rows;
    }

    public static function fipsToAbbr(string $fips): ?string
    {
        return self::FIPS_TO_ABBR[$fips] ?? null;
    }
}
