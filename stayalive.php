<?php
#!/usr/bin/env php

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise;

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

        // Create promises for concurrent requests
        $promises = [];
        foreach ($this->databases as $config) {
            $promises[] = $this->createPingPromise($config);
        }

        // Wait for all requests to complete
        try {
            Promise\settle($promises)->wait();
        } catch (Exception $e) {
            echo "⚠️  Some requests failed: " . $e->getMessage() . "\n";
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

    private function createPingPromise($config)
    {
        return Promise\coroutine(function() use ($config) {
            return $this->pingDatabase($config);
        });
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