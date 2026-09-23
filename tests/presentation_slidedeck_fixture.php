<?php
declare(strict_types=1);

function writeTestSlidedeck(string $path, string $title = 'Test presentation'): void
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Unable to create fixture.');
    $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/></Types>');
    $zip->addFromString('ppt/presentation.xml', '<p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"><p:sldIdLst/></p:presentation>');
    $zip->addFromString('docProps/title.txt', $title);
    $zip->close();
}

/** Stored ZIP padding exercises actual large on-disk files without a large PHP string. */
function writeSizedTestSlidedeck(string $path, int $bytes): void
{
    $padding = tempnam(sys_get_temp_dir(), 'ppt-padding-');
    try {
        $addPadding = static function () use ($path, $padding): void {
            // Rebuild instead of replacing an entry: libzip can retain a replaced entry's default compression.
            writeTestSlidedeck($path);
            $zip = new ZipArchive();
            if ($zip->open($path) !== true || !$zip->addFile($padding, 'ppt/media/padding.bin')
                || !$zip->setCompressionName('ppt/media/padding.bin', ZipArchive::CM_STORE) || !$zip->close()) {
                throw new RuntimeException('Unable to build large PPTX fixture.');
            }
        };
        // Measure a nonempty stored entry: ZIP metadata for an empty entry varies by libzip version.
        $file = fopen($padding, 'wb'); ftruncate($file, 1024); fclose($file);
        $addPadding(); clearstatcache(true, $path);
        $length = $bytes - filesize($path) + 1024;
        if ($length < 1) throw new InvalidArgumentException('Fixture size is too small.');
        $file = fopen($padding, 'wb'); ftruncate($file, $length); fclose($file);
        $addPadding(); clearstatcache(true, $path);
        if (filesize($path) !== $bytes) throw new RuntimeException('Unexpected PPTX fixture size: '.filesize($path).' instead of '.$bytes.'.');
    } finally { @unlink($padding); }
}

/** A structurally valid CFB v4 presentation with a large document container. */
function writeSizedLegacySlidedeck(string $path, int $bytes): void
{
    if ($bytes % 4096 !== 0 || $bytes < 1024 * 1024) throw new InvalidArgumentException('Use a sector-aligned fixture size.');
    $sectors = intdiv($bytes, 4096) - 1;
    $fatCount = (int) ceil($sectors / 1024);
    $difatCount = (int) ceil(max(0, $fatCount - 109) / 1023);
    $fatStart = $sectors - $fatCount - $difatCount;
    $difatStart = $fatStart + $fatCount;
    $docSize = ($fatStart - 2) * 4096;
    $set = static function (string &$buffer, int $offset, string $value): void { $buffer = substr_replace($buffer, $value, $offset, strlen($value)); };
    $header = str_repeat("\0", 4096);
    $set($header, 0, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1");
    foreach ([24=>0x3e,26=>4,28=>0xfffe,30=>12,32=>6] as $offset=>$value) $set($header,$offset,pack('v',$value));
    foreach ([40=>1,44=>$fatCount,48=>0,56=>4096,60=>0xfffffffe,64=>0,68=>$difatCount ? $difatStart : 0xfffffffe,72=>$difatCount] as $offset=>$value) $set($header,$offset,pack('V',$value));
    for ($i=0;$i<109;$i++) $set($header,76+$i*4,pack('V',$i<$fatCount?$fatStart+$i:0xffffffff));
    $entry = static function (string $name, int $type, int $right, int $child, int $start, int $size) use ($set): string {
        $row=str_repeat("\0",128); $name=mb_convert_encoding($name."\0",'UTF-16LE','UTF-8');
        $set($row,0,$name);$set($row,64,pack('v',strlen($name)));$row[66]=chr($type);$row[67]=chr(1);
        foreach ([68=>0xffffffff,72=>$right,76=>$child,116=>$start,120=>$size] as $o=>$v) $set($row,$o,pack('V',$v));
        return $row;
    };
    $file=fopen($path,'wb');
    try {
        ftruncate($file,$bytes); fwrite($file,$header);
        fwrite($file,$entry('Root Entry',5,0xffffffff,1,0xfffffffe,0)
            .$entry('PowerPoint Document',2,2,0xffffffff,2,$docSize)
            .$entry('Current User',2,0xffffffff,0xffffffff,1,4096));
        fseek($file,2*4096); fwrite($file,pack('vvV',0,4086,20).str_repeat("\0",20));
        fseek($file,3*4096); fwrite($file,pack('vvV',0,4085,28).str_repeat("\0",28).pack('vvV',15,1000,$docSize-44));
        for ($sector=0;$sector<$fatCount;$sector++) {
            $values=[];
            for($i=0;$i<1024;$i++) {
                $id=$sector*1024+$i;
                $values[]=$id>=$sectors?0xffffffff:($id>=$difatStart?0xfffffffc:($id>=$fatStart?0xfffffffd:($id<2||$id===$fatStart-1?0xfffffffe:$id+1)));
            }
            fseek($file,($fatStart+$sector+1)*4096); fwrite($file,pack('V*',...$values));
        }
        for($sector=0;$sector<$difatCount;$sector++) {
            $values=[];
            for($i=0;$i<1023;$i++) { $index=109+$sector*1023+$i; $values[]=$index<$fatCount?$fatStart+$index:0xffffffff; }
            $values[]=$sector+1<$difatCount?$difatStart+$sector+1:0xfffffffe;
            fseek($file,($difatStart+$sector+1)*4096); fwrite($file,pack('V*',...$values));
        }
    } finally { fclose($file); }
    clearstatcache(true,$path);
}
