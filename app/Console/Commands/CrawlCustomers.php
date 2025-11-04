<?php

namespace App\Console\Commands;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CrawlCustomers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    // protected $signature = 'crawl:customers {--limit=1000} {--page=1} {--search-word=} {--sort-key=1} {--sort-order=2} {--output=storage/app/public/customers.csv} {--login-url=https://grow-appt.com/shopmaster/api/sign_in} {--loginid} {--password}';
    protected $signature = 'crawl:customers {--limit=1000} {--page=1} {--from-page=1} {--to-page=} {--all} {--sleep-ms=500} {--search-word=} {--sort-key=1} {--sort-order=2} {--output=storage/app/private/customers.csv} {--login-url=https://grow-appt.com/shopmaster/api/sign_in} {--loginid=} {--password=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crawl customers from API and export to a CSV file';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $outputPath = (string) $this->option('output');
        $page = (int) $this->option('page');
        $searchWord = (string) $this->option('search-word');
        $sortKey = (int) $this->option('sort-key');
        $sortOrder = (int) $this->option('sort-order');

        $all = (bool) $this->option('all');
        $fromPage = (int) $this->option('from-page');
        $toPageOpt = $this->option('to-page');
        $toPage = $toPageOpt === null || $toPageOpt === '' ? PHP_INT_MAX : (int) $toPageOpt;
        $sleepMs = (int) $this->option('sleep-ms');

        $api = 'https://grow-appt.com/shopmaster/api/customers';
        $loginUrl = (string) $this->option('login-url');

        $username = (string) ($this->option('loginid') ?: env('GROWAPPT_LOGIN_ID', ''));
        $password = (string) ($this->option('password') ?: env('GROWAPPT_PASSWORD', ''));

        if ($username === '') {
            $username = (string) $this->ask('Enter grow-appt loginid');
        }
        if ($password === '') {
            $password = (string) $this->secret('Enter grow-appt password');
        }

        $this->info("Fetching customers (limit={$limit}, page={$page}, sort_key={$sortKey}, sort_order={$sortOrder})...");

        $cookieJar = new CookieJar();
        $client = new Client([
            'timeout' => 30,
            'cookies' => $cookieJar,
            'allow_redirects' => true,
            'headers' => [
                'Accept' => 'text/html,application/json;q=0.9,*/*;q=0.8',
                'User-Agent' => 'Mozilla/5.0 (compatible; GrowApptCrawler/1.0)'
            ],
        ]);

        // Attempt login against API endpoint using provided credentials, expecting an access token
        $accessToken = null;
        $loginAttempts = [
            ['type' => 'json', 'payload' => ['loginid' => $username, 'password' => $password]],
            ['type' => 'form_params', 'payload' => ['loginid' => $username, 'password' => $password]],
        ];

        foreach ($loginAttempts as $attempt) {
            try {
                $options = [
                    'headers' => [
                        'Accept' => 'application/json, */*',
                        'Origin' => 'https://grow-appt.com',
                        'Referer' => $loginUrl,
                    ],
                ];
                if ($attempt['type'] === 'form_params') {
                    $options['form_params'] = $attempt['payload'];
                } else {
                    $options['json'] = $attempt['payload'];
                }

                $loginResp = $client->post($loginUrl, $options);
                $loginBody = (string) $loginResp->getBody();
                $loginJson = null;
                try {
                    $loginJson = json_decode($loginBody, true, 512, JSON_THROW_ON_ERROR);
                } catch (\Throwable $e) {
                    $loginJson = @json_decode($loginBody, true);
                }

                if (is_array($loginJson)) {
                    // Common token keys
                    $candidates = [
                        'access_token',
                        'accessToken',
                        'token',
                        'jwt',
                        'data',
                    ];
                    foreach ($candidates as $key) {
                        if (!isset($loginJson[$key])) {
                            continue;
                        }
                        $value = $loginJson[$key];
                        if (is_string($value) && $value !== '') {
                            $accessToken = $value;
                            break;
                        }
                        if (is_array($value)) {
                            // nested possibilities like data.access_token
                            if (isset($value['access_token']) && is_string($value['access_token'])) {
                                $accessToken = $value['access_token'];
                                break;
                            }
                            if (isset($value['token']) && is_string($value['token'])) {
                                $accessToken = $value['token'];
                                break;
                            }
                        }
                    }
                }

                if ($accessToken) {
                    break;
                }
            } catch (\Throwable $e) {
                // try next attempt type
            }
        }

        if (!$accessToken) {
            $this->error('Authentication failed: no access_token returned from sign_in API.');
            return self::FAILURE;
        }

        try {
            $response = $client->get($api, [
                'query' => [
                    'limit' => $limit,
                    'page' => $page,
                    'search_word' => $searchWord,
                    'sort_key' => $sortKey,
                    'sort_order' => $sortOrder,
                ],
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $accessToken,
                    'X-Requested-With' => 'XMLHttpRequest',
                ],
            ]);
        } catch (\Throwable $e) {
            $this->error('Failed to request API: ' . $e->getMessage());
            return self::FAILURE;
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $this->error('Unexpected status code: ' . $status);
            return self::FAILURE;
        }

        $body = (string) $response->getBody();
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->error('Failed to parse JSON: ' . $e->getMessage());
            return self::FAILURE;
        }

        // Try to locate the customer list. If API returns a wrapper, infer the likely key.
        $customers = [];
        if (is_array($data)) {
            if (array_is_list($data)) {
                $customers = $data;
            } elseif (isset($data['data']) && is_array($data['data'])) {
                $customers = $data['data'];
            } elseif (isset($data['customers']) && is_array($data['customers'])) {
                $customers = $data['customers'];
            } else {
                // Fallback: treat root as one record if keyed by fields
                $customers = [$data];
            }
        }

        if (empty($customers)) {
            $this->warn('No customers found in API response. A CSV with only headers will be generated.');
        }

        $columns = [
            'customer_name',
            'email',
            'kana',
            'memo',
            'ng',
            'no',
            'registdatetime',
            'salon_name',
            'staffng',
            'stamp',
            'status',
            'tel',
            'type',
        ];

        // Ensure output directory exists
        $absoluteOutput = base_path(trim($outputPath));
        $timestamp = now()->format('Ymd_His');
        $dir = dirname($absoluteOutput);
        $base = pathinfo($absoluteOutput, PATHINFO_FILENAME);
        $ext = pathinfo($absoluteOutput, PATHINFO_EXTENSION);
        if ($ext === '') {
            $ext = 'csv';
        }
        $absoluteOutput = $dir . '/' . $base . '_' . $timestamp . '.' . $ext;
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                $this->error('Failed to create directory: ' . $dir);
                return self::FAILURE;
            }
        }

        // Prepare in-memory collectors for duplicate detection by tel
        $allRows = [];
        $telCounts = [];
        $normalizeTel = function ($tel) {
            return preg_replace('/\D+/', '', (string) $tel);
        };

        // Helper to extract customers array from any response shape
        $extractCustomers = function ($data) {
            if (is_array($data)) {
                if (array_is_list($data)) {
                    return $data;
                }
                if (isset($data['data']) && is_array($data['data'])) {
                    return $data['data'];
                }
                if (isset($data['customers']) && is_array($data['customers'])) {
                    return $data['customers'];
                }
            }
            return [];
        };

        $totalWritten = 0;

        // Determine page range
        $startPage = $all ? max(1, $fromPage) : (int) $this->option('page');
        $endPage = $all ? $toPage : $startPage;

        for ($page = $startPage; $page <= $endPage; $page++) {
            $this->info("Fetching page {$page} ...");

            try {
                $response = $client->get($api, [
                    'query' => [
                        'limit' => $limit,
                        'page' => $page,
                        'search_word' => $searchWord,
                        'sort_key' => $sortKey,
                        'sort_order' => $sortOrder,
                    ],
                    'headers' => [
                        'Accept' => 'application/json',
                        'Authorization' => 'Bearer ' . $accessToken,
                        'X-Requested-With' => 'XMLHttpRequest',
                    ],
                ]);
            } catch (\Throwable $e) {
                $this->error('Failed to request API on page ' . $page . ': ' . $e->getMessage());
                break;
            }

            if ($response->getStatusCode() !== 200) {
                $this->warn('Non-200 status on page ' . $page . ': ' . $response->getStatusCode());
                break;
            }

            $body = (string) $response->getBody();
            try {
                $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                $this->error('Failed to parse JSON on page ' . $page . ': ' . $e->getMessage());
                break;
            }

            $customers = $extractCustomers($data);

            // Stop if no more data
            if (empty($customers)) {
                if ($all) {
                    $this->info('No data returned; stopping at page ' . $page . '.');
                }
                break;
            }

            // Collect rows (accumulate across pages)
            foreach ($customers as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $row = [];
                foreach ($columns as $key) {
                    $value = $item[$key] ?? $item[strtoupper($key)] ?? $item[ucfirst($key)] ?? '';
                    if (is_array($value) || is_object($value)) {
                        $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                    }
                    $row[] = $value;
                }
                $telIndex = array_search('tel', $columns, true);
                $telRaw = $telIndex !== false ? ($row[$telIndex] ?? '') : '';
                $telNorm = $normalizeTel($telRaw);

                if ($telNorm !== '') {
                    $telCounts[$telNorm] = ($telCounts[$telNorm] ?? 0) + 1;
                }

                $allRows[] = [$row, $telNorm];
                $totalWritten++;
            }

            // Respectful pacing
            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }
        // Write duplicates and unique CSVs
        $dupesPath = $dir . '/' . $base . '_dupes_' . $timestamp . '.' . $ext;
        $uniquePath = $dir . '/' . $base . '_unique_' . $timestamp . '.' . $ext;

        $dupesFp = @fopen($dupesPath, 'w');
        if ($dupesFp === false) {
            $this->error('Unable to open duplicates file: ' . $dupesPath);
            return self::FAILURE;
        }
        $uniqueFp = @fopen($uniquePath, 'w');
        if ($uniqueFp === false) {
            fclose($dupesFp);
            $this->error('Unable to open unique file: ' . $uniquePath);
            return self::FAILURE;
        }

        fputcsv($dupesFp, $columns);
        fputcsv($uniqueFp, $columns);

        // Build groups for duplicates by normalized tel
        $dupeGroups = [];
        $uniqueRows = [];
        foreach ($allRows as [$row, $telNorm]) {
            if ($telNorm !== '' && ($telCounts[$telNorm] ?? 0) > 1) {
                if (!isset($dupeGroups[$telNorm])) {
                    $dupeGroups[$telNorm] = [];
                }
                $dupeGroups[$telNorm][] = $row;
            } else {
                $uniqueRows[] = $row;
            }
        }

        // Write duplicates grouped by tel, with a blank line between groups
        ksort($dupeGroups, SORT_STRING);
        $dupesCount = 0;
        $firstGroup = true;
        foreach ($dupeGroups as $telNorm => $rows) {
            if (!$firstGroup) {
                fputcsv($dupesFp, array_fill(0, count($columns), ''));
            }
            $firstGroup = false;
            foreach ($rows as $row) {
                fputcsv($dupesFp, $row);
                $dupesCount++;
            }
        }

        // Write unique rows
        $uniqueCount = 0;
        foreach ($uniqueRows as $row) {
            fputcsv($uniqueFp, $row);
            $uniqueCount++;
        }

        fclose($dupesFp);
        fclose($uniqueFp);

        $this->info('Total rows processed: ' . $totalWritten);
        $this->info('Duplicates CSV: ' . $dupesPath . ' (' . $dupesCount . ' rows)');
        $this->info('Unique CSV: ' . $uniquePath . ' (' . $uniqueCount . ' rows)');
        return self::SUCCESS;
    }
}
