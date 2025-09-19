<?php
class Utils
{
    /** Human-friendly reason for common HTTP codes. */
    public static function httpDescription(int $code): ?string
    {
        static $map = [
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        408 => 'Request Timeout',
        418 => "I'm a Teapot",
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        ];
        return $map[$code] ?? null;
    }

    /** Safe trim list of URLs from file. */
    public static function readUrls(string $path): array
    {
        if (!file_exists($path)) {
            fwrite(STDERR, "urls.txt not found at: {$path}\n");
            exit(1);
        }
        $urls = array_values(array_filter(array_map('trim', file($path)), fn($u) => $u !== ''));
        if (!$urls) {
            fwrite(STDERR, "No URLs found in urls.txt\n");
            exit(1);
        }
        return $urls;
    }
}
