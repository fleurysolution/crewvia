<?php
/**
 * The privacy notice (P4-M06), open to anybody: the version the agency
 * published last, linked from every public form. Publishing and requests
 * are on /privacy-requests, for administrators.
 */

declare(strict_types=1);

require_once __DIR__ . '/../privacy.php';

$notice = privacy_notice_current();
$pageTitle = t('Privacy notice') . ' · ' . $config['app_name'];

render('privacy', compact('notice'));
