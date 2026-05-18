<?php

declare(strict_types=1);

namespace Tourneo\Service;

class CsvParser
{
    public function parse(string $content): array
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        $firstLine = strstr($content, "\n", true);
        if ($firstLine === false) {
            $firstLine = $content;
        }
        $delimiter = $this->detectDelimiter($firstLine);

        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $headers = fgetcsv($handle, 0, $delimiter, '"', '\\');
        if ($headers === false) {
            fclose($handle);
            return [];
        }

        $headers = array_map(fn(string $h): string => strtolower(trim($h)), $headers);
        $rows    = [];

        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            if (array_filter($row, fn($v): bool => trim((string) $v) !== '') === []) {
                continue;
            }

            $entry = [];
            foreach ($headers as $i => $header) {
                $entry[$header] = trim((string) ($row[$i] ?? ''));
            }

            $rows[] = $entry;
        }

        fclose($handle);
        return $rows;
    }

    private function detectDelimiter(string $firstLine): string
    {
        $semicolons = substr_count($firstLine, ';');
        $commas     = substr_count($firstLine, ',');

        return $semicolons > $commas ? ';' : ',';
    }
}
