<?php
#!/usr/bin/env php

/**
 * Supabase Stay Alive - Simple Version
 * No Composer dependencies required - uses only built-in PHP functions
 */

class SupabaseStayAliveSimple
{
    private $databases = [];
    private $results = [];

    public function __construct()
    {
        $this->loadEnvironment();
        $this->databases = $this->loadDatabaseConfigs();
        $this->results = [];
    }

    private function loadEnvironment()
    {
        $envFile = __DIR__ . '/.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
                    continue;
                }
                
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                // Remove quotes if present
                if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
                    (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
                    $value = substr($value, 1, -1);
                }
                
                $_ENV[$key] = $value;
                putenv("$key=$value");
            }
        }
    }

    private function loadDatabaseConfigs()
    {
        $databases = [];
        $index = 1;

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
                'url' => rtrim($url, '/'),
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

        // Try multiple endpoints to ensure database activity
        $endpoints = [
            '/rest/v1/_realtime_schema_version?select=*&limit=1',
            '/auth/v1/settings',
            '/rest/v1/'
        ];

        $success = false;
        $lastError = null;

        foreach ($endpoints as $endpoint) {
            $ch = curl_init();
            
            curl_setopt_array($ch, [
                CURLOPT_URL => $url . $endpoint,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $anonKey,
                    'apikey: ' . $anonKey,
                    'Content-Type: application/json'
                ],
                CURLOPT_USERAGENT => 'SupabaseStayAlive/1.0',
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response !== false && $httpCode < 300) {
                $success = true;
                break;
            } else {
                $lastError = $error ?: "HTTP {$httpCode}";
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
            $this->results[] = [
                'database' => $name,
                'status' => 'error',
                'error' => $lastError ?? 'All endpoints failed',
                'timestamp' => date('c')
            ];

            echo "❌ {$name} - Connection failed: " . ($lastError ?? 'All endpoints failed') . "\n";
            return false;
        }
    }

    public function run()
    {
        echo "🚀 Supabase Stay Alive Script Started (Simple Version)\n";
        echo "📊 Found " . count($this->databases) . " database(s) to ping\n";
        echo "⏰ Timestamp: " . date('c') . "\n\n";

        if (empty($this->databases)) {
            echo "❌ No databases configured. Please check your .env file.\n";
            exit(1);
        }

        // Check if cURL is available
        if (!function_exists('curl_init')) {
            echo "❌ cURL extension is required but not available.\n";
            exit(1);
        }

        // Ping each database
        foreach ($this->databases as $config) {
            $this->pingDatabase($config);
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
}

// Handle uncaught errors
set_exception_handler(function($exception) {
    echo "💥 Uncaught Exception: " . $exception->getMessage() . "\n";
    exit(1);
});

set_error_handler(function($severity, $message, $file, $line) {
    if (error_reporting() & $severity) {
        echo "💥 Error: {$message} in {$file} on line {$line}\n";
        exit(1);
    }
});

// Run the script
if (php_sapi_name() === 'cli' && isset($argv[0]) && basename($argv[0]) === 'stayalive-simple.php') {
    try {
        $stayAlive = new SupabaseStayAliveSimple();
        $stayAlive->run();
    } catch (Exception $e) {
        echo "💥 Script failed: " . $e->getMessage() . "\n";
        exit(1);
    }
} 