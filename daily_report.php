<?php
/**
 * Sends the full results.csv as the email body (optionally attach the CSV).
 * Run daily via cron.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Mailer.php';

$csvPath = CSV_LOG_FILE;
$when = date('Y-m-d H:i:s');

$mailer = new Mailer();
$ok = $mailer->sendDailyReport(DAILY_REPORT_TO, $csvPath, DAILY_REPORT_SUBJECT);

if ($ok) {
    echo "[{$when}] Daily report sent to " . DAILY_REPORT_TO . PHP_EOL;
} else {
    fwrite(STDERR, "[{$when}] Failed to send daily report\n");
    exit(1);
}
