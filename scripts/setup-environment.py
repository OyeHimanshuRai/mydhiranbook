from pathlib import Path
import json, secrets, base64, subprocess, os
r=Path.cwd().resolve()
assert r==Path('/home/himanshu/Desktop/Dhiran ERP')
manifest=r/'.runtime/targets.json'
if manifest.exists():
 print('Existing Dhiran local targets preserved; no credentials replaced.')
 raise SystemExit(0)
assert not (r/'.env').exists() and not (r/'.env.testing').exists(), 'Existing environment preserved; configure manually.'
pg='/usr/lib/postgresql/16/bin/psql'
def sql(statement, db='postgres'):
 return subprocess.check_output([pg,'-X','-v','ON_ERROR_STOP=1','-h',str(r/'.runtime/postgres/socket'),'-p','55439','-d',db,'-Atc',statement],text=True).strip()
identity=sql('SELECT system_identifier FROM pg_control_system()')
targets={'root':str(r),'system_identifier':identity,'port':'55439','host':str(r/'.runtime/postgres/socket'),'local':{'database':'dhiran_local','username':'dhiran_app','password':secrets.token_hex(24)},'testing':{'database':'dhiran_test','username':'dhiran_test','password':secrets.token_hex(24)}}
sql('REVOKE CONNECT ON DATABASE postgres, template1, template0 FROM PUBLIC')
for env in ['local','testing']:
 t=targets[env]
 sql(f"CREATE ROLE {t['username']} LOGIN PASSWORD '{t['password']}' NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS")
 sql(f"CREATE DATABASE {t['database']} OWNER {t['username']}")
 sql(f"REVOKE ALL ON DATABASE {t['database']} FROM PUBLIC")
 sql(f"GRANT CONNECT, TEMPORARY ON DATABASE {t['database']} TO {t['username']}")
 sql('REVOKE CREATE ON SCHEMA public FROM PUBLIC', t['database'])
 sql(f"GRANT EXECUTE ON FUNCTION pg_control_system() TO {t['username']}",t['database'])
 storage=r/'.runtime/testing/storage' if env=='testing' else r/'storage'
 for path in ['app/private','framework/cache/data','framework/sessions','framework/views','logs']:
  (storage/path).mkdir(parents=True,exist_ok=True)
 key=base64.b64encode(secrets.token_bytes(32)).decode()
 content=f'''APP_NAME=Dhiran
APP_ENV={env}
APP_KEY=base64:{key}
APP_DEBUG=false
APP_URL=http://127.0.0.1:8787
DB_CONNECTION=pgsql
DB_HOST="{targets['host']}"
DB_PORT=55439
DB_DATABASE={t['database']}
DB_USERNAME={t['username']}
DB_PASSWORD={t['password']}
SESSION_COOKIE=dhiran_{env}_session
QUEUE_NAME=dhiran-{env}
MAIL_MAILER=log
'''
 path=r/('.env.testing' if env=='testing' else '.env'); path.write_text(content); path.chmod(0o600)
manifest.write_text(json.dumps(targets,indent=2)+'\n'); manifest.chmod(0o600)
print('Fresh Dhiran local and test databases, restricted roles, environments and distinct application keys created.')
