<?php
date_default_timezone_set('Australia/Melbourne');

// -------- App paths --------
define('URLS_FILE', __DIR__ . DIRECTORY_SEPARATOR . 'urls.txt');
define('CSV_LOG_FILE', __DIR__ . DIRECTORY_SEPARATOR . 'results.csv');

// -------- HTTP check config --------
define('CONCURRENCY', 10);
define('TIMEOUT_SECONDS', 20);
define('CONNECT_TIMEOUT', 5);
define('FOLLOW_REDIRECTS', true);
define('VERIFY_SSL', true);
define('USER_AGENT', 'Mozilla/5.0 (compatible; TI-UptimeMonitor/1.0; +mailto:digital@techinnovate.com.au)');
define('METHOD_HEAD_FIRST', false); // when set to true, it shows many 418 status
define('UP_RANGE_MIN', 200);
define('UP_RANGE_MAX', 399);

// -------- Email (SMTP via Mailtrap / cPanel / etc.) --------
define('ALERT_EMAIL_TO', 'abc@email.com');
define('EMAIL_SUBJECT', 'Client Website DOWN Alert');

// SMTP: fill from Mailtrap (tesing)
define('SMTP_HOST', 'sandbox.smtp.mailtrap.io');
define('SMTP_PORT', 587);     // Mailtrap: 587 or 2525 with TLS
define('SMTP_SECURE', 'tls');   // 'tls' or 'ssl'
define('SMTP_USER', ''); // e.g. 12345
define('SMTP_PASS', ''); // paste full password
define('SMTP_FROM', 'abc@email.com');
define('SMTP_FROMNAME', 'Website Monitor Alerts');

// -------- Daily report email --------
define('DAILY_REPORT_TO', ALERT_EMAIL_TO); // same recipient
define('DAILY_REPORT_SUBJECT', 'Daily Uptime Report');
define('DAILY_REPORT_ATTACH', false); // set true if you also want CSV attached

