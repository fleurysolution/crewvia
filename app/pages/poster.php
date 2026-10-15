<?php
/**
 * A job poster (P5-M46): one printable page per requisition, to put up on a
 * site, a union hall, a training school or a website, with a QR code that
 * opens the public application page. Whoever scans it applies on their
 * phone, says when they can start, and gets a portal (P5-M45).
 *
 *   /poster?id=VACANCY            the poster, ready to print (Letter)
 *   /poster?id=VACANCY&qr=1       the QR code alone, to save as an image
 *
 * The QR code encodes the configured address of the site (app_url), not
 * the address the office happens to be browsing from, so a poster printed
 * from a test or a local copy still sends people to the real page. It is
 * drawn in the browser by the bundled library; nothing leaves the site.
 */

declare(strict_types=1);

require_role('recruiter');

$vacancy = row("SELECT v.*, COALESCE(v.pay_rate, l.pay_rate) AS shown_pay, COALESCE(v.per_diem_rate, l.per_diem_rate) AS shown_diem,
                       COALESCE(v.guarantee_hours, l.guarantee_hours) AS shown_hours, j.title AS project, j.site_city, j.site_state, j.starts_on AS job_starts
                FROM vacancies v JOIN jobs j ON j.id = v.job_id LEFT JOIN job_order_lines l ON l.id = v.order_line_id WHERE v.id = ?", [(int) ($_GET['id'] ?? 0)]);
if (! $vacancy) {
    refuse(404, t('That requisition does not exist.'), '/requisitions');
}
$applyUrl = rtrim((string) ($config['app_url'] ?? ''), '/') . '/apply?id=' . (int) $vacancy['id'];
$brand = (string) ($config['app_name'] ?? 'Crewvia');
try { $named = val("SELECT setting_value FROM platform_settings WHERE setting_key = 'brand_name'"); if ($named) { $brand = (string) $named; } } catch (Throwable $ignored) {}
log_activity('opened a job poster', 'vacancy', (int) $vacancy['id'], (string) $vacancy['title']);

require __DIR__ . '/../views/poster.php';
