<?php

namespace App\Modules\Imports\Services;

use Generator;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;

/**
 * Reads CSV or XLSX into associative rows keyed by normalised header
 * ("Guardian Phone" → "guardian_phone"). Values are trimmed strings.
 */
class SpreadsheetReader
{
    public const MAX_ROWS = 5000;

    /**
     * @return Generator<int, array<string, string>> row number (1-based, excluding header) => row
     */
    public function rows(string $absolutePath, string $extension): Generator
    {
        $iterator = match (strtolower($extension)) {
            'csv', 'txt' => $this->csv($absolutePath),
            'xlsx' => $this->xlsx($absolutePath),
            default => throw new RuntimeException('Unsupported file type.'),
        };

        $headers = null;
        $line = 0;

        foreach ($iterator as $cells) {
            $cells = array_map(fn ($v) => trim((string) ($v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v)), $cells);

            if ($headers === null) {
                // Strip a UTF-8 BOM that Excel adds to CSV exports.
                $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cells[0] ?? '');
                $headers = array_map(fn ($h) => strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $h) ?? '', '_')), $cells);

                continue;
            }

            if (implode('', $cells) === '') {
                continue; // blank line
            }

            if (++$line > self::MAX_ROWS) {
                throw new RuntimeException('Too many rows: split the file into parts of '.self::MAX_ROWS.' rows.');
            }

            $row = [];
            foreach ($headers as $i => $header) {
                if ($header !== '') {
                    $row[$header] = $cells[$i] ?? '';
                }
            }

            yield $line => $row;
        }
    }

    /**
     * @return Generator<int, list<mixed>>
     */
    private function csv(string $path): Generator
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException('Cannot read the uploaded file.');
        }

        try {
            while (($cells = fgetcsv($handle, escape: '\\')) !== false) {
                yield $cells;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return Generator<int, list<mixed>>
     */
    private function xlsx(string $path): Generator
    {
        $reader = new XlsxReader;
        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    yield $row->toArray();
                }

                break; // first sheet only
            }
        } finally {
            $reader->close();
        }
    }
}
