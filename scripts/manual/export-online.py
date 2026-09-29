#!/usr/bin/env python3
"""Render the manual's chapter text offline using explicit example configuration.

No application bootstrap, database, authentication, or network access is used.
"""
import json, subprocess
from pathlib import Path
from lxml import html
ROOT=Path(__file__).resolve().parents[2]
source=(ROOT/'src/help.php').read_text()
start=source.index('<section class="manual-chapter"')
end=source.index('<section class="manual-empty"',start)
body=source[start:end]
# Render the administrator reference so administrative instructions are retained.
context='''<?php
$manual_brand='MOED'; $manual_role='admin'; $manual_role_label='Admin';
$manual_access_summary='Access depends on your role and record ownership.';
$manual_can_manage=true; $manual_is_admin=true; $manual_task_days=7;
$manual_marker_example='[DNR#123.<signed-token>]'; $manual_marker_template='[DNR#ID.<signed-token>]';
function applicationGeneralWorkLabel(){ return 'General MOED work'; }
?>'''
rendered=subprocess.run(['php'],input=context+body,text=True,capture_output=True,check=True).stdout
root=html.fragment_fromstring(rendered,create_parent='div')
chapters=[]
for node in root.xpath('.//section[@data-manual-section]'):
 chapters.append({'id':node.get('id'),'title':node.xpath('.//header/h2')[0].text_content().strip(),
                  'keywords':node.get('data-keywords',''),'html':''.join(html.tostring(child,encoding='unicode') for child in node)})
if len(chapters)!=14 or chapters[9]['id']!='reimbursements':raise RuntimeError('Manual chapter extraction is incomplete')
(ROOT/'docs/user-manual/online-chapters.json').write_text(json.dumps(chapters,indent=2,ensure_ascii=False)+'\n')
print(f'Exported {len(chapters)} manual chapters without accessing live records.')
