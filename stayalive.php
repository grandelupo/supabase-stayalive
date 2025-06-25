<?php
#!/usr/bin/env php

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;

class SupabaseStayAlive
{
    private $databases = [];
    private $results = [];
    private $httpClient;

    public function __construct()
    {
        $this->loadEnvironment();
        $this->databases = $this->loadDatabaseConfigs();
        $this->httpClient = new Client([
            'timeout' => 30,
            'connect_timeout' => 10
        ]);
        $this->results = [];
    }

    private function loadEnvironment()
    {
        if (file_exists(__DIR__ . '/.env')) {
            $dotenv = Dotenv::createImmutable(__DIR__);
            $dotenv->load();
        }
    }

    private function loadDatabaseConfigs()
    {
        $databases = [];
        $index = 1;

        // Load database configs from environment variables
        // Format: DB1_URL, DB1_ANON_KEY, DB2_URL, DB2_ANON_KEY, etc.
        while (isset($_ENV["DB{$index}_URL"]) || getenv("DB{$index}_URL")) {
            $url = $_ENV["DB{$index}_URL"] ?? getenv("DB{$index}_URL");
            $anonKey = $_ENV["DB{$index}_ANON_KEY"] ?? getenv("DB{$index}_ANON_KEY");
            $name = $_ENV["DB{$index}_NAME"] ?? getenv("DB{$index}_NAME") ?? "Database {$index}";

            if (empty($url) || empty($anonKey)) {
                echo "⚠️  Missing configuration for DB{$index} - skipping\n";
                $index++;
                continue;
            }

            $databases[] = [
                'name' => $name,
                'url' => $url,
                'anon_key' => $anonKey,
                'index' => $index
            ];

            $index++;
        }

        return $databases;
    }

    private function pingDatabase($config)
    {
        $name = $config['name'];
        $url = $config['url'];
        $anonKey = $config['anon_key'];

        echo "🏓 Pinging {$name}...\n";

        try {
            // Try multiple endpoints to ensure database activity
            $endpoints = [
                '/rest/v1/_realtime_schema_version?select=*&limit=1',
                '/rest/v1/rpc/version',
                '/auth/v1/settings'
            ];

            $success = false;
            $lastError = null;

            foreach ($endpoints as $endpoint) {
                try {
                    $response = $this->httpClient->get($url . $endpoint, [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $anonKey,
                            'apikey' => $anonKey,
                            'Content-Type' => 'application/json'
                        ]
                    ]);

                    if ($response->getStatusCode() < 300) {
                        $success = true;
                        break;
                    }
                } catch (RequestException $e) {
                    $lastError = $e->getMessage();
                    // Try next endpoint
                    continue;
                }
            }

            if ($success) {
                $this->results[] = [
                    'database' => $name,
                    'status' => 'success',
                    'timestamp' => date('c')
                ];

                echo "✅ {$name} - Connection successful\n";
                return true;
            } else {
                throw new Exception($lastError ?? 'All endpoints failed');
            }

        } catch (Exception $e) {
            $this->results[] = [
                'database' => $name,
                'status' => 'error',
                'error' => $e->getMessage(),
                'timestamp' => date('c')
            ];

            echo "❌ {$name} - Connection failed: " . $e->getMessage() . "\n";
            return false;
        }
    }

    public function run()
    {
        echo "🚀 Supabase Stay Alive Script Started\n";
        echo "📊 Found " . count($this->databases) . " database(s) to ping\n";
        echo "⏰ Timestamp: " . date('c') . "\n\n";

        if (empty($this->databases)) {
            echo "❌ No databases configured. Please check your .env file.\n";
            exit(1);
        }

        // Try concurrent requests with Pool, fallback to sequential if not available
        try {
            if (class_exists('GuzzleHttp\Pool')) {
                $this->pingDatabasesConcurrent();
            } else {
                throw new Exception('Pool class not available');
            }
        } catch (Exception $e) {
            echo "⚠️  Falling back to sequential requests: " . $e->getMessage() . "\n";
            $this->pingDatabasesSequential();
        }

        // Summary
        $successful = count(array_filter($this->results, function($r) {
            return $r['status'] === 'success';
        }));
        $failed = count(array_filter($this->results, function($r) {
            return $r['status'] === 'error';
        }));

        echo "\n📈 Summary:\n";
        echo "✅ Successful: {$successful}\n";
        echo "❌ Failed: {$failed}\n";
        echo "🔄 Total: " . count($this->results) . "\n";

        if ($failed > 0) {
            echo "\n❌ Failed databases:\n";
            foreach ($this->results as $result) {
                if ($result['status'] === 'error') {
                    echo "   - {$result['database']}: {$result['error']}\n";
                }
            }
            exit(1);
        }

        echo "\n🎉 All databases pinged successfully!\n";
    }

    private function pingDatabasesConcurrent()
    {
        $requests = [];
        foreach ($this->databases as $index => $config) {
            $name = $config['name'];
            $url = $config['url'];
            $anonKey = $config['anon_key'];

            echo "🏓 Pinging {$name}...\n";

            // Try the most reliable endpoint first
            $endpoint = '/rest/v1/_realtime_schema_version?select=*&limit=1';
            
            $requests[$index] = new Request('GET', $url . $endpoint, [
                'Authorization' => 'Bearer ' . $anonKey,
                'apikey' => $anonKey,
                'Content-Type' => 'application/json'
            ]);
        }

        $pool = new Pool($this->httpClient, $requests, [
            'concurrency' => 5,
            'fulfilled' => function ($response, $index) {
                $config = $this->databases[$index];
                $this->handleSuccessfulResponse($config, $response);
            },
            'rejected' => function ($reason, $index) {
                $config = $this->databases[$index];
                $this->handleFailedResponse($config, $reason);
            }
        ]);

        $promise = $pool->promise();
        $promise->wait();
    }

    private function pingDatabasesSequential()
    {
        foreach ($this->databases as $config) {
            $this->pingDatabase($config);
        }
    }

    private function handleSuccessfulResponse($config, $response)
    {
        $name = $config['name'];
        
        $this->results[] = [
            'database' => $name,
            'status' => 'success',
            'timestamp' => date('c')
        ];

        echo "✅ {$name} - Connection successful\n";
    }

    private function handleFailedResponse($config, $reason)
    {
        $name = $config['name'];
        $error = $reason instanceof Exception ? $reason->getMessage() : (string) $reason;
        
        $this->results[] = [
            'database' => $name,
            'status' => 'error',
            'error' => $error,
            'timestamp' => date('c')
        ];

        echo "❌ {$name} - Connection failed: {$error}\n";
    }
}

// Handle uncaught errors
set_exception_handler(function($exception) {
    echo "💥 Uncaught Exception: " . $exception->getMessage() . "\n";
    exit(1);
});

set_error_handler(function($severity, $message, $file, $line) {
    echo "💥 Error: {$message} in {$file} on line {$line}\n";
    exit(1);
});

// Run the script
if (php_sapi_name() === 'cli' && isset($argv[0]) && basename($argv[0]) === 'stayalive.php') {
    try {
        $stayAlive = new SupabaseStayAlive();
        $stayAlive->run();
    } catch (Exception $e) {
        echo "💥 Script failed: " . $e->getMessage() . "\n";
        exit(1);
    }
} 