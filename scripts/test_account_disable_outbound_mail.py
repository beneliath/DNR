#!/usr/bin/env python3
"""Disable all Test Account SMTP delivery, preserving the qualified image and external AI."""
import argparse,copy,datetime,fcntl,json,os,sys,subprocess
from pathlib import Path
ROOT=Path('/home/dgilmore/moed')
sys.path.insert(0,str(ROOT/'scripts'))
from account_production import saved_compose,saved_active_services,qualify_runtime,private_file,clean_environment
from deployment_backup import query,sha
from online_recovery_backup import create_online_backup
KEY='test-account';PROJECT='moed-account-test-account'

def disable_mail(document):
 updated=copy.deepcopy(document)
 changed=[]
 app=document['services']['web']['image']
 for name,service in updated['services'].items():
  if service.get('image')!=app:continue
  env=service.setdefault('environment',{})
  # The shared non-delivering transport keeps demo composition usable. It never
  # opens an SMTP connection. Remove the worker's SMTP credential mount as well.
  env['DNR_MAIL_TRANSPORT']='log'
  for key in list(env):
   if key.startswith('DNR_SMTP_'):env[key]=''
  service['secrets']=[s for s in service.get('secrets',[]) if (s if isinstance(s,str) else s['source'])!='dnr_smtp_password']
  if service!=document['services'][name]:changed.append(name)
 return updated,changed

def main():
 os.umask(0o077)
 parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--apply',action='store_true');args=parser.parse_args()
 directory=ROOT/'var/accounts'/KEY;metadata=json.loads((directory/'deployment.json').read_text());old=json.loads((directory/'runtime.compose.json').read_text())
 assert metadata['account_key']==KEY and metadata['project']==PROJECT and metadata['mode']=='production'
 assert query(PROJECT+'-db-1',"SELECT CONCAT(account_key,'/',name) FROM dnr.account_profile WHERE id=1")=='test-account/Test Account'
 command=saved_compose(directory,PROJECT,metadata);active=saved_active_services(directory)
 updated,changed=disable_mail(old)
 assert updated['networks']==old['networks']
 assert all(s.get('networks')==old['services'][n].get('networks') and s.get('image')==old['services'][n].get('image') for n,s in updated['services'].items())
 assert all({k:v for k,v in s.get('environment',{}).items() if k.startswith('DNR_AI_COACH_')}=={k:v for k,v in old['services'][n].get('environment',{}).items() if k.startswith('DNR_AI_COACH_')} for n,s in updated['services'].items())
 qualify_runtime(directory,updated,metadata)
 print(json.dumps({'account':KEY,'changed_services':changed,'outbound_transport':'log (no network delivery)','smtp_credentials_unmounted':True,'ai_configuration_unchanged':True,'images_and_networks_unchanged':True}),flush=True)
 if not args.apply or not changed:return
 with (ROOT/'.git/dnr-deploy/deploy.lock').open('a') as lock:
  fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
  assert sha(directory/'runtime.compose.json')==metadata['runtime_sha256']
  paused=[s for s in active if s not in ['web','account-control']]
  subprocess.run(command+['stop',*paused],check=True,env=clean_environment(),stdout=subprocess.DEVNULL)
  saved=False
  try:
   backup=create_online_backup(PROJECT+'-db-1',PROJECT+'-web-1',directory/'dnr_backup_password_file',ROOT/'var/backups/test-account-demo-before',metadata['commit'],'disable-test-account-outbound-mail')
   assert backup['restore_verified']
   dest=Path(backup['backup_path']).parent
   private_file(dest/'runtime.compose.before.json',json.dumps(old,indent=2))
   private_file(dest/'deployment.before.json',json.dumps(metadata,indent=2))
   private_file(directory/'runtime.compose.json',json.dumps(updated,indent=2))
   metadata['runtime_sha256']=sha(directory/'runtime.compose.json')
   private_file(directory/'deployment.json',json.dumps(metadata,indent=2));saved=True
   command=saved_compose(directory,PROJECT,metadata)
   subprocess.run(command+['up','-d','--no-build','--no-deps','--wait',*[s for s in changed if s in active]],check=True,env=clean_environment(),stdout=subprocess.DEVNULL)
   subprocess.run(command+['up','-d','--no-build','--no-deps','--no-recreate','--wait',*paused],check=True,env=clean_environment(),stdout=subprocess.DEVNULL)
   for service in active:
    info=json.loads(subprocess.check_output(['docker','inspect',PROJECT+'-'+service+'-1']))[0]
    assert info['State']['Running'] and info['State'].get('Health',{}).get('Status','healthy')=='healthy'
    if service in changed:
     env=dict(v.split('=',1) for v in info['Config']['Env']);assert env['DNR_MAIL_TRANSPORT']=='log'
     assert not any(m['Destination']=='/run/secrets/dnr_smtp_password' for m in info['Mounts'])
   receipt={'account':KEY,'completed_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'backup':backup['backup_path'],'restore_verified':True,'transport':'log','smtp_credentials_unmounted':True,'changed_services':changed,'application_commit':metadata['commit'],'ai_configuration_unchanged':True,'images_and_networks_unchanged':True}
   private_file(directory/'outbound-mail-disabled.json',json.dumps(receipt,indent=2))
   print(json.dumps(receipt,indent=2),flush=True)
  except BaseException:
   if not saved:subprocess.run(command+['up','-d','--no-build','--no-deps','--no-recreate','--wait',*paused],env=clean_environment(),stdout=subprocess.DEVNULL)
   # Do not automatically restore SMTP after the operator has disabled it.
   raise
if __name__=='__main__':main()
