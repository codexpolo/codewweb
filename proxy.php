<?php
// proxy.php - Auto trigger version
if (isset($_POST['proxy'])) {
    $data = $_POST['proxy'];
} else {
    http_response_code(400);
    exit('no data');
}

$file = __DIR__ . '/proxy_8080.txt';
file_put_contents($file, $data . PHP_EOL, FILE_APPEND | LOCK_EX);

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