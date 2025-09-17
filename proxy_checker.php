<?php
class ProxyChecker {
    private $proxyFile = 'proxy_8080.txt';
    private $queueFile = 'start_check.txt';
    private $checkedFile = 'checked_proxies.txt';
    private $resultFile = 'proxy_info.json';
    private $logFile = 'proxy_checker.log';
    
    public function __construct() {
        // Tạo các file cần thiết nếu chưa tồn tại
        $this->initializeFiles();
    }
    
    private function initializeFiles() {
        if (!file_exists($this->queueFile)) {
            file_put_contents($this->queueFile, '');
        }
        if (!file_exists($this->checkedFile)) {
            file_put_contents($this->checkedFile, '');
        }
        if (!file_exists($this->resultFile)) {
            file_put_contents($this->resultFile, '[]');
        }
        if (!file_exists($this->logFile)) {
            file_put_contents($this->logFile, '');
        }
    }
    
    public function run() {
        $this->log("Starting proxy checker...");
        
        // Bước 1: Chuyển proxy mới từ proxy_8080.txt sang start_check.txt
        $this->moveNewProxiesToQueue();
        
        // Bước 2: Xử lý queue - check từng proxy một
        $this->processQueue();
        
        $this->log("Proxy checking completed.");
    }
    
    /**
     * FIXED: Auto-monitoring - Chỉ chuyển proxy THỰC SỰ MỚI vào queue
     */
    private function moveNewProxiesToQueue() {
        if (!file_exists($this->proxyFile)) {
            $this->log("Proxy file not found: $this->proxyFile");
            return;
        }
        
        // Đọc tất cả proxy từ file chính
        $allProxies = file($this->proxyFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        // FIXED: Lấy danh sách proxy đã check
        $checkedProxies = $this->getCheckedProxies();
        
        // FIXED: Lấy danh sách proxy đang trong queue
        $queueProxies = $this->getQueueProxies();
        
        // FIXED: Lọc proxy thực sự mới (chưa check VÀ chưa có trong queue)
        $newProxies = [];
        foreach ($allProxies as $proxy) {
            $proxy = trim($proxy);
            if (empty($proxy)) continue;
            
            // FIXED: Kiểm tra cả 2 điều kiện
            if (!in_array($proxy, $checkedProxies) && !in_array($proxy, $queueProxies)) {
                $newProxies[] = $proxy;
            }
        }
        
        if (empty($newProxies)) {
            $this->log("No new proxies found to add to queue.");
            return;
        }
        
        // Thêm proxy mới vào queue
        foreach ($newProxies as $proxy) {
            file_put_contents($this->queueFile, $proxy . "\n", FILE_APPEND);
        }
        
        $this->log("Added " . count($newProxies) . " new proxies to queue.");
    }
    
    /**
     * FIXED: Lấy danh sách proxy trong queue
     */
    private function getQueueProxies() {
        if (!file_exists($this->queueFile)) {
            return [];
        }
        
        $queueProxies = file($this->queueFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return array_map('trim', array_filter($queueProxies));
    }
    
    /**
     * FIXED: Kiểm tra proxy đã có trong queue chưa
     */
    private function isProxyInQueue($proxy) {
        $queueProxies = $this->getQueueProxies();
        return in_array(trim($proxy), $queueProxies);
    }
    
    /**
     * FIXED: Lấy danh sách proxy đã check (từ checked_proxies.txt)
     */
    private function getCheckedProxies() {
        if (!file_exists($this->checkedFile)) {
            return [];
        }
        
        $checkedProxies = file($this->checkedFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return array_map('trim', array_filter($checkedProxies));
    }
    
    /**
     * FIXED: Xử lý queue với check duplicate
     */
    private function processQueue() {
        if (!file_exists($this->queueFile)) {
            $this->log("Queue file not found.");
            return;
        }
        
        $queueProxies = file($this->queueFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        if (empty($queueProxies)) {
            $this->log("Queue is empty.");
            return;
        }
        
        // FIXED: Lọc proxy trùng lặp trong queue
        $queueProxies = array_unique(array_map('trim', $queueProxies));
        
        $this->log("Processing " . count($queueProxies) . " unique proxies from queue.");
        
        $results = $this->loadResults();
        $checkedProxies = $this->getCheckedProxies();
        $processedCount = 0;
        
        foreach ($queueProxies as $proxy) {
            $proxy = trim($proxy);
            if (empty($proxy)) continue;
            
            // FIXED: Skip nếu proxy đã được check
            if (in_array($proxy, $checkedProxies)) {
                $this->log("Skipping already checked proxy: $proxy");
                $this->removeProxyFromQueue($proxy);
                continue;
            }
            
            $this->log("Processing proxy from queue: $proxy");
            
            // Extract IP từ proxy
            $ip = $this->extractIPFromProxy($proxy);
            
            if (!$ip) {
                $this->log("Invalid proxy format: $proxy");
                $this->removeProxyFromQueue($proxy);
                continue;
            }
            
            $this->log("Extracted IP: $ip from proxy: $proxy");
            
            // Check thông tin IP với API
            $info = $this->checkIPInfo($ip);
            
            if ($info) {
                $proxyData = [
                    'proxy' => $proxy,
                    'ip' => $ip,
                    'country' => $info['country'] ?? '',
                    'city' => $info['city'] ?? '',
                    'mobile' => $info['mobile'] ?? false,
                    'proxy_detected' => $info['proxy'] ?? false,
                    'hosting' => $info['hosting'] ?? false,
                    'isp' => $info['isp'] ?? '',
                    'checked_at' => date('Y-m-d H:i:s'),
                    'status' => 'success'
                ];
                
                $results[] = $proxyData;
                $this->log("Successfully checked: $proxy - IP: $ip - {$info['country']}, {$info['city']} - ISP: {$info['isp']}");
            } else {
                $proxyData = [
                    'proxy' => $proxy,
                    'ip' => $ip,
                    'checked_at' => date('Y-m-d H:i:s'),
                    'status' => 'failed'
                ];
                
                $results[] = $proxyData;
                $this->log("Failed to check: $proxy - IP: $ip");
            }
            
            // FIXED: Thêm vào danh sách đã check TRƯỚC KHI xóa khỏi queue
            file_put_contents($this->checkedFile, $proxy . "\n", FILE_APPEND);
            
            // Xóa proxy khỏi queue sau khi xử lý xong
            $this->removeProxyFromQueue($proxy);
            
            $processedCount++;
            
            // Rate limiting: delay 1.5 giây giữa các request (40 req/min)
            if ($processedCount < count($queueProxies)) {
                $this->log("Rate limiting: sleeping 1.5 seconds (using usleep)...");
                usleep(1500000);
            }
        }
        
        // Lưu kết quả
        $this->saveResults($results);
        $this->log("Processed $processedCount proxies from queue.");
    }
    
    /**
     * FIXED: Xóa proxy khỏi queue file an toàn hơn
     */
    private function removeProxyFromQueue($proxyToRemove) {
        if (!file_exists($this->queueFile)) {
            return;
        }
        
        $queueProxies = file($this->queueFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $updatedQueue = [];
        
        foreach ($queueProxies as $proxy) {
            $proxy = trim($proxy);
            if (!empty($proxy) && $proxy !== trim($proxyToRemove)) {
                $updatedQueue[] = $proxy;
            }
        }
        
        // FIXED: Xóa duplicate trong queue
        $updatedQueue = array_unique($updatedQueue);
        
        // Ghi lại queue đã cập nhật
        file_put_contents($this->queueFile, implode("\n", $updatedQueue) . (empty($updatedQueue) ? "" : "\n"));
        $this->log("Removed proxy from queue: $proxyToRemove");
    }
    
    /**
     * Extract IP từ proxy format ip:port
     */
    private function extractIPFromProxy($proxy) {
        $proxy = trim($proxy);
        
        if (strpos($proxy, ':') !== false) {
            $parts = explode(':', $proxy);
            $ip = trim($parts[0]);
            
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        
        if (filter_var($proxy, FILTER_VALIDATE_IP)) {
            return $proxy;
        }
        
        return false;
    }
    
    /**
     * Check thông tin IP qua ip-api.com với fields mới
     */
    private function checkIPInfo($ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->log("Invalid IP format: $ip");
            return false;
        }
        
        // API URL với fields mới: query, country, city, isp, mobile, proxy, hosting
        $url = "http://ip-api.com/json/$ip?fields=query,country,city,isp,mobile,proxy,hosting";
        
        $this->log("Checking IP info for: $ip - URL: $url");
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            $this->log("CURL Error for IP $ip: $error");
            return false;
        }
        
        if ($httpCode !== 200) {
            $this->log("HTTP Error for IP $ip: HTTP $httpCode");
            return false;
        }
        
        if (!$response) {
            $this->log("Empty response for IP $ip");
            return false;
        }
        
        $this->log("Raw response for IP $ip: " . substr($response, 0, 500));
        
        $data = json_decode($response, true);
        
        if (!$data) {
            $this->log("JSON decode error for IP $ip: " . json_last_error_msg());
            return false;
        }
        
        // Kiểm tra response format (ip-api.com không trả về status field khi thành công)
        if (isset($data['query']) && $data['query'] === $ip) {
            $this->log("Successfully got info for IP $ip: " . json_encode($data));
            return $data;
        } else {
            $this->log("API returned error for IP $ip: " . json_encode($data));
            return false;
        }
    }
    
    private function loadResults() {
        $content = file_get_contents($this->resultFile);
        return json_decode($content, true) ?: [];
    }
    
    private function saveResults($results) {
        $json = json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($this->resultFile, $json);
        $this->log("Saved results to $this->resultFile");
    }
    
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] $message\n";
        file_put_contents($this->logFile, $logEntry, FILE_APPEND);
        echo $logEntry;
    }
    
    // API endpoint để lấy thông tin proxy
    public function getProxyInfo($limit = 100) {
        $results = $this->loadResults();
        $results = array_slice($results, -$limit);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'count' => count($results),
            'data' => $results
        ], JSON_UNESCAPED_UNICODE);
    }
    
    // API endpoint để lấy statistics
    public function getStats() {
        $results = $this->loadResults();
        
        // Đếm proxy trong queue
        $queueCount = 0;
        if (file_exists($this->queueFile)) {
            $queueProxies = file($this->queueFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $queueCount = count(array_filter($queueProxies, function($proxy) {
                return !empty(trim($proxy));
            }));
        }
        
        $stats = [
            'total_proxies' => count($results),
            'successful_checks' => 0,
            'failed_checks' => 0,
            'queue_count' => $queueCount,
            'countries' => [],
            'mobile_proxies' => 0,
            'hosting_proxies' => 0,
            'proxy_detected' => 0,
            'latest_check' => ''
        ];
        
        foreach ($results as $result) {
            if ($result['status'] == 'success') {
                $stats['successful_checks']++;
                
                if (!empty($result['country'])) {
                    $stats['countries'][$result['country']] = ($stats['countries'][$result['country']] ?? 0) + 1;
                }
                
                if ($result['mobile']) $stats['mobile_proxies']++;
                if ($result['hosting']) $stats['hosting_proxies']++;
                if ($result['proxy_detected']) $stats['proxy_detected']++;
            } else {
                $stats['failed_checks']++;
            }
            
            if (empty($stats['latest_check']) || $result['checked_at'] > $stats['latest_check']) {
                $stats['latest_check'] = $result['checked_at'];
            }
        }
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'stats' => $stats
        ], JSON_UNESCAPED_UNICODE);
    }
    
    // Test một IP cụ thể
    public function testIP($ip) {
        $this->log("Testing IP: $ip");
        
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            echo json_encode(['error' => 'Invalid IP format']);
            return;
        }
        
        $info = $this->checkIPInfo($ip);
        
        header('Content-Type: application/json');
        if ($info) {
            echo json_encode([
                'success' => true,
                'ip' => $ip,
                'data' => $info
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode([
                'success' => false,
                'ip' => $ip,
                'error' => 'Failed to get IP info'
            ], JSON_UNESCAPED_UNICODE);
        }
    }
    
    // API để lấy thông tin queue
    public function getQueueInfo() {
        $queueProxies = [];
        if (file_exists($this->queueFile)) {
            $queueProxies = file($this->queueFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $queueProxies = array_filter(array_map('trim', $queueProxies));
        }
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'queue_count' => count($queueProxies),
            'queue_data' => array_values($queueProxies)
        ], JSON_UNESCAPED_UNICODE);
    }
    
    /**
     * FIXED: Thêm method để clear duplicates
     */
    public function clearDuplicates() {
        $this->log("Starting duplicate cleanup...");
        
        // Clear duplicates in queue
        if (file_exists($this->queueFile)) {
            $queueProxies = file($this->queueFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $uniqueQueue = array_unique(array_map('trim', array_filter($queueProxies)));
            file_put_contents($this->queueFile, implode("\n", $uniqueQueue) . "\n");
            $this->log("Cleaned queue: " . count($queueProxies) . " -> " . count($uniqueQueue));
        }
        
        // Clear duplicates in checked proxies
        if (file_exists($this->checkedFile)) {
            $checkedProxies = file($this->checkedFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $uniqueChecked = array_unique(array_map('trim', array_filter($checkedProxies)));
            file_put_contents($this->checkedFile, implode("\n", $uniqueChecked) . "\n");
            $this->log("Cleaned checked proxies: " . count($checkedProxies) . " -> " . count($uniqueChecked));
        }
        
        $this->log("Duplicate cleanup completed.");
    }
}

// Xử lý request
if (isset($_GET['action'])) {
    $checker = new ProxyChecker();
    
    switch ($_GET['action']) {
        case 'check':
            $checker->run();
            break;
        case 'info':
            $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
            $checker->getProxyInfo($limit);
            break;
        case 'stats':
            $checker->getStats();
            break;
        case 'test':
            $ip = $_GET['ip'] ?? '';
            $checker->testIP($ip);
            break;
        case 'queue':
            $checker->getQueueInfo();
            break;
        case 'cleanup':
            $checker->clearDuplicates();
            break;
        default:
            echo json_encode(['error' => 'Invalid action']);
    }
} else {
    // Chạy checker nếu không có action
    $checker = new ProxyChecker();
    $checker->run();
}
?>