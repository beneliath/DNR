<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/presentation_slidedeck_helpers.php';
require_once __DIR__ . '/presentation_slidedeck_fixture.php';

function expectSlidedeck(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}
$path = tempnam(sys_get_temp_dir(), 'dnr-ppt-');
try {
    writeTestSlidedeck($path);
    $asset = presentationSlidedeckFromPath($path, '../../test "deck".PPTX', false);
    expectSlidedeck($asset['filename'] === 'test _deck_.pptx' && $asset['mime_type'] === PRESENTATION_SLIDEDECK_MIMES['pptx'], 'PowerPoint names and MIME types are normalized.');
    expectSlidedeck($asset['size'] === filesize($path) && $asset['sha256'] === hash_file('sha256', $path, true), 'Metadata matches exact uploaded bytes.');
    foreach (['deck.pdf', 'deck.pptm', 'deck.ppt'] as $name) {
        try { presentationSlidedeckFromPath($path, $name, false); throw new RuntimeException('Wrong file type accepted.'); }
        catch (InvalidArgumentException $expected) {}
    }
    try { presentationSlidedeckFromPath($path, 'deck.pptx'); throw new RuntimeException('Non-upload accepted.'); }
    catch (InvalidArgumentException $expected) {}
    foreach (['', '<html>not PowerPoint</html>', "%PDF-1.4\n", "PK\x03\x04truncated"] as $data) {
        file_put_contents($path, $data);
        clearstatcache();
        try { presentationSlidedeckFromPath($path, 'deck.pptx', false); throw new RuntimeException('Invalid PowerPoint accepted.'); }
        catch (InvalidArgumentException $expected) {}
    }
    $file = fopen($path, 'wb'); ftruncate($file, PRESENTATION_SLIDEDECK_MAX_BYTES + 1); fclose($file); clearstatcache();
    try { presentationSlidedeckFromPath($path, 'deck.pptx', false); throw new RuntimeException('Oversized file accepted.'); }
    catch (InvalidArgumentException $expected) { expectSlidedeck(str_contains($expected->getMessage(), '100 MB'), 'Size limit has useful feedback.'); }
    unlink($path);
    $zip = new ZipArchive(); $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('word/document.xml', '<document/>'); $zip->close(); clearstatcache();
    try { presentationSlidedeckFromPath($path, 'deck.pptx', false); throw new RuntimeException('Non-PowerPoint ZIP accepted.'); }
    catch (InvalidArgumentException $expected) {}
    $zip = new ZipArchive(); $zip->open($path);
    $zip->addFromString('[Content_Types].xml', ''); $zip->addFromString('ppt/presentation.xml', ''); $zip->close();
    try { presentationSlidedeckFromPath($path, 'deck.pptx', false); throw new RuntimeException('Empty XML parts accepted.'); }
    catch (InvalidArgumentException $expected) {}
    // A legacy OLE presentation is accepted separately from ZIP-based PPTX.
    file_put_contents($path, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 504)
        . mb_convert_encoding('PowerPoint Document', 'UTF-16LE', 'UTF-8') . str_repeat("\0", 512));
    clearstatcache();
    $legacy = presentationSlidedeckFromPath($path, 'legacy.ppt', false);
    expectSlidedeck($legacy['mime_type'] === PRESENTATION_SLIDEDECK_MIMES['ppt'], 'Legacy PPT files retain their PowerPoint MIME type.');
    file_put_contents($path, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 512));
    clearstatcache();
    try { presentationSlidedeckFromPath($path, 'spreadsheet.ppt', false); throw new RuntimeException('Non-PowerPoint OLE accepted.'); }
    catch (InvalidArgumentException $expected) {}
    $row = ['_form_key' => '2', 'id' => 3];
    $changes = attachPresentationAssetChanges([$row], ['2' => ['remove_ppt_slidedeck' => '1']], []);
    expectSlidedeck($changes[0]['asset_changes'] === ['ppt_slidedeck' => ['action' => 'remove']], 'PPT removal never removes notes.');
    expectSlidedeck(presentationAssetDefinitionForQueryType('slides')['form_key'] === 'speaker_notes', 'Legacy PDF URLs remain compatible.');
    expectSlidedeck(presentationAssetDefinitionForQueryType('slidedeck')['form_key'] === 'ppt_slidedeck', 'New PPT endpoint is separate.');
    $presentation_dom_id = 7; $is_saved_presentation = true;
    $presentation = ['id' => 123, 'has_ppt_slidedeck' => true, 'ppt_slidedeck_filename' => 'deck.pptx', 'ppt_slidedeck_size' => 2097152];
    ob_start(); include __DIR__ . '/../src/templates/presentation_slidedeck_upload.php'; $markup = ob_get_clean();
    expectSlidedeck(str_contains($markup, 'presentations[7][ppt_slidedeck]') && str_contains($markup, 'presentations[7][remove_ppt_slidedeck]') && str_contains($markup, 'id=123&amp;type=slidedeck') && str_contains($markup, '2.0 MB'), 'Upload, replacement, removal and view refer to the same presentation.');
    echo "PPT Slidedeck validation and form tests passed.\n";
} finally { @unlink($path); }
