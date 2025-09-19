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
$VERIFY_SSL = true;   // set to false if some sites use odd certs
$USER_AGENT = 'SimpleUptimeMonitor/1.0 (+https://github.com/your-org/your-repo)';
$METHOD_HEAD_FIRST = true;   // try HEAD first, fall back to GET if HEAD not allowed
$UP_RANGE_MIN = 200;    // inclusive
$UP_RANGE_MAX = 399;    // inclusive
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

// Prepare CSV log (create header once)
$csvHeader = ['timestamp', 'url', 'status_code', 'ok', 'total_time_ms', 'error'];
if (!file_exists($CSV_LOG_FILE)) {
    $fh = fopen($CSV_LOG_FILE, 'w');
    fputcsv($fh, $csvHeader);
    fclose($fh);
}

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

        // We will try HEAD then optionally retry GET if needed
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_NOBODY => $METHOD_HEAD_FIRST,  // HEAD when true
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
            // If there is a low-level multi error, break
            break;
        }
        // Read completed transfers
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
                // Retry with GET if:
                // - HEAD not allowed (405) OR no code (0) but no curl error OR some servers give 403 to HEAD
                if ($httpCode === 405 || $httpCode === 403 || ($httpCode === 0 && empty($curlError))) {
                    $needsRetryWithGET = true;
                }
            }

            if ($needsRetryWithGET) {
                // Retry same handle with GET
                curl_setopt_array($finished, [
                    CURLOPT_NOBODY => false,  // GET now
                ]);
                $meta[$key]['tried_get'] = true;

                // Re-add to multi to run again
                curl_multi_remove_handle($mh, $finished);
                curl_multi_add_handle($mh, $finished);
                continue;
            }

            // Finalise result
            $ok = ($httpCode >= $UP_RANGE_MIN && $httpCode <= $UP_RANGE_MAX) ? '1' : '0';

            $allResults[] = [
                'timestamp' => $startBatchTs,
                'url' => $url,
                'status_code' => $httpCode,
                'ok' => $ok,
                'total_time_ms' => (int) round($totalTime),
                'error' => $curlError,
            ];

            // Cleanup this handle
            curl_multi_remove_handle($mh, $finished);
            curl_close($finished);
            unset($meta[$key]);
        }

        // Prevent busy loop
        if ($active) {
            curl_multi_select($mh, 0.2);
        }
    } while ($active);

    curl_multi_close($mh);
}

// Write CSV append
$fh = fopen($CSV_LOG_FILE, 'a');
foreach ($allResults as $r) {
    fputcsv($fh, $r);
}
fclose($fh);

// Console summary
$up = array_filter($allResults, fn($r) => $r['ok'] === '1');
$down = array_filter($allResults, fn($r) => $r['ok'] !== '1');

echo "=== Website Check @ {$startBatchTs} ===\n";
foreach ($allResults as $r) {
    $status = $r['ok'] === '1' ? 'UP  ' : 'DOWN';
    $extra = $r['error'] ? " (err: {$r['error']})" : '';
    echo sprintf(
        "[%s] %-5s code=%s time=%dms%s\n",
        $r['timestamp'],
        $status,
        $r['status_code'],
        $r['total_time_ms'],
        $extra
    );
}
echo "--------------------------------------\n";
echo "Total: " . count($allResults) . " | Up: " . count($up) . " | Down: " . count($down) . "\n";
echo "Log: {$CSV_LOG_FILE}\n";
