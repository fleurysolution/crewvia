<?php
/** P5-M73: POST /nav-mark - bookmark a screen of the menu, or remove the bookmark. */

require_login();
require_once __DIR__ . '/../nav-marks.php';
require_once __DIR__ . '/../navigation.php';

$path = '/' . trim((string) ($_POST['path'] ?? ''), '/');
$menu = array_column(workspace_nav(user()), 1, 0);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ! isset($menu[$path]) || ! workspace_can_open($path)) {
    refuse(422, t('Only a screen of the menu can be bookmarked.'), '/');
}
flash(nm_toggle((int) uid(), $path) ? t(':screen is in your bookmarks.', ['screen' => t($menu[$path])]) : t(':screen is no longer bookmarked.', ['screen' => t($menu[$path])]));
redirect($path);
