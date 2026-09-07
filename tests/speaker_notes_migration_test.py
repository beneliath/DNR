"""Exercise the real forward SQL against temporary schemas in a disposable MySQL container."""
import os
from pathlib import Path
import secrets
import subprocess
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
from deployment_backup import root_command

def main():
    container = os.environ.get('DNR_MIGRATION_TEST_CONTAINER', '')
    if os.environ.get('DNR_INTEGRATION_TARGET') != 'disposable' or not container:
        print('Speaker Notes migration test skipped (requires a disposable MySQL container).')
        return
    migration = (Path(__file__).resolve().parents[1] / 'migrations/20260907_unify_speaker_notes.sql').read_text()

    def query(sql):
        return subprocess.check_output(root_command(container, 'mysql', '-uroot', '-NBr', '-e', sql), text=True).strip()

    for conflict in (True, False):
        schema = 'notes_migration_test_' + secrets.token_hex(6)
        query(f'CREATE DATABASE {schema}')
        try:
            for table in ('presentations', 'presentation_notes', 'short_links'):
                query(f'CREATE TABLE {schema}.{table} LIKE dnr.{table}')
            if not query(f"SHOW COLUMNS FROM {schema}.presentations LIKE 'slide_deck_pdf'"):
                query(f"ALTER TABLE {schema}.presentations ADD slide_deck_pdf LONGBLOB, ADD slide_deck_filename VARCHAR(255), ADD slide_deck_size INT UNSIGNED, ADD slide_deck_sha256 BINARY(32), ADD slide_deck_updated_at DATETIME(6)")
            query(f'''USE {schema};
                INSERT INTO presentations (id,engagement_id,speaker_id,topic_title,slide_deck_pdf,slide_deck_filename,slide_deck_updated_at,is_archived) VALUES
                (1,1,11,'Legacy only','legacy','legacy.pdf','2026-09-01',0),
                (2,1,11,'Duplicate','same','legacy-name.pdf','2026-09-01',0),
                (3,1,11,'Notes only',NULL,NULL,NULL,0),
                (4,1,11,'No PDF',NULL,NULL,NULL,0),
                (5,1,11,'Empty notes row','recovered','recovered.pdf','2026-09-01',0),
                (6,1,11,'Archived','archived','archived.pdf','2026-09-01',1);
                INSERT INTO presentation_notes (presentation_id,speaker_id,pdf,filename,size,sha256,updated_at) VALUES
                (2,11,'same','notes-name.pdf',4,UNHEX(SHA2('same',256)),'2026-09-02'),
                (3,11,'notes','notes.pdf',5,UNHEX(SHA2('notes',256)),'2026-09-02'),
                (5,11,NULL,NULL,NULL,NULL,'2026-09-01'),
                (6,12,'previous speaker','previous.pdf',16,UNHEX(SHA2('previous speaker',256)),'2026-08-01');
                INSERT INTO short_links (code,engagement_id,presentation_id,speaker_id,link_type,target_url,is_enabled) VALUES
                ('1111111111111111',1,2,11,'notes',NULL,0),
                ('2222222222222222',1,6,12,'notes',NULL,1);
            ''')
            if conflict:
                query(f"UPDATE {schema}.presentation_notes SET pdf='different' WHERE presentation_id=2")
            checksums = query(f'CHECKSUM TABLE {schema}.presentations, {schema}.presentation_notes, {schema}.short_links')
            result = subprocess.run(root_command(container, 'mysql', '-uroot', schema), input=migration, text=True, capture_output=True)
            if conflict:
                assert result.returncode != 0 and 'Distinct presentation PDFs require reconciliation' in result.stderr, result.stderr
                assert query(f'CHECKSUM TABLE {schema}.presentations, {schema}.presentation_notes, {schema}.short_links') == checksums, 'Conflict changed stored files or links'
                assert query(f"SHOW COLUMNS FROM {schema}.presentations LIKE 'slide_deck_pdf'"), 'Conflict dropped legacy data'
            else:
                assert result.returncode == 0, result.stderr
                assert not query(f"SHOW COLUMNS FROM {schema}.presentations LIKE 'slide_deck_pdf'"), 'Legacy store still exists'
                assert query(f'SELECT COUNT(*) FROM {schema}.presentation_notes WHERE pdf IS NOT NULL') == '6'
                assert query(f'SELECT COUNT(*) FROM {schema}.short_links') == '6'
                assert query(f"SELECT CONCAT(filename, '|', DATE(updated_at)) FROM {schema}.presentation_notes WHERE presentation_id=2") == 'notes-name.pdf|2026-09-02'
                for pid, speaker, pdf in [(1,11,'legacy'),(2,11,'same'),(3,11,'notes'),(5,11,'recovered'),(6,11,'archived'),(6,12,'previous speaker')]:
                    assert query(f"SELECT pdf = '{pdf}' AND size = OCTET_LENGTH(pdf) AND sha256 = UNHEX(SHA2(pdf,256)) FROM {schema}.presentation_notes WHERE presentation_id={pid} AND speaker_id={speaker}") == '1'
                assert query(f"SELECT is_enabled FROM {schema}.short_links WHERE code='1111111111111111'") == '0'
                assert query(f"SELECT speaker_id FROM {schema}.short_links WHERE code='2222222222222222'") == '12'
                assert query(f'SELECT COUNT(*) FROM {schema}.short_links WHERE presentation_id=4') == '0'
        finally:
            query(f'DROP DATABASE {schema}')
    print('Speaker Notes migration tests passed: migration, deduplication, metadata, archived files, speaker history, link preservation, and conflict safety.')


if __name__ == "__main__":
    main()
