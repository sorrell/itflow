<?php

// Simple .env parser
function parseEnv(string $filePath): array
{
    if (!file_exists($filePath) || !is_readable($filePath)) {
        throw new RuntimeException("Unable to read .env file: {$filePath}");
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $env = [];
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue; // Skip comments
        }
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            // Remove surrounding quotes if any
            if (strlen($value) > 1 && $value[0] === '"' && $value[strlen($value) - 1] === '"') {
                $value = substr($value, 1, -1);
            }
            if (strlen($value) > 1 && $value[0] === '\'' && $value[strlen($value) - 1] === '\'') {
                $value = substr($value, 1, -1);
            }
            $env[$key] = $value;
        }
    }
    return $env;
}

// Function to perform POST request using cURL
function postRequest(string $url, array $postData, string $cookieFile): array
{
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Follow redirects
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile); // Save cookies
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile); // Send cookies
    curl_setopt($ch, CURLOPT_HEADER, true); // Include header in output to check status/location

    // Allow self-signed certs if running locally (common in dev)
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("cURL Error for {$url}: {$error}");
    }

    curl_close($ch);

    // Extract Location header for redirect checking
    $location = '';
    if (preg_match('/^Location:\s*(.*)$/mi', $header, $matches)) {
        $location = trim($matches[1]);
    }


    return [
        'http_code' => $httpCode,
        'location' => $location,
        'header' => $header,
        'body' => $body // Useful for debugging if needed
    ];
}

// --- Main Script ---

echo "Starting ITFlow automated setup...\n";

$envFile = '/var/www/localhost/htdocs/docker/.env';
$cookieFile = __DIR__ . '/setup_cookie.txt';
$configFile = '/var/www/localhost/htdocs/config.php';

// 1. Check if already configured
if (file_exists($configFile)) {
    // Temporarily include config to check the variable
    @include $configFile;
    if (isset($config_enable_setup) && $config_enable_setup === 0) {
        echo "Setup seems to be already completed and locked (config.php exists and \$config_enable_setup = 0).\n";
        echo "Delete config.php to re-run setup.\n";
        exit(0);
    }
    echo "config.php exists but setup is not locked. Proceeding...\n";
    // If config.php exists but setup isn't locked, we might be mid-setup.
    // The script will attempt to continue, potentially overwriting previous steps if needed.
    // It's generally cleaner to delete config.php before running this script.
}

// Clean up old cookie file if it exists
if (file_exists($cookieFile)) {
    unlink($cookieFile);
}

try {
    // 2. Parse .env
    $env = parseEnv($envFile);
    echo "Parsed .env file.\n";

    $baseUrl = "http://localhost:8080/";

    // Construct URLs relative to the potential web root
    $setupUrl = rtrim($baseUrl, '/') . '/setup.php';
    echo "Using base URL: {$baseUrl}\n";
    echo "Setup URL: {$setupUrl}\n";


    // --- Step 1: Database Setup ---
    if (!file_exists($configFile) || !(isset($config_enable_setup) && $config_enable_setup === 0)) {
        echo "Attempting Database setup...\n";
        $dbData = [
            'add_database' => 1,
            'host' => $env['ITFLOW_DB_HOST'] ?? 'itflow-db',
            'database' => $env['ITFLOW_DB_NAME'] ?? 'itflow',
            'username' => $env['ITFLOW_DB_USER'] ?? 'itflow',
            'password' => $env['ITFLOW_DB_PASS'] ?? 'itflow',
        ];
        $response = postRequest($setupUrl . '?database', $dbData, $cookieFile);
        if ($response['http_code'] == 200 && strpos($response['body'], 'Database connection failed') !== false) {
             throw new RuntimeException("Database connection failed. Check credentials/host and ensure DB is running.\nResponse Body:\n" . substr($response['body'], 0, 500) . "...");
        }
        if ($response['http_code'] != 302 && $response['http_code'] != 200) { // Allow 200 if redirect fails but body indicates success implicitly
             throw new RuntimeException("Database setup failed. HTTP Code: {$response['http_code']}.\nHeader:\n{$response['header']}\nBody:\n" . substr($response['body'], 0, 500) . "...");
        }
        // Check if config.php was created
        if (!file_exists($configFile)) {
            throw new RuntimeException("Database setup seemed to run, but config.php was not created. Check web server permissions and logs.\nResponse Body:\n" . substr($response['body'], 0, 500) . "...");
        }
        echo "Database setup request sent. config.php should now exist.\n";
    } else {
         echo "Skipping Database setup (config.php exists and locked).\n";
    }


    // --- Step 2: User Setup ---
     echo "Attempting User setup...\n";
    $userData = [
        'add_user' => 1,
        'name' => $env['ITFLOW_ADMIN_NAME'] ?? 'ITFlow Admin',
        'email' => $env['ITFLOW_ADMIN_EMAIL'] ?? 'admin@example.com',
        // setup.php hashes the password
        'password' => $env['ITFLOW_ADMIN_PASSWORD'] ?? 'password',
    ];
    // Note: File upload for avatar is skipped
    $response = postRequest($setupUrl . '?user', $userData, $cookieFile);
     if ($response['http_code'] != 302 && $response['http_code'] != 200) {
         throw new RuntimeException("User setup failed. HTTP Code: {$response['http_code']}.\nHeader:\n{$response['header']}\nBody:\n" . substr($response['body'], 0, 500) . "...");
     }
    echo "User setup request sent.\n";


    // --- Step 3: Company Setup ---
     echo "Attempting Company setup...\n";
    $companyData = [
        'add_company_settings' => 1,
        'name' => $env['COMPANY_NAME'] ?? 'ITFlow',
        'address' => $env['COMPANY_ADDRESS'] ?? '',
        'city' => $env['COMPANY_CITY'] ?? '',
        'state' => $env['COMPANY_STATE'] ?? '',
        'zip' => $env['COMPANY_ZIP'] ?? '',
        'country' => $env['COMPANY_COUNTRY'] ?? 'United States',
        'phone' => $env['COMPANY_PHONE'] ?? '',
        'email' => $env['COMPANY_EMAIL'] ?? '',
        'website' => $env['COMPANY_WEBSITE'] ?? '',
    ];
    // Note: File upload for logo is skipped
    $response = postRequest($setupUrl . '?company', $companyData, $cookieFile);
     if ($response['http_code'] != 302 && $response['http_code'] != 200) {
         throw new RuntimeException("Company setup failed. HTTP Code: {$response['http_code']}.\nHeader:\n{$response['header']}\nBody:\n" . substr($response['body'], 0, 500) . "...");
     }
    echo "Company setup request sent.\n";

    // --- Step 4: Localization Setup ---
     echo "Attempting Localization setup...\n";
    $localizationData = [
        'add_localization_settings' => 1,
        'locale' => $env['ITFLOW_LOCALE'] ?? 'en_US',
        'currency_code' => $env['ITFLOW_CURRENCY'] ?? 'USD',
        'timezone' => $env['TZ'] ?? 'America/New_York', // Use TZ from .env
    ];
    $response = postRequest($setupUrl . '?localization', $localizationData, $cookieFile);
     if ($response['http_code'] != 302 && $response['http_code'] != 200) {
         throw new RuntimeException("Localization setup failed. HTTP Code: {$response['http_code']}.\nHeader:\n{$response['header']}\nBody:\n" . substr($response['body'], 0, 500) . "...");
     }
    echo "Localization setup request sent.\n";

    // --- Step 5: Telemetry Setup ---
     echo "Attempting Telemetry setup (sharing disabled by default)...\n";
    $telemetryData = [
        'add_telemetry' => 1,
        'share_data' => 0, // Default to not sharing telemetry
        'comments' => 'Automated setup via setup_cli.php',
    ];
    $response = postRequest($setupUrl . '?telemetry', $telemetryData, $cookieFile);
     // Expect redirect to login.php
     if (($response['http_code'] != 302 && $response['http_code'] != 200) || ($response['location'] && !str_contains($response['location'], 'login.php'))) {
         throw new RuntimeException("Telemetry setup failed or did not redirect to login.php. HTTP Code: {$response['http_code']}. Location: {$response['location']}\nHeader:\n{$response['header']}\nBody:\n" . substr($response['body'], 0, 500) . "...");
     }

    // Verify setup lock
    unset($config_enable_setup); // Unset from potential previous include
    if (file_exists($configFile)) {
         include $configFile; // Re-include the potentially modified config
         if (isset($config_enable_setup) && $config_enable_setup === 0) {
             echo "Setup completed successfully and config.php is now locked.\n";
         } else {
              echo "WARNING: Setup process finished, but config.php does not seem to be locked (\$config_enable_setup = 0 is missing or false).\n";
         }
    } else {
         echo "WARNING: Setup process finished, but config.php was not found at the end.\n";
    }


} catch (Exception $e) {
    echo "\nError during setup: " . $e->getMessage() . "\n";
    // Clean up cookie file on error
    if (file_exists($cookieFile)) {
        unlink($cookieFile);
    }
    exit(1);
} finally {
    // Clean up cookie file on success/exit
    if (file_exists($cookieFile)) {
        unlink($cookieFile);
    }
}

echo "Setup script finished.\n";
exit(0);

?> 