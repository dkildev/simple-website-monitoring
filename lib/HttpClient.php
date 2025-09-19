<?php
require_once __DIR__ . '/Utils.php';

class HttpClient
{
    /**
     * Check all URLs with curl_multi (HEAD then fallback to GET when needed).
     * Returns an array of associative results:
     * ['timestamp','status','url','status_code','ok','total_time_ms','error'].
     */
    public static function check(array $urls, string $batchTs): array
    {
        $chunks = array_chunk($urls, CONCURRENCY);
        $results = [];

        foreach ($chunks as $chunk) {
            $mh = curl_multi_init();
            $meta = [];

            foreach ($chunk as $url) {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_NOBODY => METHOD_HEAD_FIRST,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => FOLLOW_REDIRECTS,
                    CURLOPT_MAXREDIRS => 5,
                    CURLOPT_CONNECTTIMEOUT => CONNECT_TIMEOUT,
                    CURLOPT_TIMEOUT => TIMEOUT_SECONDS,
                    CURLOPT_SSL_VERIFYPEER => VERIFY_SSL,
                    CURLOPT_SSL_VERIFYHOST => VERIFY_SSL ? 2 : 0,
                    CURLOPT_USERAGENT => USER_AGENT,
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

                    $httpCode = (int) curl_getinfo($finished, CURLINFO_RESPONSE_CODE);
                    $totalTime = (int) round(curl_getinfo($finished, CURLINFO_TOTAL_TIME) * 1000);
                    $curlError = $info['result'] !== CURLE_OK ? curl_error($finished) : '';

                    // Retry GET if HEAD not great
                    if (METHOD_HEAD_FIRST && !$meta[$key]['tried_get']) {
                        if ($httpCode === 405 || $httpCode === 403 || ($httpCode === 0 && $curlError === '')) {
                            curl_setopt($finished, CURLOPT_NOBODY, false);
                            $meta[$key]['tried_get'] = true;
                            curl_multi_remove_handle($mh, $finished);
                            curl_multi_add_handle($mh, $finished);
                            continue;
                        }
                    }

                    // Decide UP/DOWN
                    // $ok = ($httpCode >= UP_RANGE_MIN && $httpCode <= UP_RANGE_MAX) ? '1' : '0';
                    $ok = (($httpCode >= UP_RANGE_MIN && $httpCode <= UP_RANGE_MAX) || $httpCode === 418) ? '1' : '0';
                    $statusText = $ok === '1' ? 'UP' : 'DOWN';

                    // Build reason if DOWN
                    $errorMessage = '';
                    if ($ok === '0') {
                        if ($curlError) {
                            $errorMessage = $curlError;
                        } elseif ($httpCode > 0) {
                            $desc = Utils::httpDescription($httpCode);
                            $errorMessage = "HTTP {$httpCode}" . ($desc ? " ({$desc})" : '');
                        } else {
                            $errorMessage = 'Unknown error';
                        }
                    }

                    $results[] = [
                        'timestamp' => $batchTs,
                        'status' => $statusText,
                        'url' => $url,
                        'status_code' => $httpCode,
                        'ok' => $ok,
                        'total_time_ms' => $totalTime,
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

        return $results;
    }
}
