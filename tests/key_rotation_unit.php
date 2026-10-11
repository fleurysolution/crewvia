<?php
/**
 * GAP-1002: the configuration edit at the heart of a key rotation, on
 * temporary copies only. The key is replaced in place, the previous key
 * added and removed, every other value and line kept, in each way a
 * config.php is written; and a file it cannot edit safely is left as it was.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';
require_once __DIR__ . '/test-app/app/key-rotation.php';

$results = [];
function check(string $label, bool $condition, string $detail = ''): void
{
    global $results;
    if (! $condition) { fwrite(STDERR, 'FAIL ' . $label . ($detail !== '' ? ' - ' . $detail : '') . PHP_EOL); exit(1); }
    $results[] = $label;
    echo 'PASS ' . $label . PHP_EOL;
}

$dir = sys_get_temp_dir() . '/crewvia-kr-' . bin2hex(random_bytes(4));
mkdir($dir);
$old = base64_encode(str_repeat('o', 32));
$new = base64_encode(str_repeat('n', 32));
$layouts = [
    'single line' => "<?php return ['db_host'=>'127.0.0.1','db_name'=>'x','encryption_key'=>'$old','debug'=>false];",
    'one entry per line' => "<?php\n/** Copy to config.php. */\nreturn [\n    'db_host'  => '127.0.0.1',\n    'db_name'  => 'x',\n    // keep outside public/\n    'encryption_key' => '$old',\n    'debug'    => false,\n];\n",
    'Windows line endings, double quotes' => "<?php\r\nreturn [\r\n    \"db_name\" => \"x\",\r\n    \"encryption_key\" => \"$old\",\r\n    \"app_url\" => \"https://example.test\",\r\n];\r\n",
];
foreach ($layouts as $name => $text) {
    $file = $dir . '/config.php';
    file_put_contents($file, $text);
    kr_write_config($file, $new, $old);
    $c = kr_read_config($file);
    check("$name: the new key is in, the previous key beside it", $c['encryption_key'] === $new && $c['previous_encryption_key'] === $old);
    check("$name: every other value is kept", count($c) === count(require $dir . '/config.php') && ($c['db_name'] ?? '') === 'x');
    kr_write_config($file, $new, null);
    $c = kr_read_config($file);
    check("$name: finishing removes the previous key, nothing else", $c['encryption_key'] === $new && ! array_key_exists('previous_encryption_key', $c) && ($c['db_name'] ?? '') === 'x');
    $lines = substr_count($text, "\n");
    check("$name: the lines and comments are kept", substr_count((string) file_get_contents($file), "\n") === $lines
          && (! str_contains($text, '// keep outside public/') || str_contains((string) file_get_contents($file), '// keep outside public/'))
          && (! str_contains($text, "\r\n") || substr_count((string) file_get_contents($file), "\r\n") === substr_count($text, "\r\n")));
}

$file = $dir . '/config.php';
$twice = "<?php return ['encryption_key'=>'$old','x'=>['encryption_key'=>'$old']];";
file_put_contents($file, $twice);
try { kr_write_config($file, $new, $old); $ok = false; } catch (RuntimeException $e) { $ok = str_contains($e->getMessage(), 'exactly one encryption_key'); }
check('A config with two encryption_key entries is refused, and left as it was', $ok && file_get_contents($file) === $twice);
$computed = "<?php return ['encryption_key'=>getenv('CREWVIA_KEY')];";
file_put_contents($file, $computed);
try { kr_write_config($file, $new, $old); $ok = false; } catch (RuntimeException $e) { $ok = true; }
check('A key that is not written literally (from the environment) is refused, and left as it was', $ok && file_get_contents($file) === $computed);
check('No temporary file is left behind', ! file_exists($file . '.rotating'));

array_map('unlink', glob($dir . '/*'));
rmdir($dir);
file_put_contents(__DIR__ . '/kr-unit-results.json', json_encode($results, JSON_PRETTY_PRINT));
