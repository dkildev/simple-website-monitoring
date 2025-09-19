<?php
/**
 * Website Uptime Monitor with Email Alerts (SMTP via PHPMailer)
 */

date_default_timezone_set('Australia/Melbourne');

// ---- Config ----
$URLS_FILE = __DIR__ . DIRECTORY_SEPARATOR . 'urls.txt';
$CSV_LOG_FILE = __DIR__ . DIRECTORY_SEPARATOR . 'results.csv';
$CONCURRENCY = 10;
$TIMEOUT_SECONDS = 10;
$CONNECT_TIMEOUT = 5;
$FOLLOW_REDIRECTS = true;
$VERIFY_SSL = true;
$USER_AGENT = '';
$METHOD_HEAD_FIRST = true;
$UP_RANGE_MIN = 200;
$UP_RANGE_MAX = 399;

// ---- Email via SMTP ----
$ALERT_EMAIL_TO = 'digital@techinnovate.com.au';
$EMAIL_SUBJECT = "Website Maintenance Package / Client Website DOWN Alert";

$SMTP_HOST = "sandbox.smtp.mailtrap.io";
$SMTP_PORT = 587;        // or 2525 works too
$SMTP_SECURE = "tls";      // use "tls" for Mailtrap
$SMTP_USER = "242d5932aec94c";   // from your Mailtrap credentials
$SMTP_PASS = "d9807e9cb850b1";   // set strong password
$SMTP_FROM = "website-checker@techinnovate.com.au";
$SMTP_FROMNAME = "Website Monitor Alerts";
// -------------------------

// Load PHPMailer
require __DIR__ . '/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendAlertEmailSMTP($to, $subject, $body, $smtpConfig)
{
    $mail = new PHPMailer(true);
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = $smtpConfig['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $smtpConfig['user'];
        $mail->Password = $smtpConfig['pass'];
        $mail->SMTPSecure = $smtpConfig['secure'];
        $mail->Port = $smtpConfig['port'];

        // From / To
        $mail->setFrom($smtpConfig['from'], $smtpConfig['fromName']);
        $mail->addAddress($to);

        // Content
        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body = $body;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email failed: {$mail->ErrorInfo}");
        return false;
    }
}

$smtpConfig = [
    'host' => $SMTP_HOST,
    'port' => $SMTP_PORT,
    'secure' => $SMTP_SECURE,
    'user' => $SMTP_USER,
    'pass' => $SMTP_PASS,
    'from' => $SMTP_FROM,
    'fromName' => $SMTP_FROMNAME,
];

// ---- Load URLs ----
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
$csvHeader = ['timestamp', 'status', 'url', 'status_code', 'ok', 'total_time_ms', 'error'];

// Chunk URLs
$chunks = array_chunk($urls, $CONCURRENCY);
$allResults = [];
$startBatchTs = date('Y-m-d H:i:s');

foreach ($chunks as $chunk) {
    $mh = curl_multi_init();
    $meta = [];

    foreach ($chunk as $url) {
        $ch = curl_init();
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
        $meta[(int) $ch] = ['url' => $url, 'tried_get' => false];
        curl_multi_add_handle($mh, $ch);
    }

    do {
        $status = curl_multi_exec($mh, $active);
        if ($status > CURLM_OK)
            break;

        while ($info = curl_multi_info_read($mh)) {
            $finished = $info['handle'];
            $key = (int) $finished;
            $url = $meta[$key]['url'];

            $httpCode = curl_getinfo($finished, CURLINFO_RESPONSE_CODE);
            $totalTime = curl_getinfo($finished, CURLINFO_TOTAL_TIME) * 1000;
            $curlError = $info['result'] !== CURLE_OK ? curl_error($finished) : '';

            // Retry GET if needed
            if ($METHOD_HEAD_FIRST && !$meta[$key]['tried_get']) {
                if ($httpCode === 405 || $httpCode === 403 || ($httpCode === 0 && empty($curlError))) {
                    curl_setopt_array($finished, [CURLOPT_NOBODY => false]);
                    $meta[$key]['tried_get'] = true;
                    curl_multi_remove_handle($mh, $finished);
                    curl_multi_add_handle($mh, $finished);
                    continue;
                }
            }

            // Result
            $ok = ($httpCode >= $UP_RANGE_MIN && $httpCode <= $UP_RANGE_MAX) ? '1' : '0';
            $statusText = $ok === '1' ? 'UP' : 'DOWN';
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

                // 🚨 Send alert
                $body = "ALERT: Website is DOWN\n\n"
                    . "Time: {$startBatchTs}\n"
                    . "URL: {$url}\n"
                    . "Status Code: {$httpCode}\n"
                    . "Reason: {$errorMessage}\n"
                    . "Response Time: " . round($totalTime) . " ms\n";
                sendAlertEmailSMTP($ALERT_EMAIL_TO, $EMAIL_SUBJECT, $body, $smtpConfig);
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

            curl_multi_remove_handle($mh, $finished);
            curl_close($finished);
            unset($meta[$key]);
        }

        if ($active)
            curl_multi_select($mh, 0.2);
    } while ($active);

    curl_multi_close($mh);
}

// --- Write CSV ---
$fileExists = file_exists($CSV_LOG_FILE);
$fh = fopen($CSV_LOG_FILE, 'a');
if (!$fileExists) {
    fputcsv($fh, $csvHeader);
} else {
    if (filesize($CSV_LOG_FILE) > 0)
        fwrite($fh, PHP_EOL);
}
foreach ($allResults as $r)
    fputcsv($fh, $r);
fclose($fh);

// --- Console output ---
$up = array_filter($allResults, fn($r) => $r['ok'] === '1');
$down = array_filter($allResults, fn($r) => $r['ok'] !== '1');

echo "=== Website Check @ {$startBatchTs} ===\n";
foreach ($allResults as $r) {
    $extra = $r['error'] ? " (err: {$r['error']})" : '';
    echo sprintf(
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
echo "Total: " . count($allResults) . " | Up: " . count($up) . " | Down: " . count($down) . "\n";
echo "Log: {$CSV_LOG_FILE}\n";
