#!/usr/bin/env python3
"""Read s1 feedback without installing files or changing production review state.

Default output is private operational evidence. Do not save it in tracked files.
"""
import argparse
import json
import subprocess
from pathlib import Path

PHP = r'''<?php
declare(strict_types=1);
require_once '/var/www/html/bootstrap.php';
require_once '/var/www/html/ai_coach_helpers.php';
require_once '/var/www/html/ai_coach_feedback_helpers.php';
try {
    $conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY);
    // Export only packet inputs; group them with the local compatible reader.
    // No accounts, credentials, or other request columns cross this boundary.
    $fields = array_flip(['id','question','page_path','guided_step','user_role','response_json',
        'feedback_context_json','conversation_json','user_feedback','review_status','outcome',
        'duration_ms','feedback_version','review_version','created_at','model_name',
        'application_version','ui_state_json','review_notes','corrected_guidance','guidance_revision']);
    $rows = array_map(static fn($row)=>array_intersect_key($row,$fields), aiCoachFeedbackPending($conn, 100));
    $reviews = $conn->query('SELECT request_id,disposition,summary,evidence_json,guidance_revision,reviewed_at FROM ai_coach_feedback_reviews ORDER BY id DESC LIMIT 20')->fetch_all(MYSQLI_ASSOC);
    $result = ['source'=>['host'=>'192.168.1.150','container'=>'moed-web-1',
        'environment'=>'production','application_version'=>trim(file_get_contents('/opt/dnr/VERSION'))],
        'production_guidance_revision'=>aiCoachGuidanceRevision(), 'pending_rows'=>$rows,'recent_reviews'=>$reviews];
    $conn->rollback();
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
} catch (Throwable $exception) {
    $conn->rollback();
    fwrite(STDERR, "Production feedback read failed: " . $exception->getMessage() . "\n");
    exit(1);
}
'''



def build_packet(data):
    """Group production evidence locally without requiring a production helper rollout."""
    source = data['source']
    if (source.get('host'), source.get('container'), source.get('environment')) != (
            '192.168.1.150', 'moed-web-1', 'production'):
        raise ValueError('Unexpected production source metadata')
    revision = data['production_guidance_revision']
    if not isinstance(revision, str) or len(revision) != 64:
        raise ValueError('Missing production guidance revision')
    rows = data['pending_rows']
    if not isinstance(rows, list) or len(rows) > 100:
        raise ValueError('Expected a bounded production feedback batch')
    root = Path(__file__).resolve().parents[2]
    php = """require_once $argv[1]; require_once $argv[2];
$rows=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
echo json_encode(aiCoachFeedbackPacket($rows),JSON_THROW_ON_ERROR);"""
    result = subprocess.run(['php', '-r', php, str(root/'src/ai_coach_helpers.php'),
                             str(root/'src/ai_coach_feedback_helpers.php')],
                            input=json.dumps(rows), text=True, capture_output=True,
                            timeout=30, check=True)
    packet = json.loads(result.stdout)
    packet['builder_guidance_revision'] = packet['guidance_revision']
    packet['builder_source'] = 'local_worktree'
    packet['guidance_revision'] = revision
    return {'source': source, 'packet': packet, 'recent_reviews': data['recent_reviews']}

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
        data = build_packet(json.loads(result.stdout))
        if args.summary:
            data = {'source': data['source'], 'guidance_revision': data['packet']['guidance_revision'],
                    'pending_in_batch': data['packet']['pending_in_batch'],
                    'recent_review_count': len(data['recent_reviews'])}
        print(json.dumps(data, indent=2))
    except subprocess.TimeoutExpired:
        raise SystemExit('Production feedback intake or local grouping timed out after 30 seconds; no local-data fallback was used.')
    except subprocess.CalledProcessError as error:
        raise SystemExit(f'Production feedback intake or local grouping failed (exit {error.returncode}): {error.stderr.strip()}')
    except (ValueError, KeyError) as error:
        raise SystemExit(f'Production feedback response was invalid: {error}')


if __name__ == '__main__':
    main()
