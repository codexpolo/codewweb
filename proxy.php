<?php
// proxy.php - Auto trigger version

function isValidProxy(string $proxy): bool
{
    if ($proxy === '') {
        return false;
    }

    $parts = explode(':', $proxy, 2);
    if (count($parts) !== 2) {
        return false;
    }

    [$host, $port] = array_map('trim', $parts);

    if ($host === '' || $port === '') {
        return false;
    }

    if (!ctype_digit($port)) {
        return false;
    }

    $portNumber = (int) $port;
    if ($portNumber < 1 || $portNumber > 65535) {
        return false;
    }

    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6)) {
        return true;
    }

    return (bool) filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
}

if (!isset($_POST['proxy'])) {
    http_response_code(400);
    exit('no data');
}

$rawInput = $_POST['proxy'];
$rawItems = [];

if (is_array($rawInput)) {
    $rawItems = $rawInput;
} else {
    $rawItems = preg_split('/[\r\n]+/', (string) $rawInput);
}

$validProxies = [];

foreach ($rawItems as $item) {
    $proxy = trim((string) $item);

    if ($proxy === '') {
        continue;
    }

    if (isValidProxy($proxy)) {
        $validProxies[] = $proxy;
    }
}

if (empty($validProxies)) {
    http_response_code(400);
    exit('invalid proxy format');
}

$file = __DIR__ . '/proxy_8080.txt';
file_put_contents($file, implode(PHP_EOL, $validProxies) . PHP_EOL, FILE_APPEND | LOCK_EX);

// ✅ TỰ ĐỘNG TRIGGER PROXY CHECKER NGAY LẬP TỨC
try {
    // Gọi proxy_checker.php trong background
    $checkerScript = __DIR__ . '/proxy_checker.php';
    
    if (file_exists($checkerScript)) {
        // Chạy trong background không block response
        if (function_exists('exec')) {
            exec("php $checkerScript > /dev/null 2>&1 &");
        } else {
            // Fallback: gọi bằng file_get_contents
            $context = stream_context_create([
                'http' => [
                    'timeout' => 1, // Timeout nhanh
                    'ignore_errors' => true
                ]
            ]);
            @file_get_contents("http://{$_SERVER['HTTP_HOST']}/proxy_checker.php?action=check", false, $context);
        }
    }
} catch (Exception $e) {
    // Ignore errors, vẫn trả về success
}

echo 'ok';
?>