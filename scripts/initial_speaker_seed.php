<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$source = is_file('/var/www/html/bootstrap.php') ? '/var/www/html' : dirname(__DIR__) . '/src';
require_once $source . '/bootstrap.php';
require_once $source . '/speaker_seed_helpers.php';
$conn = applicationDatabaseConnection();
try {
    $action = $argv[1] ?? '';
    if ($action === 'export') {
        echo json_encode(exportInitialSpeakerSeed($conn), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
    } elseif ($action === 'validate' || $action === 'apply') {
        $path = $argv[2] ?? '';
        if (!is_file($path) || filesize($path) > 8 * 1024 * 1024) throw new RuntimeException('Invalid speaker seed file.');
        $seed = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($seed)) throw new RuntimeException('Invalid speaker seed document.');
        validateInitialSpeakerSeed($seed);
        if ($action === 'validate') {
            echo "Initial speaker seed valid.\n";
        } else {
            $conn->begin_transaction();
            try {
                $result = applyInitialSpeakerSeed($conn, $seed);
                $conn->commit();
                echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
            } catch (Throwable $error) {
                $conn->rollback();
                throw $error;
            }
        }
    } else {
        throw new InvalidArgumentException('Usage: initial_speaker_seed.php export | validate FILE | apply FILE');
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
