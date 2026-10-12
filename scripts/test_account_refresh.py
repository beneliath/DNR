#!/usr/bin/env python3
"""Back up and rebuild only Test Account's fictional records, preserving its users."""
import argparse
import fcntl
import json
from pathlib import Path
import subprocess
import sys

KEY = 'test-account'
PROJECT = 'moed-account-test-account'
# A fixed allowlist; authentication, users, account identity, cost centers,
# standard templates, settings and stored-file inventory are deliberately retained.
TABLES = '''engagement_email_deliveries reimbursement_email_deliveries
engagement_chron_entries organization_chron_entries contact_chron_entries booking_inquiry_chron_entries
reimbursement_submissions reimbursement_request_items reimbursement_receipts reimbursement_expenses reimbursement_requests reimbursement_events
engagement_financial_revisions engagement_financial_drafts engagement_financial_reports
short_link_stats short_link_stats_archive short_link_report_stats short_link_qr_images short_links
document_scan_jobs presentation_notes presentation_slidedecks presentations
engagement_contacts engagement_contact_history follow_up_tasks booking_inquiry_stage_history booking_inquiries
engagement_map_pins engagement_map_geocode_queue engagement_map_geocodes engagements contact_organizations contacts organizations speakers
record_creation_operations record_merge_journal notes_cache_purge_queue inbound_mail_reconciliation inbound_email_quarantine inbound_email_messages'''.split()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=Path('/home/dgilmore/moed'))
    parser.add_argument('--bundle', type=Path)
    parser.add_argument('--as-of')
    parser.add_argument('--apply', action='store_true')
    parser.add_argument('--replace', action='store_true', help='Explicitly discard all Test Account business records after backup')
    args = parser.parse_args()
    root = args.root.resolve(); bundle = (args.bundle or root/'var/demo/test-account').resolve()
    seed = bundle/'test_account_seed.php'; assets=bundle/'assets'
    if not seed.is_file() or not assets.is_dir(): raise ValueError('Missing saved demo seed/assets bundle')
    if not args.apply:
        print(json.dumps({'account':KEY,'replace_business_records':args.replace,'preserve':'users, credentials, MFA, account identity, settings, templates, cost centers','as_of':args.as_of or 'current America/Chicago date','steps':['identity check','deployment lock','pause Test Account writers','encrypted restore-verified backup','explicit reset if requested','date-relative seed','encrypted restore-verified pitstop snapshot','resume Test Account']},indent=2));return
    sys.path.insert(0,str(root/'scripts'))
    from account_production import saved_compose, saved_active_services, clean_environment
    from deployment_backup import query, root_command
    from online_recovery_backup import create_online_backup
    directory=root/'var/accounts'/KEY
    metadata=json.loads((directory/'deployment.json').read_text())
    if metadata.get('account_key')!=KEY or metadata.get('project')!=PROJECT or metadata.get('mode')!='production': raise ValueError('Wrong member deployment')
    web=PROJECT+'-web-1';db=PROJECT+'-db-1'
    if query(db,"SELECT CONCAT(account_key,'/',name) FROM dnr.account_profile WHERE id=1")!='test-account/Test Account': raise ValueError('Wrong database identity')
    if query(db,"SELECT COUNT(*) FROM dnr.users WHERE username='TestAdmin' AND role='admin' AND is_superadmin=0 AND platform_identity_id IS NULL AND account_status='active'")!='1': raise ValueError('Ordinary TestAdmin not found')
    password=directory/'dnr_backup_password_file'
    with (root/'.git/dnr-deploy/deploy.lock').open('a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        dc=saved_compose(directory,PROJECT,metadata)
        services=saved_active_services(directory)
        # Keep web (CLI seeding uses its bounded existing network/file mounts),
        # ingress and downloads stopped to visitors during reset. The pause is
        # targeted to this member; the primary and other accounts keep running.
        workers=[s for s in services if s not in ['web','account-control']]
        subprocess.run(dc+['stop',*workers],check=True,stdout=subprocess.DEVNULL,env=clean_environment())
        committed=False
        try:
            before=create_online_backup(db,web,password,root/'var/backups/test-account-demo-before',metadata['commit'],'demo-pre-refresh')
            if args.replace:
                # Reject any future schema relation into an unlisted business table.
                links=query(db,"SELECT TABLE_NAME,REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='dnr' AND REFERENCED_TABLE_NAME IS NOT NULL")
                unexpected=[(a,b) for a,b in (line.split('\t') for line in links.splitlines()) if b in TABLES and a not in TABLES]
                if unexpected:raise ValueError('Review new business relationships before refresh: '+str(unexpected))
                sql='SET FOREIGN_KEY_CHECKS=0; START TRANSACTION;\n'+''.join('DELETE FROM `'+t+'`;\n' for t in TABLES)+'COMMIT; SET FOREIGN_KEY_CHECKS=1;'
                subprocess.run(root_command(db,'mysql','-uroot','dnr'),input=sql,text=True,check=True)
            subprocess.run(['docker','exec','--user','www-data','-i',web,'sh','-c','cat > /tmp/test-account-seed.php'],input=seed.read_bytes(),check=True)
            archive=subprocess.Popen(['tar','-cf','-','-C',str(assets),'.'],stdout=subprocess.PIPE)
            try:
                subprocess.run(['docker','exec','--user','www-data','-i',web,'sh','-c','mkdir -p /tmp/test-account-demo-assets && tar --no-same-owner --no-same-permissions -xf - -C /tmp/test-account-demo-assets'],stdin=archive.stdout,check=True)
                if archive.wait()!=0:raise ValueError('Asset staging failed')
            finally:archive.stdout.close()
            command=['docker','exec','--user','www-data',web,'php','/tmp/test-account-seed.php','--apply','--assets=/tmp/test-account-demo-assets']
            if args.as_of:command+=['--as-of='+args.as_of]
            result=subprocess.check_output(command,text=True);summary=json.loads(result)
            committed=True
            snapshot=create_online_backup(db,web,password,root/'var/backups/test-account-demo-pitstop',metadata['commit'],'demo-fixture-v1')
            receipt={'seed':summary,'before_backup':before['backup_path'],'pitstop_backup':snapshot['backup_path'],'restore_verified':snapshot['restore_verified']}
            (bundle/'latest-receipt.json').write_text(json.dumps(receipt,indent=2)+'\n')
            print(json.dumps(receipt,indent=2))
        finally:
            if committed:
                subprocess.run(dc+['start',*workers],check=True,stdout=subprocess.DEVNULL,env=clean_environment())
            else:
                print('Test Account remains paused after an incomplete refresh. Restore the saved backup or resolve the seed error, then resume its saved services.',file=sys.stderr)


if __name__=='__main__':main()
