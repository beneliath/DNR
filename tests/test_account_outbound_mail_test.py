"""Disabling demo mail must preserve the qualified topology, AI and other services."""
import copy
from pathlib import Path
import sys
import unittest
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'scripts'))
from test_account_disable_outbound_mail import disable_mail

class TestAccountOutboundMailTest(unittest.TestCase):
    def test_removes_smtp_capability_without_changing_ai_networks_or_image(self):
        document={'networks':{'backend':{'ipam':{'config':[{'subnet':'10.2.3.0/24'}]}}},'services':{
            'web':{'image':'qualified-app','environment':{'DNR_MAIL_TRANSPORT':'smtp','DNR_AI_COACH_ENABLED':'1','DNR_AI_COACH_URL':'http://ai:11437'},'networks':['backend']},
            'mail-dispatch':{'image':'qualified-app','environment':{'DNR_MAIL_TRANSPORT':'smtp','DNR_SMTP_HOST':'smtp.example.invalid','DNR_SMTP_PASSWORD_FILE':'/run/secrets/dnr_smtp_password'},'secrets':[{'source':'dnr_smtp_password'},{'source':'database_password'}]},
            'db':{'image':'qualified-db'},'ai-coach-tunnel':{'image':'qualified-tunnel'}}}
        original=copy.deepcopy(document)
        updated,changed=disable_mail(document)
        self.assertEqual(document,original)
        self.assertEqual(changed,['web','mail-dispatch'])
        self.assertEqual(updated['services']['mail-dispatch']['secrets'],[{'source':'database_password'}])
        self.assertEqual(updated['services']['mail-dispatch']['environment']['DNR_SMTP_HOST'],'')
        self.assertEqual(updated['services']['web']['environment']['DNR_AI_COACH_URL'],'http://ai:11437')
        self.assertEqual(updated['networks'],document['networks'])
        for name in document['services']:self.assertEqual(updated['services'][name]['image'],document['services'][name]['image'])
        self.assertEqual(updated['services']['db'],document['services']['db'])
        self.assertEqual(disable_mail(updated),(updated,[]))

if __name__=='__main__':unittest.main()
