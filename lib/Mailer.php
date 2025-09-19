<?php
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Mailer
{
    private function makeMailer(): PHPMailer
    {
        $mail = new PHPMailer(true);
        // Server
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE; // 'tls' or 'ssl'
        $mail->Port = SMTP_PORT;

        // From / defaults
        $mail->setFrom(SMTP_FROM, SMTP_FROMNAME);
        $mail->isHTML(false);
        $mail->CharSet = 'UTF-8';

        return $mail;
    }

    public function sendDownAlert(string $to, array $resultRow, string $batchTs): bool
    {
        $mail = $this->makeMailer();

        $body =
            "ALERT: Website is DOWN\n\n" .
            "Time: {$batchTs}\n" .
            "URL: {$resultRow['url']}\n" .
            "Status Code: {$resultRow['status_code']}\n" .
            "Reason: {$resultRow['error']}\n" .
            "Response Time: {$resultRow['total_time_ms']} ms\n";

        try {
            $mail->Subject = EMAIL_SUBJECT;
            $mail->Body = $body;
            $mail->addAddress($to);
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log('Down alert email failed: ' . $mail->ErrorInfo);
            return false;
        }
    }

    public function sendDailyReport(string $to, string $csvPath, string $subject): bool
    {
        $mail = $this->makeMailer();

        $body = "Daily Uptime Report\n\n";
        if (file_exists($csvPath) && filesize($csvPath) > 0) {
            $body .= file_get_contents($csvPath);
        } else {
            $body .= "(No results yet — results.csv not found or empty)";
        }

        try {
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->addAddress($to);

            if (DAILY_REPORT_ATTACH && file_exists($csvPath)) {
                // Use positional args to avoid analyser warnings
                $mail->addAttachment($csvPath, 'results.csv', PHPMailer::ENCODING_BASE64, 'text/csv');
            }

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log('Daily report email failed: ' . $mail->ErrorInfo);
            return false;
        }
    }
}
