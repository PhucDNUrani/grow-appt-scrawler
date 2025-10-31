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
    protected $signature = 'crawl:customers {--limit=100} {--page=1} {--search-word=} {--sort-key=1} {--sort-order=2} {--output=storage/app/public/customers.csv} {--login-url=https://grow-appt.com/shopmaster/api/sign_in} {--loginid} {--password}';

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
                        'access_token', 'accessToken', 'token', 'jwt', 'data',
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

        $fp = @fopen($absoluteOutput, 'w');
        if ($fp === false) {
            $this->error('Unable to open output file for writing: ' . $absoluteOutput);
            return self::FAILURE;
        }

        // Write header
        fputcsv($fp, $columns);

        foreach ($customers as $item) {
            if (!is_array($item)) {
                continue;
            }

            $row = [];
            foreach ($columns as $key) {
                // Try direct key; if not present, try common alternative casings
                $value = $item[$key] ?? $item[strtoupper($key)] ?? $item[ucfirst($key)] ?? '';
                // Normalize scalars
                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                }
                $row[] = $value;
            }

            fputcsv($fp, $row);
        }

        fclose($fp);

        $this->info('CSV exported: ' . $absoluteOutput);
        return self::SUCCESS;
    }
}


