<?php
/**
 * P5-M110b: a require hidden behind a comment on the same line.
 * P5-M107 left "// ... sends nothingrequire __DIR__.'/../app/gmail.php';" in two delivery jobs: the require became
 * part of the comment, token_decrypt() was undefined, and every portal invitation and password reset failed in
 * production for two days. PHP's own lint cannot see it: the line is valid.
 */

declare(strict_types=1);

// In the test run the application is copied next to this file; from the repository, it is the parent folder.
$root = is_dir(__DIR__ . '/test-app') ? __DIR__ . '/test-app' : dirname(__DIR__);
$bad = [];
$files = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app', FilesystemIterator::SKIP_DOTS));
foreach (array_merge(iterator_to_array($it, false), array_map(fn($f) => new SplFileInfo($f), glob($root . '/install/*.php'))) as $f) {
    if ($f->getExtension() !== 'php') {
        continue;
    }
    $files++;
    foreach (token_get_all(file_get_contents($f->getPathname())) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) && str_starts_with($t[1], '//')
            && preg_match('/(require|include)(_once)?\s*\(?\s*__DIR__/', $t[1])) {
            $bad[] = substr($f->getPathname(), strlen($root) + 1) . ':' . $t[2] . ' ' . trim($t[1]);
        }
    }
}
if ($bad) {
    fwrite(STDERR, "FAIL A require is inside a comment:\n  " . implode("\n  ", $bad) . "\n");
    exit(1);
}
echo "PASS No require or include hidden in a comment ($files files)\n";

// The delivery jobs load what they call.
foreach (['install/deliver-account-mail.php', 'install/deliver-emails.php'] as $job) {
    $code = file_get_contents($root . '/' . $job);
    if (str_contains($code, 'token_decrypt(') && ! preg_match('/^\s*require(_once)?\s+__DIR__\s*\.\s*\'\/\.\.\/app\/gmail\.php\';/m', $code)) {
        fwrite(STDERR, "FAIL $job calls token_decrypt() without loading app/gmail.php\n");
        exit(1);
    }
}
echo "PASS The delivery jobs load app/gmail.php for token_decrypt()\n";
