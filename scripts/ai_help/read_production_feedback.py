#!/usr/bin/env python3
"""Read s1 feedback without installing files or changing production review state.

Default output is private operational evidence. Do not save it in tracked files.
"""
import argparse
import json
import subprocess

PHP = r'''<?php
declare(strict_types=1);
require_once '/var/www/html/bootstrap.php';
require_once '/var/www/html/ai_coach_helpers.php';
require_once '/var/www/html/ai_coach_feedback_helpers.php';
try {
    $conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY);
    $packet = aiCoachFeedbackPacket(aiCoachFeedbackPending($conn, 100));
    $reviews = $conn->query('SELECT request_id,disposition,summary,evidence_json,guidance_revision,reviewed_at FROM ai_coach_feedback_reviews ORDER BY id DESC LIMIT 20')->fetch_all(MYSQLI_ASSOC);
    $result = ['source'=>['host'=>'192.168.1.150','container'=>'moed-web-1',
        'environment'=>'production','application_version'=>trim(file_get_contents('/opt/dnr/VERSION'))],
        'packet'=>$packet,'recent_reviews'=>$reviews];
    $conn->rollback();
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
} catch (Throwable $exception) {
    $conn->rollback();
    fwrite(STDERR, "Production feedback read failed: " . $exception->getMessage() . "\n");
    exit(1);
}
'''


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--summary', action='store_true', help='Print counts and source only; omit private feedback')
    args = parser.parse_args()
    try:
        result = subprocess.run([
            'ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=8',
            '-o', 'ConnectionAttempts=1', '-o', 'StrictHostKeyChecking=yes',
            'dgilmore@192.168.1.150',
            'docker exec -i -u www-data moed-web-1 php',
        ], input=PHP, text=True, capture_output=True, timeout=30, check=True)
        data = json.loads(result.stdout)
        if args.summary:
            data = {'source': data['source'], 'guidance_revision': data['packet']['guidance_revision'],
                    'pending_in_batch': data['packet']['pending_in_batch'],
                    'recent_review_count': len(data['recent_reviews'])}
        print(json.dumps(data, indent=2))
    except subprocess.TimeoutExpired:
        raise SystemExit('Production feedback read timed out after 30 seconds; no local-data fallback was used.')
    except subprocess.CalledProcessError as error:
        raise SystemExit(f'Production feedback read failed (exit {error.returncode}): {error.stderr.strip()}')
    except (ValueError, KeyError) as error:
        raise SystemExit(f'Production feedback response was invalid: {error}')


if __name__ == '__main__':
    main()
