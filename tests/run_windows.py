"""Isolated Windows regression runner. Never uses the customer's config/database."""
import base64, os, pathlib, shutil, socket, subprocess, sys, tempfile, time
source = pathlib.Path(__file__).resolve().parents[1]
php = os.environ.get('PHP_BIN', 'C:/xampp/php/php.exe')
mysql_dir = pathlib.Path(os.environ.get('MYSQL_BIN_DIR', 'C:/xampp/mysql/bin'))
node = os.environ.get('NODE_BIN', 'node')
# Playwright is not on every build machine. Skipping it is explicit and printed,
# so a run without screenshots can never be mistaken for a full one.
skip_browser = os.environ.get('SKIP_BROWSER') == '1'
for port in [3308,8097]:
    with socket.socket() as probe:
        if probe.connect_ex(('127.0.0.1',port)) == 0:
            raise RuntimeError(f'Port {port} is occupied. Refusing to start tests.')
scratch=pathlib.Path(os.environ.get('TEST_WORK_DIR', str(pathlib.Path.cwd()/'work')));scratch.mkdir(parents=True,exist_ok=True)
with tempfile.TemporaryDirectory(prefix='rss-', dir=scratch) as temporary:
    base=pathlib.Path(temporary);work=base/'work';work.mkdir();(base/'outputs').mkdir()
    app=work/'test-app';shutil.copytree(source,app,ignore=shutil.ignore_patterns('config.php','storage','tenants','.git','tests'))
    for folder in ['sessions','uploads']: (work/folder).mkdir()
    for name in ['fixture.php','http_tests.py','browser_tests.cjs','saas_unit.php','recruiting_unit.php','i18n_unit.php','security_unit.php','payroll_unit.php','connector_unit.php','docusign_unit.php','scale_fixture.php','scale_http.py','tenant_fixture.php','tenant_http.py','hr_relationships_db.php','hr_relationships_http.py','hr_relationships_probe.php','attendance_controls_db.php','attendance_controls_http.py','attendance_controls_probe.php','workforce_overview_http.py','workforce_overview_probe.php','pay_rules_unit.php','pay_rules_db.php','pay_rules_http.py','pay_rules_probe.php','leave_unit.php','leave_db.php','leave_http.py','leave_probe.php','gross_to_net_unit.php','gross_to_net_db.php','gross_to_net_http.py','gross_to_net_probe.php','pay_periods_db.php','pay_periods_http.py','pay_periods_probe.php','procurement_db.php','procurement_http.py','procurement_probe.php','access_matrix_http.py','backup_restore_drill.py','self_service_db.php','self_service_http.py','self_service_probe.php']:shutil.copy2(source/'tests'/name,work/name)
    (app/'config.php').write_text("<?php return ['db_host'=>'127.0.0.1;port=3308','db_name'=>'rss_ops_test','db_user'=>'root','db_pass'=>'','app_url'=>'http://127.0.0.1:8097','debug'=>true,'encryption_key'=>'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=','recruiting_webhooks'=>['test_partner'=>['enabled'=>true,'secret'=>'unit-test-secret-unit-test-secret']]];")
    init=mysql_dir/'mysql_install_db.exe'
    subprocess.run([str(init),'--datadir='+str(work/'mysql-data'),'--port=3308'],check=True)
    ini=work/'mysql-data/my.ini';ini.write_text('[mysqld]\ndatadir='+str(work/'mysql-data').replace('\\','/')+'\nport=3308\n')
    with (work/'runtime-services.log').open('w') as log:
        mysql=subprocess.Popen([str(mysql_dir/'mysqld.exe'),'--defaults-file='+str(ini),'--bind-address=127.0.0.1','--console'],stdout=log,stderr=log)
        try:
            time.sleep(2)
            if mysql.poll() is not None:raise RuntimeError('Isolated database failed to start')
            cli=[str(mysql_dir/'mysql.exe'),'--host=127.0.0.1','--port=3308','--user=root']
            subprocess.run(cli+['-e','CREATE DATABASE rss_ops_test CHARACTER SET utf8mb4'],check=True,capture_output=True)
            command=[php,'-d','session.save_path='+str(work/'sessions')]
            subprocess.run(command+[str(work/'saas_unit.php')],check=True)
            subprocess.run(command+[str(work/'recruiting_unit.php')],check=True)
            for locale in ['en','fr','es']:subprocess.run(command+[str(work/'i18n_unit.php'),locale],check=True,capture_output=True)
            subprocess.run(command+[str(work/'security_unit.php')],check=True)
            subprocess.run(command+[str(work/'payroll_unit.php')],check=True)
            subprocess.run(command+[str(work/'pay_rules_unit.php')],check=True)
            subprocess.run(command+[str(work/'leave_unit.php')],check=True)
            subprocess.run(command+[str(work/'gross_to_net_unit.php')],check=True)
            subprocess.run(command+[str(work/'connector_unit.php')],check=True)
            subprocess.run(command+[str(work/'docusign_unit.php')],check=True)
            # Blank installer proves no legacy records are needed for a new customer.
            env=dict(os.environ,RSS_ADMIN_EMAIL='owner@test.invalid')
            subprocess.run(command+[str(app/'install/install.php'),'--blank'],env=env,check=True,capture_output=True)
            empty=subprocess.run(cli+['rss_ops_test','-N','-e','SELECT COUNT(*) FROM candidates'],check=True,capture_output=True,text=True)
            assert empty.stdout.strip()=='0','Blank installation imported candidates'
            print('PASS Blank installation without legacy candidates',flush=True)
            for _ in range(2):subprocess.run(command+[str(app/'install/upgrade.php')],check=True,capture_output=True)
            print('PASS Upgrade can be repeated',flush=True)
            fixture=subprocess.run(command+[str(work/'fixture.php')],check=True,capture_output=True,text=True)
            (work/'fixture.json').write_text(fixture.stdout)
            # P1-M01: rollback, upgrade and backfill on the test database, before any HTTP.
            subprocess.run(command+[str(work/'hr_relationships_db.php')],check=True)
            # P1-M02: rollback and upgrade, then the synthetic week the screens are tested on.
            subprocess.run(command+[str(work/'attendance_controls_db.php')],check=True)
            http=subprocess.Popen(command+['-d','extension=zip','-d','upload_tmp_dir='+str(work/'uploads'),'-S','127.0.0.1:8097','-t',str(app/'public'),str(app/'public/index.php')],stdout=log,stderr=log)
            try:
                time.sleep(1)
                subprocess.run([sys.executable,str(work/'http_tests.py')],cwd=base,check=True)
                subprocess.run([sys.executable,str(work/'hr_relationships_http.py')],cwd=base,env=dict(os.environ,PHP_BIN=php),check=True)
                subprocess.run([sys.executable,str(work/'attendance_controls_http.py')],cwd=base,env=dict(os.environ,PHP_BIN=php),check=True)
                subprocess.run([sys.executable,str(work/'workforce_overview_http.py')],cwd=base,env=dict(os.environ,PHP_BIN=php),check=True)
                # P1-M03 after the older suites, so its ADP settings cannot change what they expected.
                subprocess.run(command+[str(work/'pay_rules_db.php')],check=True)
                subprocess.run([sys.executable,str(work/'pay_rules_http.py')],cwd=base,env=dict(os.environ,PHP_BIN=php),check=True)
                subprocess.run(command+[str(work/'leave_db.php')],check=True)
                subprocess.run([sys.executable,str(work/'leave_http.py')],cwd=base,env=dict(os.environ,PHP_BIN=php),check=True)
                subprocess.run(command+[str(work/'gross_to_net_db.php')],check=True)
                subprocess.run([sys.executable,str(work/'gross_to_net_http.py')],cwd=base,env=dict(os.environ,PHP_BIN=php),check=True)
                # P1-M06 last: it locks weeks.
                subprocess.run(command+[str(work/'pay_periods_db.php')],check=True)
                subprocess.run([sys.executable,str(work/'pay_periods_http.py')],cwd=base,env=dict(os.environ,PHP_BIN=php),check=True)
                subprocess.run(command+[str(work/'procurement_db.php')],check=True)
                subprocess.run([sys.executable,str(work/'procurement_http.py')],cwd=base,env=dict(os.environ,PHP_BIN=php),check=True)
                subprocess.run(command+[str(work/'self_service_db.php')],check=True)
                subprocess.run([sys.executable,str(work/'self_service_http.py')],cwd=base,env=dict(os.environ,PHP_BIN=php),check=True)
                # P1-M07: every route by every role, the CSRF sweep, then a backup and restore drill.
                subprocess.run([sys.executable,str(work/'access_matrix_http.py')],cwd=base,check=True)
                subprocess.run([sys.executable,str(work/'backup_restore_drill.py')],cwd=base,env=dict(os.environ,MYSQL_BIN_DIR=str(mysql_dir)),check=True)
                subprocess.run(command+[str(app/'install/verify-relationships.php')],check=True)
                print('PASS Relationships consistent after the HTTP suites',flush=True)
                if skip_browser:print('SKIPPED Browser screenshots (SKIP_BROWSER=1). This is not a full run.',flush=True)
                else:subprocess.run([node,str(work/'browser_tests.cjs')],cwd=base,check=True)
                for _ in range(2):subprocess.run(command+[str(app/'install/run-workflows.php')],check=True,capture_output=True)
                subprocess.run(command+[str(app/'install/reconcile-contracts.php')],check=True,capture_output=True)
                scale=subprocess.run(command+[str(work/'scale_fixture.php')],env=dict(os.environ,WORKFORCE_SYNTHETIC_TEST='1'),check=True,capture_output=True,text=True)
                (work/'scale-fixture.json').write_text(scale.stdout)
                subprocess.run([sys.executable,str(work/'scale_http.py')],cwd=base,check=True)
                # Real database/HTTP isolation on synthetic registered hosts.
                tenants=app/'tenants';tenants.mkdir(exist_ok=True)
                default=(app/'config.php').read_text().rstrip().removesuffix(';')
                default=default.replace("<?php return ","<?php return ['tenant_id'=>'default-test',")
                default=default.replace("['tenant_id'=>'default-test',[","['tenant_id'=>'default-test',").replace("'app_url'=>'http://127.0.0.1:8097'","'app_url'=>'https://127.0.0.1'")
                (tenants/'default.php').write_text(default+';')
                for label in ['alpha','bravo']:
                    subprocess.run(cli+['-e','CREATE DATABASE workforce_'+label+'_test CHARACTER SET utf8mb4'],check=True,capture_output=True)
                    (tenants/(label+'.php')).write_text("<?php return ['tenant_id'=>'"+label+"','db_host'=>'127.0.0.1;port=3308','db_name'=>'workforce_"+label+"_test','db_user'=>'root','db_pass'=>'','app_url'=>'https://"+label+".test','encryption_key'=>'"+base64.b64encode(bytes([1 if label=='alpha' else 2])*32).decode()+"'];")
                (app/'config.php').write_text("<?php return ['debug'=>true,'tenant_hosts'=>['127.0.0.1'=>'default.php','alpha.test'=>'alpha.php','bravo.test'=>'bravo.php']];")
                for label in ['alpha','bravo']:
                    tenant_env=dict(os.environ,WORKFORCE_TENANT_HOST=label+'.test',WORKFORCE_ADMIN_EMAIL='admin@'+label+'.test',WORKFORCE_SYNTHETIC_TEST='1')
                    subprocess.run(command+[str(app/'install/install.php'),'--blank'],env=tenant_env,check=True,capture_output=True)
                    subprocess.run(command+[str(work/'tenant_fixture.php')],env=tenant_env,check=True,capture_output=True)
                subprocess.run([sys.executable,str(work/'tenant_http.py')],cwd=base,check=True)

            finally:
                http.terminate();http.wait(timeout=10)
        finally:
            mysql.terminate();mysql.wait(timeout=10)
    output=pathlib.Path(os.environ.get('TEST_OUTPUT_DIR',str(source/'test-results'))).resolve();output.mkdir(parents=True,exist_ok=True)
    for name in [*([] if skip_browser else ['browser-results.json']),'m01-db-results.json','m01-http-results.json','m02-db-results.json','m02-http-results.json','overview-http-results.json','pay-rules-unit-results.json','m03-db-results.json','m03-http-results.json','leave-unit-results.json','m04-db-results.json','m04-http-results.json','gross-to-net-unit-results.json','m05-db-results.json','m05-http-results.json','m06-db-results.json','m06-http-results.json','proc-db-results.json','proc-http-results.json','m07-access-results.json','m07-backup-results.json','ss-db-results.json','ss-http-results.json','test-results.json','runtime-services.log','saas-unit-results.json','recruiting-unit-results.json','security-unit-results.json','payroll-unit-results.json','connector-unit-results.json','docusign-unit-results.json','scale-results.json','tenant-runtime-results.json']:shutil.copy2(work/name,output/name)
    for screenshot in (base/'outputs').glob('*.png'):shutil.copy2(screenshot,output/screenshot.name)
    for locale in ['en','fr','es']:shutil.copy2(work/('i18n-unit-'+locale+'.json'),output/('i18n-unit-'+locale+'.json'))
    print('Test evidence:',output)


