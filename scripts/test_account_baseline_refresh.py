#!/usr/bin/env python3
"""Restore the immutable Test Account baseline; default to an isolated rehearsal."""
import argparse,calendar,datetime,fcntl,hashlib,json,os,re,shutil,signal,subprocess,sys,tarfile,tempfile,time
from pathlib import Path
from zoneinfo import ZoneInfo
ROOT=Path('/home/dgilmore/moed');sys.path.insert(0,str(ROOT/'scripts'))
from deployment_backup import query,root_command,crypto_command,sha
from online_recovery_backup import create_online_backup,restored_database,upload_volume
from account_production import saved_compose,saved_active_services,clean_environment
KEY='test-account';PROJECT='moed-account-test-account';WEB=PROJECT+'-web-1';DB=PROJECT+'-db-1'
PRIMARY_WEB='moed-web-1'
BASE=ROOT/'var/backups/test-account-demo-baseline/20261013T002933Z-9522b69c'
BUNDLE=ROOT/'var/demo/test-account';OPS=Path(__file__).resolve().parent
TABLES='''organizations contacts contact_organizations speakers engagements engagement_contacts engagement_contact_history
engagement_map_pins engagement_map_geocodes engagement_map_geocode_queue follow_up_tasks booking_inquiries
booking_inquiry_stage_history booking_inquiry_chron_entries engagement_chron_entries organization_chron_entries contact_chron_entries
engagement_email_messages engagement_email_deliveries inbound_email_messages inbound_email_import_receipts
short_links short_link_stats short_link_stats_archive short_link_qr_images presentations presentation_notes presentation_slidedecks
engagement_financial_reports engagement_financial_drafts engagement_financial_revisions
reimbursement_setup reimbursement_cost_centers reimbursement_expenses reimbursement_requests reimbursement_request_items
reimbursement_receipts reimbursement_events reimbursement_submissions standard_event_tasks'''.split()
PRESERVE=['users','account_profile','calendar_subscriptions']
# Operational rows can refer to discarded tester data. Clear, never restore/replay.
CLEAR_TABLES='''document_scan_jobs reimbursement_email_deliveries
mattermost_post_reaction_notifications mattermost_reply_notifications
record_creation_operations record_merge_journal notes_cache_purge_queue
inbound_email_quarantine inbound_mail_reconciliation notification_outbox'''.split()
# These inbox records are scoped to inbound mail that the refresh replaces. They
# must not retain stale references or make old mail look newly received/routed.
CLEAR_TABLES += ['marketing_inbox_inbound_parts','marketing_inbox_route_receipts']
STATE=Path.home()/'.local/state/moed-test-account-refresh'

def require(condition,message):
 if not condition:raise RuntimeError(message)

def month_shift(anchor,months):
 index=anchor.year*12+anchor.month-1+months
 year,month=divmod(index,12);month+=1
 return datetime.date(year,month,min(anchor.day,calendar.monthrange(year,month)[1]))

def code_hash():
 digest=hashlib.sha256()
 for name in ['test_account_baseline_refresh.py','test_account_baseline_remap.php','test_account_set_owner.php','test_account_task_samples.php','test_account_source_fingerprint.php','install_test_account_refresh_schedule.py']:
  digest.update(name.encode());digest.update((OPS/name).read_bytes())
 return digest.hexdigest()

def save_state(name,value):
 STATE.mkdir(mode=0o700,parents=True,exist_ok=True)
 temporary=STATE/(name+'.tmp')
 temporary.write_text(json.dumps(value,indent=2)+'\n');temporary.replace(STATE/name)

def live_php(source):
 result=subprocess.run(['docker','exec','-i',WEB,'php','/dev/stdin'],input=source,text=True,capture_output=True)
 require(result.returncode==0,(result.stdout+result.stderr)[-2000:])
 return json.loads(result.stdout)

POLICY_CHECK='''<?php require '/var/www/html/bootstrap.php'; require_once '/var/www/html/dashboard_helpers.php';
$result=[]; foreach(['TestAdmin'=>'admin','TestEditor'=>'editor','TestReviewer'=>'reviewer'] as $name=>$role){
 $u=$conn->execute_query('SELECT * FROM users WHERE username=?',[$name])->fetch_assoc();
 if(!$u || $u['role']!==$role || !demoPasswordOnlyAuthenticationAllowed($u)) throw new RuntimeException('Demo identity/pin changed');
 $result[$name]=['role'=>$role,'password_only_allowed'=>true];
}
if(accountSecurityAccessAllowed() || accountMattermostAccessAllowed()) throw new RuntimeException('Demo restrictions changed');
echo json_encode(['demo_logins'=>$result,'account_security_forbidden'=>true,'mattermost_forbidden'=>true]);'''

def service_health(services):
 for service in services:
  state=inspect(PROJECT+'-'+service+'-1')['State']
  require(state['Running'] and state.get('Health',{}).get('Status','healthy')=='healthy','Unhealthy Test Account service: '+service)

def check_external_references():
 links=query(DB,"SELECT TABLE_NAME,REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='dnr' AND REFERENCED_TABLE_NAME IS NOT NULL")
 reset_tables=set(TABLES+CLEAR_TABLES)
 unexpected=[line for line in links.splitlines() if line.split('\t')[1] in reset_tables and line.split('\t')[0] not in reset_tables]
 require(not unexpected,'Review new business references before refresh: '+str(unexpected))

MIGRATIONS_SQL='SELECT migration_name,checksum,state FROM dnr.schema_migrations ORDER BY migration_name'
SCHEMA_COLUMNS_SQL="""SELECT TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,
 COALESCE(COLUMN_DEFAULT,'<NULL>'),EXTRA,COALESCE(COLLATION_NAME,'')
 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='dnr' ORDER BY TABLE_NAME,ORDINAL_POSITION"""

def migration_plan(baseline_ledger,current_ledger):
 def parse(ledger):
  result={}
  for line in ledger.splitlines():
   fields=line.split('\t')
   require(len(fields)==3,'Invalid migration ledger row')
   name,checksum,state=fields
   require(name not in result and re.fullmatch(r'[A-Za-z0-9_.-]+\.sql',name) and re.fullmatch(r'[a-f0-9]{64}',checksum),'Invalid migration ledger identity')
   require(state=='applied','Review incomplete migration before refresh: '+name)
   result[name]=checksum
  require(bool(result),'Migration ledger is empty')
  return result
 baseline=parse(baseline_ledger);current=parse(current_ledger)
 require(all(current.get(name)==checksum for name,checksum in baseline.items()),'Applied baseline migration changed or is missing from current schema')
 return {'baseline_migration_count':len(baseline),'current_migration_count':len(current),
         'added_migrations':sorted(set(current)-set(baseline))}

def upgrade_staged_schema(staged,current_ledger,current_columns,baseline_ledger):
 # The production DB is never eligible for this migration command. The restore
 # helper creates this labeled container with no network and an ephemeral DB.
 info=inspect(staged)
 require(staged.startswith('dnr-online-restore-nightly-baseline-stage-')
         and info['HostConfig']['NetworkMode']=='none'
         and info['Config']['Labels'].get('org.dnr.recovery-owner')=='test-account-baseline-refresh',
         'Schema upgrades require the owned network-isolated disposable restore')
 require(query(staged,MIGRATIONS_SQL)==baseline_ledger,'Restored migration ledger differs from approved baseline')
 plan=migration_plan(baseline_ledger,current_ledger)
 if plan['added_migrations']:
  # Production supplies the qualified migrator through frozen, read-only binds.
  # Copy this member's exact files into the disposable DB; do not mount live DB
  # storage or use an independently versioned set of migration files.
  targets=['/opt/dnr/migrations','/opt/dnr/bin/migrate']
  mounts={m['Destination']:Path(m['Source']).resolve() for m in inspect(DB)['Mounts']
          if m['Type']=='bind' and not m.get('RW',True) and m['Destination'] in targets}
  frozen=(ROOT/'var/accounts'/KEY/'runtime-config').resolve()
  require(all(t in mounts and mounts[t].is_relative_to(frozen) for t in targets),'Current Test Account qualified migration binds are unavailable')
  migration_directory='/tmp/test-account-refresh-migrations'
  migration_runner='/tmp/test-account-refresh-migrate.sh'
  subprocess.run(['docker','cp',str(mounts[targets[0]]),staged+':'+migration_directory],check=True,capture_output=True)
  subprocess.run(['docker','cp',str(mounts[targets[1]]),staged+':'+migration_runner],check=True,capture_output=True)
  # Reuse the release's ordered/checksummed/locked migrator. This isolated
  # root-only fixture has no application DB identities, so its grants phase is
  # a harmless statement; production privilege configuration remains unchanged.
  grants='/tmp/test-account-refresh-grants.sh'
  source='#!/bin/sh\nprintf "SELECT 1;\\n" > "$DNR_PRIVILEGE_SQL_FILE"\n'
  subprocess.run(root_command(staged,'sh','-c','cat > /tmp/test-account-refresh-grants.sh'),input=source,text=True,check=True)
  command=root_command(staged,'env','MYSQL_USER=dnruser','DNR_REQUIRE_DATABASE_ENCRYPTION=0',
                       'DNR_PRIVILEGE_SCRIPT='+grants,'DNR_MIGRATION_DIRECTORY='+migration_directory,
                       'DNR_MIGRATION_ORDER_FILE='+migration_directory+'/order.txt','sh',migration_runner)
  result=subprocess.run(command,text=True,capture_output=True)
  require(result.returncode==0,'Isolated baseline migration failed: '+(result.stdout+result.stderr)[-2500:])
 require(query(staged,MIGRATIONS_SQL)==current_ledger,'Migrated baseline ledger does not match the current Test Account schema')
 require(query(staged,SCHEMA_COLUMNS_SQL)==current_columns,'Migrated baseline columns do not match the current Test Account schema')
 return dict(plan,isolated=True,migration_ledger_verified=True,schema_columns_verified=True)

def deployment_lock(lock):
 deadline=time.monotonic()+300
 while True:
  try:
   fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
   return
  except BlockingIOError:
   if time.monotonic()>=deadline:raise RuntimeError('Deployment lock remained busy for five minutes; no data replaced')
   time.sleep(2)

def date_relationships(db):
 return [query(db,sql) for sql in [
  'SELECT id,DATEDIFF(event_end_date,event_start_date) FROM dnr.engagements ORDER BY id',
  'SELECT p.id,DATEDIFF(p.presentation_date,e.event_start_date) FROM dnr.presentations p JOIN dnr.engagements e ON e.id=p.engagement_id ORDER BY p.id',
  'SELECT t.id,DATEDIFF(t.due_date,e.event_start_date) FROM dnr.follow_up_tasks t JOIN dnr.engagements e ON e.id=t.engagement_id WHERE t.inquiry_id IS NULL ORDER BY t.id',
  'SELECT i.id,DATEDIFF(i.preferred_start_date,e.event_start_date) FROM dnr.booking_inquiries i JOIN dnr.engagements e ON e.id=i.converted_engagement_id ORDER BY i.id',
  'SELECT id,DATEDIFF(preferred_end_date,preferred_start_date),DATEDIFF(alternate_start_date,preferred_start_date),DATEDIFF(alternate_end_date,preferred_start_date) FROM dnr.booking_inquiries ORDER BY id',
  'SELECT id,DATEDIFF(end_date,start_date) FROM dnr.reimbursement_requests ORDER BY id']]
def inspect(name):return json.loads(subprocess.check_output(['docker','inspect',name]))[0]
def execute(db,sql):subprocess.run(root_command(db,'mysql','-uroot','dnr'),input=sql,text=True,check=True,stdout=subprocess.DEVNULL)
def table_state(db,tables):return {t:query(db,'CHECKSUM TABLE dnr.`'+t+'`').split('\t')[-1] for t in tables}
def dump(db,tables):
 return subprocess.check_output(root_command(db,'mysqldump','-uroot','--no-create-info','--skip-triggers','--skip-add-locks','--skip-disable-keys','--skip-lock-tables','--complete-insert','--hex-blob','--set-gtid-purged=OFF','--no-tablespaces','--replace','dnr',*tables))
def source_state():
 return subprocess.check_output(['docker','exec','-i',PRIMARY_WEB,'php','/dev/stdin'],input=(OPS/'test_account_source_fingerprint.php').read_bytes())
def stage_php(db,work,image,source,args=()):
 command=['docker','run','--rm','-i','--network','container:'+db,'--read-only','--user',str(os.getuid())+':'+str(os.getgid()),'--cap-drop','ALL','--security-opt','no-new-privileges',
  '--tmpfs','/tmp:rw,nosuid,mode=1777','--tmpfs','/var/lib/php/sessions:rw,nosuid,mode=1777',
  '--mount','type=bind,src='+str(work/'files')+',dst=/var/lib/dnr/files',
  '--mount','type=bind,src='+str(work/'restore-root-password')+',dst=/run/secrets/password,readonly',
  '--mount','type=bind,src='+str(ROOT/'var/accounts/test-account/dnr_2fa_key_file')+',dst=/run/secrets/key,readonly',
  '--mount','type=bind,src='+str(work/'tables.json')+',dst=/run/tables.json,readonly',
  '-e','DB_HOST=127.0.0.1','-e','MYSQL_USER=root','-e','MYSQL_PASSWORD_FILE=/run/secrets/password','-e','MYSQL_DATABASE=dnr',
  '-e','DNR_ACCOUNTS_ENABLED=1','-e','DNR_ACCOUNT_MODE=member','-e','DNR_ACCOUNT_KEY=test-account',
  '-e','DNR_2FA_ENCRYPTION_KEY_FILE=/run/secrets/key','-e','DNR_REQUIRE_DATABASE_ENCRYPTION=0','-e','DNR_REQUIRE_STORAGE_MONITOR=0',
  '-e','DNR_MAIL_TRANSPORT=log','-e','DNR_REQUIRE_HTTPS=0','-e','DNR_PUBLIC_BASE_URL=http://localhost/a/test-account',
  '--entrypoint','php',image,'/dev/stdin',*args]
 result=subprocess.run(command,input=source,text=True,capture_output=True)
 if result.returncode:raise RuntimeError((result.stdout+result.stderr)[-3500:])
 return json.loads(result.stdout)
def foreign_key_errors(db):
 result=[]
 links=query(db,"SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='dnr' AND REFERENCED_TABLE_NAME IS NOT NULL").splitlines()
 for line in links:
  table,column,parent,key=line.split('\t')
  if parent not in TABLES+['stored_files','users']:continue
  count=int(query(db,f'SELECT COUNT(*) FROM dnr.`{table}` c LEFT JOIN dnr.`{parent}` p ON p.`{key}`=c.`{column}` WHERE c.`{column}` IS NOT NULL AND p.`{key}` IS NULL'))
  if count:result.append([table,column,parent,count])
 return result
def validate(db,anchor):
 window=[month_shift(anchor,-3).isoformat(),month_shift(anchor,9).isoformat()]
 assert query(db,"SELECT MIN(event_start_date),MAX(event_end_date) FROM dnr.engagements")=='\t'.join(window),'Date coverage failed'
 assert query(db,"SELECT COUNT(DISTINCT DATE_FORMAT(event_start_date,'%Y-%m')) FROM dnr.engagements")=='13','Missing month'
 assert query(db,'SELECT COUNT(*) FROM dnr.follow_up_tasks')=='155','Wrong task total'
 assert query(db,"SELECT COUNT(*) FROM dnr.follow_up_tasks t JOIN dnr.users u ON u.id=t.assigned_to WHERE t.title LIKE '[Demo team task] %' AND u.username IN ('TestAdmin','TestReviewer') AND t.status IN ('open','in_progress','waiting') AND t.is_archived=0")=='6','Task examples missing'
 assert query(db,"SELECT COUNT(*) FROM dnr.follow_up_tasks t JOIN dnr.users u ON u.id=t.assigned_to WHERE t.title NOT LIKE '[Demo team task] %' AND u.username<>'TestEditor'")=='0','Baseline ownership failed'
 assert not foreign_key_errors(db),'Broken foreign keys: '+str(foreign_key_errors(db))
 assert query(db,"SELECT COUNT(*) FROM dnr.engagement_email_deliveries WHERE status IN ('queued','retry','processing') OR payload_ciphertext IS NOT NULL")=='0','Outbound replay risk'
 assert query(db,'SELECT COUNT(*) FROM dnr.reimbursement_email_deliveries')=='0','Unexpected reimbursement delivery'
 assert query(db,"SELECT COUNT(*) FROM dnr.inbound_email_messages WHERE status IN ('queued','processing')")=='0','Unexpected inbound queue'
 assert int(query(db,"SELECT COUNT(*) FROM dnr.engagements e LEFT JOIN dnr.engagement_financial_reports r ON r.engagement_id=e.id WHERE e.is_deleted=0 AND e.lifecycle_status IN ('active','completed') AND e.event_end_date<'"+anchor.isoformat()+"' AND r.engagement_id IS NULL"))>0,'No dashboard closeout example'
 return query(db,"SELECT u.username,COUNT(*) FROM dnr.follow_up_tasks t JOIN dnr.users u ON u.id=t.assigned_to WHERE t.status IN ('open','in_progress','waiting') AND t.is_archived=0 GROUP BY u.username ORDER BY u.username")
def refresh(args):
 anchor=datetime.date.fromisoformat(args.as_of) if args.as_of else datetime.datetime.now(ZoneInfo('America/Chicago')).date()
 require(not args.apply or not args.as_of,'Live refresh always uses the current Chicago date')
 window=[month_shift(anchor,-3).isoformat(),month_shift(anchor,9).isoformat()]
 directory=ROOT/'var/accounts'/KEY;metadata=json.loads((directory/'deployment.json').read_text())
 assert metadata['account_key']==KEY and metadata['project']==PROJECT and metadata['mode']=='production'
 assert query(DB,"SELECT CONCAT(account_key,'/',name) FROM dnr.account_profile WHERE id=1")=='test-account/Test Account'
 (BASE/'README.md').read_text()
 manifest=json.loads((BASE/'date-remap-manifest.json').read_text())
 require(manifest['source_anchor']=='2026-10-12' and manifest['account_key']==KEY,'Wrong approved baseline manifest')
 checks=json.loads((BASE/'SHA256SUMS.json').read_text());assert all(sha(BASE/f)==h for f,h in checks.items()),'Baseline changed'
 baseline=json.loads((BASE/'receipt.json').read_text());password=directory/'dnr_backup_password_file'
 web=inspect(WEB);image=web['Image'];database_image=inspect(DB)['Image']
 assert inspect(PRIMARY_WEB)['Image']==image,'Active production image parity failed'
 require(shutil.disk_usage(BUNDLE).free>2*1024**3,'Less than 2 GiB free for staging and verified backups')
 with (ROOT/'.git/dnr-deploy/deploy.lock').open('a') as lock:
  deployment_lock(lock)
  require(inspect(WEB)['Image']==image and inspect(DB)['Image']==database_image and json.loads((directory/'deployment.json').read_text())==metadata,'Deployment changed while waiting; retry with the current release')
  current_ledger=query(DB,MIGRATIONS_SQL);current_columns=query(DB,SCHEMA_COLUMNS_SQL)
  migration_plan(baseline['database_state']['migrations'],current_ledger)
  dc=saved_compose(directory,PROJECT,metadata); services=saved_active_services(directory)
  service_health(services);check_external_references()
  policy_before=live_php(POLICY_CHECK)
  primary_before=source_state(); protected_before=table_state(DB,PRESERVE)
  with (BASE/'member-encryption-keys.tar.gz.dnrenc').open('rb') as ciphertext:
   decrypt=subprocess.Popen(crypto_command(baseline['app_image'],password,'decrypt'),stdin=ciphertext,stdout=subprocess.PIPE)
   with tarfile.open(fileobj=decrypt.stdout,mode='r|gz') as archive:
    names=[]
    for entry in archive:
     assert entry.isfile() and entry.name in ['dnr_2fa_key_file','dnr_inbound_routing_key_file']
     assert hashlib.sha256(archive.extractfile(entry).read()).hexdigest()==sha(directory/entry.name),'Member key changed; re-encryption required'
     names.append(entry.name)
   while decrypt.stdout.read(1048576):pass
   assert decrypt.wait()==0 and len(names)==2;decrypt.stdout.close()
  print('Verified immutable baseline, member keys, production image parity and migration compatibility.',flush=True)
  with tempfile.TemporaryDirectory(prefix='nightly-baseline-stage-',dir=BUNDLE) as temporary:
   work=Path(temporary);files=work/'files';files.mkdir(mode=0o777);files.chmod(0o777)
   (work/'tables.json').write_text(json.dumps(TABLES)); (work/'tables.json').chmod(0o444)
   with restored_database(BASE/'database.sql.gz.dnrenc',database_image,image,password,work,'test-account-baseline-refresh') as staged:
    schema_upgrade=upgrade_staged_schema(staged,current_ledger,current_columns,baseline['database_state']['migrations'])
    print('Isolated baseline schema qualified: '+json.dumps(schema_upgrade),flush=True)
    before_counts={t:int(query(staged,'SELECT COUNT(*) FROM dnr.`'+t+'`')) for t in TABLES}
    relationships=date_relationships(staged)
    expected={k:(int(s),c) for k,s,c in (line.split('\t') for line in query(staged,'SELECT storage_key,size,checksum FROM dnr.stored_files').splitlines())}
    with (BASE/'uploaded-files.tar.gz.dnrenc').open('rb') as ciphertext:
     decrypt=subprocess.Popen(crypto_command(image,password,'decrypt'),stdin=ciphertext,stdout=subprocess.PIPE)
     with tarfile.open(fileobj=decrypt.stdout,mode='r|gz') as archive:
      for entry in archive:
       assert entry.isfile() and entry.name in expected
       with archive.extractfile(entry) as source,(files/entry.name).open('xb') as target:shutil.copyfileobj(source,target)
       assert ((files/entry.name).stat().st_size,sha(files/entry.name))==expected[entry.name]
     while decrypt.stdout.read(1048576):pass
     assert decrypt.wait()==0;decrypt.stdout.close()
    assert len(list(files.iterdir()))==len(expected)
    # Staging uses current users/profile/subscriptions; these tables are never imported into live.
    sql=b'SET FOREIGN_KEY_CHECKS=0;\n'+b''.join(('DELETE FROM `'+t+'`;\n').encode() for t in PRESERVE)+dump(DB,PRESERVE)+b'\nSET FOREIGN_KEY_CHECKS=1;'
    subprocess.run(root_command(staged,'mysql','-uroot','dnr'),input=sql,check=True,stdout=subprocess.DEVNULL)
    remap=stage_php(staged,work,image,(OPS/'test_account_baseline_remap.php').read_text(),['--tables=/run/tables.json','--source-anchor='+manifest['source_anchor'],'--as-of='+anchor.isoformat()])
    require(date_relationships(staged)==relationships,'Event durations, linked dates or reimbursement periods changed')
    # Rebuild every submitted package, even if the captured owner already matches.
    execute(staged,"UPDATE reimbursement_requests SET owner_name_snapshot='' WHERE status='submitted'")
    ownership=stage_php(staged,work,image,(OPS/'test_account_set_owner.php').read_text(),['--apply'])
    samples=stage_php(staged,work,image,(OPS/'test_account_task_samples.php').read_text(),['--apply','--as-of='+anchor.isoformat()])
    assert len(samples['added_task_ids'])==6 and ownership['users_and_settings_unchanged']
    assert protected_before==table_state(staged,PRESERVE),'Protected staging tables changed'
    for t,n in before_counts.items():
     extra=6 if t=='follow_up_tasks' else (1 if t in ['engagements','engagement_financial_drafts'] and samples['financial_closeout']['created'] else 0)
     assert int(query(staged,'SELECT COUNT(*) FROM dnr.`'+t+'`'))==n+extra,t+' changed row count'
    owners=validate(staged,anchor)
    require(stage_php(staged,work,image,POLICY_CHECK)==policy_before,'Staged demo policy changed')
    consistency_source='<?php require "/var/www/html/bootstrap.php"; require_once "/var/www/html/data_consistency_helpers.php"; echo json_encode(applicationDataConsistencyCounts($conn));'
    consistency=stage_php(staged,work,image,consistency_source);assert sum(consistency.values())==0,consistency
    filecheck=stage_php(staged,work,image,'<?php require "/var/www/html/bootstrap.php"; require_once "/var/www/html/persistent_file_helpers.php"; $count=0;foreach($conn->query("SELECT storage_key,size,checksum FROM stored_files")->fetch_all(MYSQLI_ASSOC) as $r){$p=persistentFilePath($r["storage_key"]);if(!is_file($p)||filesize($p)!==(int)$r["size"]||hash_file("sha256",$p)!==$r["checksum"])throw new RuntimeException("File mismatch");$count++;} echo json_encode(["verified_files"=>$count]);')
    print('Isolated restore/remap validated: '+json.dumps({'remap':remap,'owners':owners,'files':filecheck}),flush=True)
    qualification={'account':KEY,'anchor':anchor.isoformat(),'window':window,'qualified_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),
      'code_sha256':code_hash(),'application_image':image,'baseline_unchanged':True,'isolated_restore_verified':True,
      'live_business_records_changed':False,'schema_upgrade':schema_upgrade,'consistency':consistency,'files':filecheck,'policy':policy_before}
    if not args.apply:
     require(protected_before==table_state(DB,PRESERVE),'Live protected data changed during rehearsal')
     qualification['primary_business_unchanged']=source_state()==primary_before
     save_state('rehearsal.json',qualification)
     print(json.dumps(qualification,indent=2),flush=True)
     return
    sql=dump(staged,TABLES)
    # Merge baseline/generated file inventory while preserving pre-existing metadata.
    file_sql=dump(staged,['stored_files']).replace(b'REPLACE INTO `stored_files`',b'INSERT IGNORE INTO `stored_files`')
    paused=[s for s in services if s not in ['web','account-control']]
    changed=False;receipt_written=False
    try:
     subprocess.run(dc+['stop',*paused],check=True,stdout=subprocess.DEVNULL,env=clean_environment())
     before=create_online_backup(DB,WEB,password,ROOT/'var/backups/test-account-demo-before',metadata['commit'],'nightly-pre-baseline-refresh')
     assert protected_before==table_state(DB,PRESERVE),'Users/settings changed during staging'
     require(query(DB,MIGRATIONS_SQL)==current_ledger and query(DB,SCHEMA_COLUMNS_SQL)==current_columns,'Live schema changed during staging')
     print('Fresh encrypted backup restored and verified; replacing only Test Account business records.',flush=True)
     volume=upload_volume(WEB)
     copy=['docker','run','--rm','--network','none','--mount','type=bind,src='+str(files)+',dst=/staged,readonly',
      '--mount','type=volume,src='+volume+',dst=/live','--entrypoint','sh',image,'-c',
      'for f in /staged/*; do k=${f##*/}; case "$k" in *[!a-f0-9]*) exit 1;; esac; '
      'test ${#k} -eq 64 || exit 1; if test -e "/live/$k"; then cmp -s "$f" "/live/$k" || exit 1; '
      'else cp "$f" "/live/$k" && chown 33:33 "/live/$k" && chmod 0640 "/live/$k" || exit 1; fi; done']
     subprocess.run(copy,check=True,stdout=subprocess.DEVNULL)
     reset=('SET FOREIGN_KEY_CHECKS=0; START TRANSACTION;\n'+''.join('DELETE FROM `'+t+'`;\n' for t in CLEAR_TABLES+list(reversed(TABLES)))).encode()
     final=reset+file_sql+sql+b'\nCOMMIT; SET FOREIGN_KEY_CHECKS=1;'
     changed=True
     subprocess.run(root_command(DB,'mysql','-uroot','dnr'),input=final,check=True,stdout=subprocess.DEVNULL)
     assert protected_before==table_state(DB,PRESERVE),'Protected live tables changed'
     require(query(DB,MIGRATIONS_SQL)==current_ledger and query(DB,SCHEMA_COLUMNS_SQL)==current_columns,'Refresh changed the live schema or migration ledger')
     for table in ['marketing_inbox_inbound_parts','marketing_inbox_route_receipts']:
      require(query(DB,'SELECT COUNT(*) FROM dnr.`'+table+'`')=='0','Stale inbox receive/routing metadata remains: '+table)
     owners=validate(DB,anchor)
     live_check=subprocess.check_output(['docker','exec','-i',WEB,'php','/dev/stdin'],input=consistency_source.encode())
     assert sum(json.loads(live_check).values())==0
     require(live_php(POLICY_CHECK)==policy_before,'Live demo policy changed')
     after=create_online_backup(DB,WEB,password,ROOT/'var/backups/test-account-demo-pitstop',metadata['commit'],'nightly-refreshed-baseline')
     assert all(sha(BASE/f)==h for f,h in checks.items()),'Immutable baseline changed'
     receipt={'account':KEY,'anchor':anchor.isoformat(),'window':window,'approved_baseline':str(BASE),
      'before_backup':before['backup_path'],'pitstop_backup':after['backup_path'],'restore_verified':True,'remap':remap,
      'ownership':ownership,'team_task_samples':samples,'active_task_owners':owners,'primary_business_unchanged':source_state()==primary_before,
      'users_settings_calendar_subscriptions_unchanged':True,'application_commit':metadata['commit'],'application_image':image,
      'baseline_unchanged':True,'schema_upgrade':schema_upgrade,'cleared_operational_tables':CLEAR_TABLES,'consistency':json.loads(live_check),'files':filecheck}
     save_state('latest-receipt.json',receipt)
     receipt_written=True
     print('Refresh and final encrypted snapshot verified.',flush=True)
    except Exception:
     if not changed:print('No live business records changed; resuming saved services.',flush=True)
     else:print('Live replacement occurred; check the retained backup and paused services before recovery.',flush=True)
     raise
    finally:
     if not changed or receipt_written:
      subprocess.run(dc+['up','-d','--no-build','--no-deps','--no-recreate','--wait',*paused],check=True,stdout=subprocess.DEVNULL,env=clean_environment())
      service_health(services)
    print(json.dumps({k:receipt[k] for k in ['account','anchor','window','restore_verified','active_task_owners','primary_business_unchanged','users_settings_calendar_subscriptions_unchanged','baseline_unchanged']},indent=2),flush=True)
def main():
 os.umask(0o077)
 require(__debug__,'Run without Python optimization; safety assertions must remain enabled')
 def interrupted(signum,frame):raise InterruptedError('Refresh interrupted by signal '+str(signum))
 signal.signal(signal.SIGTERM,interrupted)
 parser=argparse.ArgumentParser(description=__doc__)
 parser.add_argument('--apply',action='store_true',help='Replace live Test Account business data after isolated validation and backup')
 parser.add_argument('--as-of',help='Rehearsal date only; defaults to current America/Chicago date')
 args=parser.parse_args()
 STATE.mkdir(mode=0o700,parents=True,exist_ok=True)
 with (STATE/'run.lock').open('a') as lock:
  fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
  try:
   refresh(args)
   if args.apply:save_state('status.json',{'ok':True,'completed_at':datetime.datetime.now(datetime.timezone.utc).isoformat()})
  except BaseException as error:
   if args.apply:save_state('status.json',{'ok':False,'failed_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'error':str(error)[:2000]})
   raise

if __name__=='__main__':main()
