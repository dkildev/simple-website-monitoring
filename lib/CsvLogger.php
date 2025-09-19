<?php
class CsvLogger
{
    private const HEADER = ['timestamp', 'status', 'url', 'status_code', 'ok', 'total_time_ms', 'error'];

    public static function appendBatch(string $csvPath, array $rows): void
    {
        $fileExists = file_exists($csvPath);
        $fh = fopen($csvPath, 'a');
        if (!$fileExists) {
            fputcsv($fh, self::HEADER);
        } else {
            if (filesize($csvPath) > 0)
                fwrite($fh, PHP_EOL); // exactly one blank line between runs
        }
        foreach ($rows as $r)
            fputcsv($fh, $r);
        fclose($fh);
    }
}
