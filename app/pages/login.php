<?php
/** Sign in. The only page that answers without a session. */

if (user()) {
    redirect('/');
}

require_once __DIR__.'/../security.php';
$f=flash();
$error = $f['msg'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $pass  = (string) ($_POST['password'] ?? '');

    $emailAllowed=security_rate_limit('login-email',mb_strtolower($email),10,900);
    $ipAllowed=security_rate_limit('login-ip',(string)($_SERVER['REMOTE_ADDR']??''),100,900);
    $u=$emailAllowed&&$ipAllowed?row('SELECT * FROM users WHERE email=? AND is_active=1',[$email]):null;
    $hash=$u['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
    $valid=password_verify($pass,$hash);
    if($u&&$valid){
        $to=$_SESSION['intended']??'/';unset($_SESSION['intended']);
        security_begin_login($u,is_string($to)?$to:'/');
    }

    $error = t('That email and password do not match an account.');
}

$token = csrf_token();
?><!doctype html>
<html lang="<?= e(locale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= te('Sign in') ?> · <?= e($config['app_name']) ?></title>
<style>
:root{
  --navy:#0D2137; --navy-2:#17456F; --blue:#346EB6; --violet:#6F42C1;
  --blue-soft:#8CBBE5; --line:#D4DCE6; --muted:#657387; --ink:#16202B;
}
*{box-sizing:border-box}

body{
  margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
  background:
    radial-gradient(1100px 620px at 12% -8%, rgba(111,66,193,.38), transparent 62%),
    linear-gradient(152deg,#0D2137 0%,#17456F 52%,#346EB6 100%);
  font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
  color:var(--ink);padding:24px;
}

.box{
  background:#fff;border-radius:18px;padding:40px 38px 32px;width:100%;max-width:412px;
  box-shadow:0 24px 70px rgba(5,20,40,.38), 0 2px 8px rgba(5,20,40,.16);
}

.mark{
  width:46px;height:46px;border-radius:13px;margin:0 0 18px;
  background:linear-gradient(135deg,var(--blue),var(--violet));
  color:#fff;font-weight:800;font-size:22px;line-height:46px;text-align:center;
  letter-spacing:-.5px;
}

.brand{font-size:27px;font-weight:700;color:var(--navy);margin:0 0 3px;letter-spacing:-.5px}
.tag{color:var(--muted);font-size:13.5px;margin:0 0 26px;line-height:1.5}

label{display:block;font-size:12.5px;color:#2B3A4D;font-weight:600;margin:0 0 6px}

input{
  width:100%;padding:12px 14px;min-height:46px;font:inherit;font-size:15px;
  border:1px solid var(--line);border-radius:10px;margin-bottom:18px;
  transition:border-color .12s ease, box-shadow .12s ease;
}
input::placeholder{color:#9AA8B8}
input:hover{border-color:#B9C6D6}
input:focus{
  outline:none;border-color:var(--blue);
  box-shadow:0 0 0 3px rgba(52,110,182,.18);
}

button{
  width:100%;min-height:48px;padding:13px;border:0;border-radius:10px;
  background:linear-gradient(135deg,var(--blue),#4A5FC1);
  color:#fff;font:inherit;font-size:15px;font-weight:650;cursor:pointer;
  box-shadow:0 2px 10px rgba(52,110,182,.32);
  transition:filter .12s ease, transform .06s ease;
}
button:hover{filter:brightness(1.07)}
button:active{transform:translateY(1px)}
button:focus-visible{outline:none;box-shadow:0 0 0 3px rgba(52,110,182,.4)}

.err{
  background:#FDEEED;border:1px solid #F0C4C1;color:#8E1D17;padding:11px 14px;
  border-radius:10px;font-size:13.5px;margin-bottom:18px;line-height:1.45;
}

.alt{margin-top:18px;text-align:center;font-size:13.5px}
.alt a{color:var(--blue);text-decoration:none;font-weight:600}
.alt a:hover{text-decoration:underline}

.foot{
  margin-top:24px;padding-top:18px;border-top:1px solid #EDF1F6;
  font-size:12px;color:var(--muted);text-align:center;line-height:1.6;
}
.foot strong{color:#4A5A6E;font-weight:600}

@media(max-width:480px){
  .box{padding:30px 24px 24px;border-radius:14px}
  input,button{font-size:16px}  /* stops iOS zooming on focus */
}
</style>
</head>
<body>
<form class="box" method="post" action="/login">
  <input type="hidden" name="_csrf" value="<?= e($token) ?>">
  <div class="mark">C</div>
  <h1 class="brand"><?= e($config['app_name']) ?></h1>
  <p class="tag"><?= te('Placement, lodging, travel and payroll — in one place.') ?></p>

  <?php if ($error): ?><div class="err"><?= e($error) ?></div><?php endif; ?>

  <label for="email"><?= te('Email') ?></label>
  <input id="email" name="email" type="email" required autofocus autocomplete="username"
         value="<?= e($_POST['email'] ?? '') ?>">

  <label for="password"><?= te('Password') ?></label>
  <input id="password" name="password" type="password" required autocomplete="current-password">

  <button type="submit"><?= te('Sign in') ?></button><p class="foot"><a href="/google-start"><?= te('Sign in / connect with Google') ?></a></p>
  <p class="foot"><a href="/recover"><?= te('Recover account') ?></a></p>
</form>
</body>
</html>
