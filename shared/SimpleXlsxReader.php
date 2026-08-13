<?php

declare(strict_types=1);

/**
 * Minimal .xlsx reader for the first worksheet.
 * Uses raw ZIP + zlib so ZipArchive and Phar are not required.
 */
final class SimpleXlsxReader
{
    /**
     * @return list<list<string>>
     */
    public static function rows(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Spreadsheet file not found.');
        }

        $shared = self::sharedStrings($path);
        $sheetXml = self::readEntry($path, 'xl/worksheets/sheet1.xml');
        if ($sheetXml === null || $sheetXml === '') {
            foreach (self::zipEntryNames($path) as $name) {
                if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name) === 1) {
                    $sheetXml = self::readEntry($path, $name);
                    break;
                }
            }
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

    /**
     * @param list<string> $shared
     * @return list<list<string>>
     */
    private static function parseSheet(string $sheetXml, array $shared): array
    {
        $root = self::loadXml($sheetXml);
        if (!isset($root->sheetData) || !isset($root->sheetData->row)) {
            return [];
        }

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
        return self::readZipEntry($path, str_replace('\\', '/', $inner));
    }

    /**
     * @return list<string>
     */
    private static function zipEntryNames(string $path): array
    {
        $names = [];
        self::walkZipCentralDirectory($path, static function (string $name) use (&$names): bool {
            $names[] = $name;

            return true;
        });

        return $names;
    }

    private static function readZipEntry(string $path, string $inner): ?string
    {
        $found = null;
        self::walkZipCentralDirectory($path, static function (string $name, int $localOff) use ($path, $inner, &$found): bool {
            if ($name !== $inner) {
                return true;
            }
            $found = self::readLocalZipFile($path, $localOff);

            return false;
        });

        return $found;
    }

    /**
     * @param callable(string, int): bool $visitor Return false to stop.
     */
    private static function walkZipCentralDirectory(string $path, callable $visitor): void
    {
        $size = filesize($path);
        if ($size === false || $size < 22) {
            return;
        }
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return;
        }
        try {
            $eocdMax = (int) min($size, 65557);
            if (fseek($fh, $size - $eocdMax) !== 0) {
                return;
            }
            $tail = fread($fh, $eocdMax);
            if (!is_string($tail)) {
                return;
            }
            $pos = strrpos($tail, "PK\x05\x06");
            if ($pos === false) {
                return;
            }
            $eocd = substr($tail, $pos);
            if (strlen($eocd) < 22) {
                return;
            }
            $cdSize = self::unpackUint32(substr($eocd, 12, 4));
            $cdOffset = self::unpackUint32(substr($eocd, 16, 4));
            $cdCount = self::unpackUint16(substr($eocd, 10, 2));
            if (fseek($fh, $cdOffset) !== 0) {
                return;
            }
            $cd = fread($fh, $cdSize);
            if (!is_string($cd) || $cd === '') {
                return;
            }
            $offset = 0;
            $length = strlen($cd);
            for ($i = 0; $i < $cdCount && ($offset + 46) <= $length; $i++) {
                if (substr($cd, $offset, 4) !== "PK\x01\x02") {
                    return;
                }
                $nameLen = self::unpackUint16(substr($cd, $offset + 28, 2));
                $extraLen = self::unpackUint16(substr($cd, $offset + 30, 2));
                $commentLen = self::unpackUint16(substr($cd, $offset + 32, 2));
                $localOff = self::unpackUint32(substr($cd, $offset + 42, 4));
                $name = substr($cd, $offset + 46, $nameLen);
                $offset += 46 + $nameLen + $extraLen + $commentLen;
                if ($visitor($name, $localOff) === false) {
                    return;
                }
            }
        } finally {
            fclose($fh);
        }
    }

    private static function readLocalZipFile(string $path, int $localOff): ?string
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }
        try {
            if (fseek($fh, $localOff) !== 0) {
                return null;
            }
            $local = fread($fh, 30);
            if (!is_string($local) || strlen($local) < 30 || substr($local, 0, 4) !== "PK\x03\x04") {
                return null;
            }
            $method = self::unpackUint16(substr($local, 8, 2));
            $compSize = self::unpackUint32(substr($local, 18, 4));
            $nameLen = self::unpackUint16(substr($local, 26, 2));
            $extraLen = self::unpackUint16(substr($local, 28, 2));
            if (fseek($fh, $localOff + 30 + $nameLen + $extraLen) !== 0) {
                return null;
            }
            $payload = $compSize > 0 ? fread($fh, $compSize) : '';
            if (!is_string($payload)) {
                return null;
            }
            if ($method === 0) {
                return $payload;
            }
            if ($method === 8) {
                $out = @gzinflate($payload);

                return is_string($out) ? $out : null;
            }

            return null;
        } finally {
            fclose($fh);
        }
    }

    private static function unpackUint16(string $bytes): int
    {
        if (strlen($bytes) < 2) {
            return 0;
        }
        $data = unpack('v', $bytes);

        return is_array($data) ? (int) $data[1] : 0;
    }

    private static function unpackUint32(string $bytes): int
    {
        if (strlen($bytes) < 4) {
            return 0;
        }
        $data = unpack('V', $bytes);

        return is_array($data) ? (int) $data[1] : 0;
    }
}
