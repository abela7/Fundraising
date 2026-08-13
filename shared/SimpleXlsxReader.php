<?php

declare(strict_types=1);

/**
 * Minimal .xlsx reader for the first worksheet.
 */
final class SimpleXlsxReader
{
    /**
     * @return list<list<string>>
     */
    public static function rows(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('Spreadsheet file not found.');
        }

        $shared = self::sharedStrings($path);
        $sheetXml = self::readEntry($path, 'xl/worksheets/sheet1.xml');
        if ($sheetXml === null) {
            $sheetXml = self::firstWorksheetXml($path);
        }
        if ($sheetXml === null || $sheetXml === '') {
            throw new RuntimeException('Spreadsheet has no worksheet.');
        }

        return self::parseSheet($sheetXml, $shared);
    }

    /**
     * @return list<string>
     */
    private static function sharedStrings(string $path): array
    {
        $xml = self::readEntry($path, 'xl/sharedStrings.xml');
        if ($xml === null || $xml === '') {
            return [];
        }

        $root = self::loadXml($xml);
        $strings = [];
        foreach ($root->si as $si) {
            $text = '';
            foreach ($si->t as $t) {
                $text .= (string) $t;
            }
            if ($text === '') {
                foreach ($si->r as $run) {
                    $text .= (string) $run->t;
                }
            }
            $strings[] = $text;
        }

        return $strings;
    }

    private static function firstWorksheetXml(string $path): ?string
    {
        $pharPath = self::pharPath($path);
        try {
            $phar = new PharData($path);
            foreach (new RecursiveIteratorIterator($phar) as $file) {
                $name = str_replace('\\', '/', (string) $file->getPathname());
                $rel = ltrim(str_replace($pharPath, '', $name), '/');
                if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $rel) === 1) {
                    $xml = file_get_contents($name);

                    return is_string($xml) ? $xml : null;
                }
            }
        } catch (Throwable $e) {
            error_log('SimpleXlsxReader worksheet scan failed: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * @param list<string> $shared
     * @return list<list<string>>
     */
    private static function parseSheet(string $sheetXml, array $shared): array
    {
        $root = self::loadXml($sheetXml);
        $rows = [];
        $maxCols = 0;

        foreach ($root->sheetData->row as $row) {
            $cells = [];
            $col = 0;
            foreach ($row->c as $cell) {
                $ref = (string) $cell['r'];
                if ($ref !== '' && preg_match('/^([A-Z]+)/', $ref, $match) === 1) {
                    $col = self::columnIndex($match[1]);
                }
                $cells[$col] = self::cellValue($cell, $shared);
                $col++;
            }
            if ($cells === []) {
                continue;
            }
            $width = max(array_keys($cells)) + 1;
            $maxCols = max($maxCols, $width);
            $line = array_fill(0, $width, '');
            foreach ($cells as $index => $value) {
                $line[$index] = $value;
            }
            $rows[] = $line;
        }

        foreach ($rows as $i => $line) {
            if (count($line) < $maxCols) {
                $rows[$i] = array_pad($line, $maxCols, '');
            }
        }

        return $rows;
    }

    /**
     * @param list<string> $shared
     */
    private static function cellValue(SimpleXMLElement $cell, array $shared): string
    {
        $type = (string) $cell['t'];
        if ($type === 'inlineStr') {
            $text = '';
            if (isset($cell->is->t)) {
                $text = (string) $cell->is->t;
            } elseif (isset($cell->is->r)) {
                foreach ($cell->is->r as $run) {
                    $text .= (string) $run->t;
                }
            }

            return $text;
        }
        if ($type === 's') {
            $index = (int) (string) $cell->v;

            return $shared[$index] ?? '';
        }

        return isset($cell->v) ? (string) $cell->v : '';
    }

    private static function columnIndex(string $letters): int
    {
        $n = 0;
        $chars = str_split($letters);
        foreach ($chars as $ch) {
            $n = ($n * 26) + (ord($ch) - 64);
        }

        return max(0, $n - 1);
    }

    private static function loadXml(string $xml): SimpleXMLElement
    {
        $stripped = (string) preg_replace('/xmlns(?::[a-z0-9]+)?="[^"]*"/i', '', $xml);
        $element = simplexml_load_string($stripped, SimpleXMLElement::class, LIBXML_NONET);
        if (!$element instanceof SimpleXMLElement) {
            throw new RuntimeException('Invalid spreadsheet XML.');
        }

        return $element;
    }

    private static function readEntry(string $path, string $inner): ?string
    {
        $stream = self::pharPath($path) . '/' . ltrim($inner, '/');
        if (!is_file($stream)) {
            return null;
        }
        $data = file_get_contents($stream);

        return is_string($data) ? $data : null;
    }

    private static function pharPath(string $path): string
    {
        return 'phar://' . str_replace('\\', '/', $path);
    }
}
