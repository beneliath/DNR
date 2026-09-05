#!/usr/bin/env python3
import datetime
import json
from pathlib import Path
import re
root = Path(__file__).resolve().parent.parent
policy = json.loads((root / 'config/runtime-support.json').read_text())
if datetime.date.today() > datetime.date.fromisoformat(policy['review_due']):
    raise SystemExit('Quarterly runtime support review is due; verify vendor support and update config/runtime-support.json')
mysql = re.findall(r'FROM (mysql:[^\s]+)', (root / 'docker/mysql.Dockerfile').read_text())
compose = (root / 'docker-compose.yaml').read_text()
if len(mysql) != 1 or not mysql[0].startswith('mysql:' + policy['mysql_series'] + '@sha256:') or compose.count('image: ${DNR_DATABASE_IMAGE:-dnr-database:local}') != 2:
    raise SystemExit('Database and migrator must share the supported, immutable MySQL build')
print('Runtime support policy is current')
