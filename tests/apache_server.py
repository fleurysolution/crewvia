"""A private Apache with mod_php for the tests (P4-M07), on 127.0.0.1 only.

PHP's built-in server answers one request at a time on Windows, so it cannot
show what happens when people work at the same moment. Apache (XAMPP's) runs
the application as production does: threads, mod_php, the .htaccess files.
It uses its own configuration, pid and logs in the work folder and never
touches the machine's own Apache configuration.
"""
import os, pathlib, subprocess, time, urllib.request

APACHE = pathlib.Path(os.environ.get('APACHE_DIR', 'C:/xampp/apache'))
PHP_DIR = pathlib.Path(os.environ.get('PHP_DIR', 'C:/xampp/php'))


def conf(public, work, port, threads):
    # Apache reads relative paths against its own ServerRoot: every path is made absolute.
    work, public = pathlib.Path(work).resolve(), pathlib.Path(public).resolve()
    p = lambda x: str(x).replace('\\', '/')
    sessions = work / 'apache-sessions'
    sessions.mkdir(parents=True, exist_ok=True)
    uploads = work / 'uploads'
    uploads.mkdir(parents=True, exist_ok=True)
    return '\n'.join([
        'ServerRoot "%s"' % p(APACHE),
        'Listen 127.0.0.1:%d' % port,
        'ServerName 127.0.0.1:%d' % port,
        'PidFile "%s"' % p(work / 'apache.pid'),
        'ErrorLog "%s"' % p(work / 'apache-error.log'),
        'LogLevel warn',
        *['LoadModule %s_module modules/mod_%s.so' % (m, m) for m in ['authz_core', 'authz_host', 'dir', 'env', 'headers', 'log_config', 'mime', 'rewrite', 'setenvif']],
        'LoadFile "%s"' % p(PHP_DIR / 'php8ts.dll'),
        'LoadModule php_module "%s"' % p(PHP_DIR / 'php8apache2_4.dll'),
        'PHPIniDir "%s"' % p(PHP_DIR),
        '<FilesMatch "\\.php$">', '  SetHandler application/x-httpd-php', '</FilesMatch>',
        'TypesConfig conf/mime.types',
        'ThreadsPerChild %d' % threads,
        'MaxConnectionsPerChild 0',
        'KeepAlive On',
        'LogFormat "%h %t \\"%r\\" %>s %D" timing',
        'CustomLog "%s" timing' % p(work / 'apache-access.log'),
        'DocumentRoot "%s"' % p(public),
        # As on cPanel, .htaccess is honoured from the application folder down: its own
        # "Require all denied" and public/'s "Require all granted" are both exercised.
        '<Directory "%s">' % p(public.parent), '  AllowOverride All', '</Directory>',
        'DirectoryIndex index.php',
        'php_value html_errors 0',
        'php_value session.save_path "%s"' % p(sessions),
        'php_value upload_tmp_dir "%s"' % p(uploads),
        '',
    ])


def start(public, work, port=8096, threads=64):
    work = pathlib.Path(work).resolve()
    path = work / 'apache-test.conf'
    path.write_text(conf(public, work, port, threads))
    log = open(work / 'apache-console.log', 'w')
    proc = subprocess.Popen([str(APACHE / 'bin' / 'httpd.exe'), '-f', str(path)], stdout=log, stderr=log)
    for _ in range(60):
        try:
            with urllib.request.urlopen('http://127.0.0.1:%d/health' % port, timeout=2) as r:
                if r.status in (200, 503):
                    return proc
        except urllib.error.HTTPError as e:
            if e.code in (200, 503):
                return proc
        except Exception:
            pass
        if proc.poll() is not None:
            break
        time.sleep(0.5)
    stop(proc)
    raise RuntimeError('Apache did not start: ' + (work / 'apache-console.log').read_text()[-800:] + ((work / 'apache-error.log').read_text()[-800:] if (work / 'apache-error.log').exists() else ''))


def stop(proc):
    if proc:
        # httpd on Windows runs a parent and a child; ending the tree stops both.
        subprocess.run(['taskkill', '/PID', str(proc.pid), '/T', '/F'], capture_output=True)
        proc.wait(timeout=30)
