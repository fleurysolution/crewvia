<?php
/**
 * Today (layout walkthrough, wave 2): the home page of the recruiting,
 * Logistics and payroll desks. Other roles are sent to their own home
 * (home_for() in app/home.php). See app/home.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../home.php';

require_login();
$homeRole = (string) (user()['role'] ?? '');
if (! in_array($homeRole, ['recruiter', 'hotels', 'payroll'], true)) {
    redirect(home_for($homeRole));
}
$homeData = match ($homeRole) {
    'recruiter' => home_recruiter(),
    'hotels'    => home_logistics(),
    'payroll'   => home_payroll(),
};
$pageTitle = t('Today') . ' · ' . $config['app_name'];
render('home', compact('homeRole', 'homeData'));
