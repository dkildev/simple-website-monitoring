📌 Description

Website Monitor is a lightweight PHP script that checks whether a list of websites is online and responding correctly.
The script uses PHP’s curl_multi functions to send multiple requests in parallel, making it fast and efficient even for many sites. It is framework-free and runs on any system with PHP installed (for example, XAMPP on Windows).

✅ What it does

Reads a list of websites from urls.txt (one URL per line).  
Sends HTTP requests (HEAD first, then GET if needed).
Measures: HTTP status code (e.g. 200, 404, 500).  
Response time (milliseconds).  
Any curl errors (DNS issues, SSL problems, etc.).  
Determines whether a site is UP or DOWN based on the status code (200–399 = UP).  
Prints a clear summary to the console.
Logs results into results.csv with timestamp, URL, status, response time, and error details.

🔎 What it checks

Is the site reachable? (DNS, TCP, SSL handshake).  
Did the server respond within the timeout? (default 10s).  
Is the HTTP status valid? (200–399 = healthy).  
Did the request fail? (connection refused, timeout, certificate error, etc.).

🛠️ How you can use it

Run manually to spot-check sites:
php monitor.php

Schedule it via Windows Task Scheduler (or cron on Linux/Mac) to run every few minutes.  
Analyse the results.csv log over time to detect outages or slow responses.
