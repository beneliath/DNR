<?php
declare(strict_types=1);

const AUDIENCE_XLSX_MAX_TEXT_BYTES = 20 * 1024 * 1024;
const AUDIENCE_XLSX_MAX_CELLS = 500000;
const AUDIENCE_XLSX_MAX_STRING_BYTES = 131072;

/** Read zip entries incrementally; never construct a native XML tree. */
function audienceXlsxNodes(ZipArchive $zip, string $name, int $maximumBytes, int &$nodes): Generator
{
    $stat = $zip->statName($name);
    if ($stat === false || $stat['size'] > $maximumBytes) throw new InvalidArgumentException('The workbook contains a missing or oversized XML document. Save it as CSV.');
    $stream = $zip->getStream($name);
    if ($stream === false) throw new InvalidArgumentException('The workbook XML could not be read.');
    $reader = null;
    $previous = libxml_use_internal_errors(true);
    libxml_clear_errors();
    try {
        $reader = XMLReader::fromStream($stream, null, LIBXML_NONET);
        foreach ([XMLReader::LOADDTD, XMLReader::DEFAULTATTRS, XMLReader::VALIDATE, XMLReader::SUBST_ENTITIES] as $property) {
            $reader->setParserProperty($property, false);
        }
        while ($reader->read()) {
            if (++$nodes > 3000000 || $reader->depth > 32) throw new InvalidArgumentException('This workbook is too complex. Split it into smaller CSV files.');
            if (in_array($reader->nodeType, [XMLReader::DOC_TYPE, XMLReader::ENTITY, XMLReader::ENTITY_REF], true)) {
                throw new InvalidArgumentException('The workbook contains unsupported document entities.');
            }
            yield $reader;
        }
        foreach (libxml_get_errors() as $error) {
            if ($error->level >= LIBXML_ERR_ERROR) throw new InvalidArgumentException('The workbook contains invalid XML.');
        }
    } finally {
        if ($reader !== null) $reader->close();
        if (is_resource($stream)) fclose($stream);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
}

function audienceXlsxTextNode(XMLReader $node): bool
{
    return in_array($node->nodeType, [XMLReader::TEXT, XMLReader::CDATA, XMLReader::WHITESPACE, XMLReader::SIGNIFICANT_WHITESPACE], true);
}

/** Shared strings are bounded independently of how many worksheet rows use them. */
function audienceXlsxSharedStrings(ZipArchive $zip, int &$nodes): array
{
    if ($zip->locateName('xl/sharedStrings.xml') === false) return [];
    $strings = []; $inside = false; $textDepth = null; $phoneticDepth = null; $value = ''; $bytes = 0;
    foreach (audienceXlsxNodes($zip, 'xl/sharedStrings.xml', 80 * 1024 * 1024, $nodes) as $node) {
        if ($node->nodeType === XMLReader::ELEMENT) {
            if ($node->localName === 'si') {
                if ($inside || count($strings) >= AUDIENCE_XLSX_MAX_CELLS) throw new InvalidArgumentException('This workbook has too many shared strings. Split it into smaller CSV files.');
                $inside = !$node->isEmptyElement; $value = '';
                if ($node->isEmptyElement) $strings[] = '';
            } elseif ($inside && $node->localName === 'rPh' && !$node->isEmptyElement) {
                $phoneticDepth = $node->depth;
            } elseif ($inside && $node->localName === 't' && $phoneticDepth === null && !$node->isEmptyElement) {
                $textDepth = $node->depth;
            }
        } elseif (audienceXlsxTextNode($node) && $inside && $textDepth !== null) {
            $bytes += strlen($node->value);
            if ($bytes > AUDIENCE_XLSX_MAX_TEXT_BYTES || strlen($value) + strlen($node->value) > AUDIENCE_XLSX_MAX_STRING_BYTES) {
                throw new InvalidArgumentException('The workbook text is too large. Split it into smaller CSV files.');
            }
            $value .= $node->value;
        } elseif ($node->nodeType === XMLReader::END_ELEMENT) {
            if ($node->localName === 't' && $node->depth === $textDepth) $textDepth = null;
            if ($node->localName === 'rPh' && $node->depth === $phoneticDepth) $phoneticDepth = null;
            if ($node->localName === 'si' && $inside) { $strings[] = $value; $inside = false; }
        }
    }
    return $strings;
}

/** Stream one worksheet into a bounded private CSV, then use the shared CSV validation. */
function audienceReadXlsx(string $path): array
{
    if (!class_exists(ZipArchive::class) || !class_exists(XMLReader::class)) throw new InvalidArgumentException('XLSX is unavailable on this server. Save the file as CSV.');
    if (!is_file($path) || filesize($path) > 20 * 1024 * 1024) throw new InvalidArgumentException('Use a file of 20 MB or less.');
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new InvalidArgumentException('This is not a valid XLSX workbook.');
    $csv = null;
    try {
        if ($zip->numFiles > 1024) throw new InvalidArgumentException('This workbook contains too many files. Save the worksheet as CSV.');
        $total = 0; $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false || isset($names[$stat['name']])) throw new InvalidArgumentException('The workbook contains duplicate or invalid entries.');
            $names[$stat['name']] = true; $total += $stat['size'];
            if ($total > 80 * 1024 * 1024) throw new InvalidArgumentException('The expanded workbook exceeds 80 MB. Save the worksheet as CSV.');
        }
        $nodes = 0; $rid = null;
        foreach (audienceXlsxNodes($zip, 'xl/workbook.xml', 262144, $nodes) as $node) {
            if ($rid === null && $node->nodeType === XMLReader::ELEMENT && $node->localName === 'sheet') {
                $rid = $node->getAttributeNs('id', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            }
        }
        $target = '';
        foreach (audienceXlsxNodes($zip, 'xl/_rels/workbook.xml.rels', 262144, $nodes) as $node) {
            if ($node->nodeType === XMLReader::ELEMENT && $node->localName === 'Relationship' && $node->getAttribute('Id') === $rid) {
                if (strcasecmp((string) $node->getAttribute('TargetMode'), 'External') === 0) throw new InvalidArgumentException('External worksheets are not supported.');
                $target = (string) $node->getAttribute('Target');
            }
        }
        if ($rid === null || str_contains($target, '..') || !preg_match('~^(?:/?xl/)?worksheets/[A-Za-z0-9_.-]+\.xml$~D', $target)) throw new InvalidArgumentException('The first worksheet could not be read. Save it as CSV.');
        $target = str_starts_with(ltrim($target, '/'), 'xl/') ? ltrim($target, '/') : 'xl/' . $target;
        $shared = audienceXlsxSharedStrings($zip, $nodes);
        $csv = tmpfile();
        if ($csv === false) throw new RuntimeException('Unable to stage the workbook import.');
        $rowDepth = null; $cellDepth = null; $captureDepth = null; $phoneticDepth = null;
        $row = []; $column = 0; $kind = ''; $value = ''; $cellBytes = 0; $cells = 0; $rows = 0;
        $finishCell = static function (string $cellKind, string $cellValue, int $cellColumn) use (&$cellDepth, &$captureDepth, &$row, &$shared): void {
            if ($cellKind === 's') {
                $index = trim($cellValue);
                if (!ctype_digit($index) || strlen($index) > 7 || !array_key_exists((int) $index, $shared)) throw new InvalidArgumentException('The workbook references a missing shared string.');
                $cellValue = $shared[(int) $index];
            }
            $row[$cellColumn - 1] = $cellValue; $cellDepth = null; $captureDepth = null;
        };
        foreach (audienceXlsxNodes($zip, $target, 80 * 1024 * 1024, $nodes) as $node) {
            if ($node->nodeType === XMLReader::ELEMENT) {
                if ($node->localName === 'row') {
                    if ($rowDepth !== null) throw new InvalidArgumentException('The workbook contains nested rows.');
                    if ($node->isEmptyElement) continue;
                    $rowDepth = $node->depth; $row = []; $column = 0;
                } elseif ($node->localName === 'c' && $rowDepth !== null) {
                    if ($cellDepth !== null || ++$cells > AUDIENCE_XLSX_MAX_CELLS) throw new InvalidArgumentException('This workbook has too many cells. Split it into smaller CSV files.');
                    $reference = $node->getAttribute('r'); $nextColumn = $column + 1;
                    if ($reference !== null) {
                        if (!preg_match('/\A([A-Z]{1,3})[1-9][0-9]{0,6}\z/D', $reference, $match)) throw new InvalidArgumentException('The workbook contains an invalid cell reference.');
                        $nextColumn = 0; foreach (str_split($match[1]) as $letter) $nextColumn = $nextColumn * 26 + ord($letter) - 64;
                    }
                    if ($nextColumn > 200 || isset($row[$nextColumn - 1])) throw new InvalidArgumentException('Use no more than 200 distinct columns in each row.');
                    $column = $nextColumn; $cellDepth = $node->depth; $kind = (string) $node->getAttribute('t');
                    $value = ''; $cellBytes = 0; $captureDepth = null; $phoneticDepth = null;
                    if ($node->isEmptyElement) $finishCell($kind, $value, $column);
                } elseif ($cellDepth !== null && $node->localName === 'rPh' && !$node->isEmptyElement) {
                    $phoneticDepth = $node->depth;
                } elseif ($cellDepth !== null && !$node->isEmptyElement
                    && (($node->localName === 'v' && $kind !== 'inlineStr') || ($node->localName === 't' && $kind === 'inlineStr' && $phoneticDepth === null))) {
                    $captureDepth = $node->depth;
                }
            } elseif (audienceXlsxTextNode($node) && $cellDepth !== null) {
                $cellBytes += strlen($node->value);
                if ($cellBytes > AUDIENCE_XLSX_MAX_STRING_BYTES) throw new InvalidArgumentException('A workbook cell is too large. Save a smaller CSV.');
                if ($captureDepth !== null) $value .= $node->value;
            } elseif ($node->nodeType === XMLReader::END_ELEMENT) {
                if ($node->depth === $captureDepth) $captureDepth = null;
                if ($node->depth === $phoneticDepth) $phoneticDepth = null;
                if ($node->localName === 'c' && $node->depth === $cellDepth) $finishCell($kind, $value, $column);
                if ($node->localName === 'row' && $node->depth === $rowDepth) {
                    if ($row !== []) {
                        if (++$rows > 50001) throw new InvalidArgumentException('Import up to 50,000 rows at a time.');
                        $record = array_fill(0, max(array_keys($row)) + 1, '');
                        foreach ($row as $col => $text) $record[$col] = $text;
                        if (fputcsv($csv, $record, ',', '"', '') === false) throw new RuntimeException('Unable to stage the workbook import.');
                        if (ftell($csv) > AUDIENCE_XLSX_MAX_TEXT_BYTES) throw new InvalidArgumentException('The worksheet exceeds 20 MB of CSV data. Split it into smaller files.');
                    }
                    $rowDepth = null;
                }
            }
        }
        if ($rows < 2) throw new InvalidArgumentException('Include headers and at least one connection.');
        unset($shared, $row, $record, $finishCell);
        rewind($csv); $content = stream_get_contents($csv);
        if ($content === false) throw new RuntimeException('Unable to read the workbook import.');
        return audienceParseImport($content);
    } finally {
        if (is_resource($csv)) fclose($csv);
        $zip->close();
    }
}
