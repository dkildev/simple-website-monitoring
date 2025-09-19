<?php
/**
 * Simple Website Uptime Check (no frameworks)
 * Run: php monitor.php
 */

date_default_timezone_set('Australia/Melbourne');

// ---- Configuration (edit as needed) ----
$URLS_FILE = __DIR__ . DIRECTORY_SEPARATOR . 'urls.txt';
$CSV_LOG_FILE = __DIR__ . DIRECTORY_SEPARATOR . 'results.csv';
$CONCURRENCY = 10;     // number of parallel requests
$TIMEOUT_SECONDS = 10;     // per-request timeout
$CONNECT_TIMEOUT = 5;      // faster fail on bad hosts
$FOLLOW_REDIRECTS = true;   // follow 30x
$VERIFY_SSL = true;         // set to false if some sites use odd certs
$USER_AGENT = '';           // leave blank or set your own
$METHOD_HEAD_FIRST = true;  // try HEAD first, fall back to GET if HEAD not allowed
$UP_RANGE_MIN = 200;        // inclusive
$UP_RANGE_MAX = 399;        // inclusive
// ----------------------------------------

// Load URLs
if (!file_exists($URLS_FILE)) {
    fwrite(STDERR, "urls.txt not found at: {$URLS_FILE}\n");
    exit(1);
}
$urls = array_values(array_filter(array_map('trim', file($URLS_FILE)), fn($u) => $u !== ''));
if (empty($urls)) {
    fwrite(STDERR, "No URLs found in urls.txt\n");
    exit(1);
}

// CSV header
$csvHeader = ['timestamp', 'status', 'url', 'status_code', 'ok', 'total_time_ms', 'error_description'];

// Chunk URLs by concurrency
$chunks = array_chunk($urls, $CONCURRENCY);
$allResults = [];
$startBatchTs = date('Y-m-d H:i:s');

foreach ($chunks as $chunk) {
    $mh = curl_multi_init();
    $handles = [];
    $meta = [];

    foreach ($chunk as $url) {
        $ch = curl_init();

        // Try HEAD first, fallback to GET if needed
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_NOBODY => $METHOD_HEAD_FIRST,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => $FOLLOW_REDIRECTS,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => $CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => $TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => $VERIFY_SSL,
            CURLOPT_SSL_VERIFYHOST => $VERIFY_SSL ? 2 : 0,
            CURLOPT_USERAGENT => $USER_AGENT,
            CURLOPT_HEADER => false,
        ]);

        $handles[] = $ch;
        $meta[(int) $ch] = [
            'url' => $url,
            'tried_get' => false,
        ];
        curl_multi_add_handle($mh, $ch);
    }

    // Execute concurrently
    do {
        $status = curl_multi_exec($mh, $active);
        if ($status > CURLM_OK) {
            break; // Low-level multi error
        }

        while ($info = curl_multi_info_read($mh)) {
            /** @var resource $finished */
            $finished = $info['handle'];
            $key = (int) $finished;
            $url = $meta[$key]['url'];

            $httpCode = curl_getinfo($finished, CURLINFO_RESPONSE_CODE);
            $totalTime = curl_getinfo($finished, CURLINFO_TOTAL_TIME) * 1000; // ms
            $curlError = $info['result'] !== CURLE_OK ? curl_error($finished) : '';

            $needsRetryWithGET = false;
            if ($METHOD_HEAD_FIRST && !$meta[$key]['tried_get']) {
                if ($httpCode === 405 || $httpCode === 403 || ($httpCode === 0 && empty($curlError))) {
                    $needsRetryWithGET = true;
                }
            }

            if ($needsRetryWithGET) {
                curl_setopt_array($finished, [
                    CURLOPT_NOBODY => false, // retry with GET
                ]);
                $meta[$key]['tried_get'] = true;
                curl_multi_remove_handle($mh, $finished);
                curl_multi_add_handle($mh, $finished);
                continue;
            }

            // --- Finalise result ---
            $ok = ($httpCode >= $UP_RANGE_MIN && $httpCode <= $UP_RANGE_MAX) ? '1' : '0';
            $statusText = $ok === '1' ? 'UP' : 'DOWN';

            // Build error message for DOWN
            $errorMessage = '';
            if ($ok === '0') {
                if ($curlError) {
                    $errorMessage = $curlError;
                } elseif ($httpCode > 0) {
                    $errorMessage = "HTTP {$httpCode}";
                    $httpDescriptions = [
                        400 => 'Bad Request',
                        401 => 'Unauthorized',
                        403 => 'Forbidden',
                        404 => 'Not Found',
                        408 => 'Request Timeout',
                        429 => 'Too Many Requests',
                        500 => 'Internal Server Error',
                        502 => 'Bad Gateway',
                        503 => 'Service Unavailable',
                        504 => 'Gateway Timeout',
                        418 => "I'm a Teapot",
                    ];
                    if (isset($httpDescriptions[$httpCode])) {
                        $errorMessage .= " ({$httpDescriptions[$httpCode]})";
                    }
                } else {
                    $errorMessage = 'Unknown error';
                }
            }

            $allResults[] = [
                'timestamp' => $startBatchTs,
                'status' => $statusText,
                'url' => $url,
                'status_code' => $httpCode,
                'ok' => $ok,
                'total_time_ms' => (int) round($totalTime),
                'error' => $errorMessage,
            ];

            // Cleanup
            curl_multi_remove_handle($mh, $finished);
            curl_close($finished);
            unset($meta[$key]);
        }

        if ($active) {
            curl_multi_select($mh, 0.2);
        }
    } while ($active);

    curl_multi_close($mh);
}

// --- Write CSV append ---
$fileExists = file_exists($CSV_LOG_FILE);
$fh = fopen($CSV_LOG_FILE, 'a');

// If file does not exist, write header
if (!$fileExists) {
    fputcsv($fh, $csvHeader);
} else {
    // Add one blank line before new batch
    if (filesize($CSV_LOG_FILE) > 0) {
        fwrite($fh, PHP_EOL);
    }
}

foreach ($allResults as $r) {
    fputcsv($fh, $r);
}
fclose($fh);

// --- Console summary ---
$up = array_filter($allResults, fn($r) => $r['ok'] === '1');
$down = array_filter($allResults, fn($r) => $r['ok'] !== '1');

echo "=== Website Check @ {$startBatchTs} ===\n";
foreach ($allResults as $r) {
    $extra = $r['error'] ? " (err: {$r['error']})" : '';
    echo sprintf(
        "[%s] %-5s %s code=%s time=%dms%s\n",
        $r['timestamp'],
        $r['status'],      // UP or DOWN
        $r['url'],
        $r['status_code'],
        $r['total_time_ms'],
        $extra
    );
}
echo "--------------------------------------\n";
echo "Total: " . count($allResults) . " | Up: " . count($up) . " | Down: " . count($down) . "\n";
echo "Log: {$CSV_LOG_FILE}\n";
