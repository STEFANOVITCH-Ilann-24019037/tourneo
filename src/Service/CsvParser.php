<?php

declare(strict_types=1);

namespace Tourneo\Service;

class CsvParser
{
    public function parse(string $content): array
    {
        // Retire le BOM UTF-8 si présent (fichiers exportés depuis Excel)
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $headers = fgetcsv($handle, 0, ',', '"', '\\');
        if ($headers === false) {
            fclose($handle);
            return [];
        }

        $headers = array_map(fn(string $h): string => strtolower(trim($h)), $headers);
        $rows    = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            // Ignore les lignes vides
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
}
