<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Utils.php';
require_once __DIR__ . '/lib/HttpClient.php';
require_once __DIR__ . '/lib/CsvLogger.php';
require_once __DIR__ . '/lib/Mailer.php';

// Load URLs and set batch timestamp
$urls = Utils::readUrls(URLS_FILE);
$batchTs = date('Y-m-d H:i:s');

// Run checks
$results = HttpClient::check($urls, $batchTs);

// Log to CSV
CsvLogger::appendBatch(CSV_LOG_FILE, $results);

// Send alerts for DOWN rows
$mailer = new Mailer();
foreach ($results as $r) {
    if ($r['ok'] !== '1') {
        $mailer->sendDownAlert(ALERT_EMAIL_TO, $r, $batchTs);
    }
}

// Console summary
$up = array_filter($results, fn($r) => $r['ok'] === '1');
$down = array_filter($results, fn($r) => $r['ok'] !== '1');

echo "=== Website Check @ {$batchTs} ===\n";
foreach ($results as $r) {
    $extra = $r['error'] ? " (err: {$r['error']})" : '';
    printf(
        "[%s] %-5s %s code=%s time=%dms%s\n",
        $r['timestamp'],
        $r['status'],
        $r['url'],
        $r['status_code'],
        $r['total_time_ms'],
        $extra
    );
}
echo "--------------------------------------\n";
echo "Total: " . count($results) . " | Up: " . count($up) . " | Down: " . count($down) . "\n";
echo "Log: " . CSV_LOG_FILE . "\n";
