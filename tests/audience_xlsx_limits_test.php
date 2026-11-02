<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/audience_import.php';
if (!class_exists(ZipArchive::class) || !class_exists(XMLReader::class)) exit("XLSX tests skipped (XMLReader and ZipArchive required).\n");
$checks = 0;
$expect = static function (bool $ok, string $why) use (&$checks): void { if (!$ok) throw new RuntimeException($why); $checks++; };
$paths = [];
$workbook = static function (string $sheet, ?string $shared = null, string $target = 'worksheets/sheet1.xml', string $mode = '') use (&$paths): string {
    $path = tempnam(sys_get_temp_dir(), 'dnr-xlsx-regression-'); $paths[] = $path;
    $zip = new ZipArchive(); $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships><Relationship Id="rId1" Target="' . $target . '" TargetMode="' . $mode . '"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    if ($shared !== null) $zip->addFromString('xl/sharedStrings.xml', $shared);
    $zip->close(); return $path;
};
$reject = static function (string $path, string $why) use ($expect): void {
    try { audienceParseXlsx($path); } catch (InvalidArgumentException $error) { $expect(true, $why); return; }
    throw new RuntimeException($why);
};
$sheet = '<worksheet><sheetData><row><c r="A1" t="inlineStr"><is><t>Email</t></is></c><c r="B1" t="inlineStr"><is><t>Name</t></is></c></row><row><c r="A2" t="s"><v>0</v></c><c r="B2" t="inlineStr"><is><r><t>Avery </t></r><r><t>Rivéra</t></r><rPh><t>ignored pronunciation</t></rPh></is></c></row></sheetData></worksheet>';
$shared = '<sst><si><r><t>synthetic</t></r><r><t>@example.test</t></r></si></sst>';
try {
    $parsed = audienceParseXlsx($workbook($sheet, $shared));
    $expect($parsed === ['headers' => ['Email', 'Name'], 'rows' => [['synthetic@example.test', 'Avery Rivéra']]], 'Shared/inline rich strings preserve text, Unicode and whitespace without phonetic annotation.');
    $reject($workbook($sheet, $shared, 'https://example.test/sheet.xml', 'External'), 'External worksheet relationships remain forbidden.');
    $reject($workbook($sheet, $shared, '../worksheets/sheet1.xml'), 'Unsafe worksheet paths remain forbidden.');
    $reject($workbook(str_replace('B2', 'GS2', $sheet), $shared), 'Every worksheet cell respects the 200-column ceiling.');
    $reject($workbook(str_replace('<v>0</v>', '<v>99</v>', $sheet), $shared), 'Missing shared-string references produce an error instead of losing values.');
    $reject($workbook(str_replace('</worksheet>', '', $sheet), $shared), 'Malformed XML cannot become a partial import.');
    $entities = '<!DOCTYPE sst [<!ENTITY fixture "synthetic@example.test">]><sst><si><t>&fixture;</t></si></sst>';
    $reject($workbook($sheet, $entities), 'Document entities remain forbidden.');
    $reject($workbook($sheet, mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?>' . $entities, 'UTF-16', 'UTF-8')), 'Entity rejection also applies to UTF-16 documents.');
    $reject($workbook($sheet, '<sst><si><t>' . str_repeat('x', 131073) . '</t></si></sst>'), 'Individual decoded strings are bounded before they accumulate.');
    // Harmless unused values exercise the structural budget without any network access.
    $reject($workbook($sheet, '<sst>' . str_repeat('<si><t>x</t></si>', 500001) . '</sst>'), 'Unused shared strings have their own count limit.');
    $expect(memory_get_peak_usage(true) < 128 * 1024 * 1024, 'Structural rejection stays inside a bounded memory budget.');
    echo "Streaming XLSX import limits: $checks checks passed.\n";
} finally { foreach ($paths as $path) unlink($path); }
