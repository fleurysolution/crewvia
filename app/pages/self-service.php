<?php
/**
 * My self-service (P2-M07): the worker's home for their own records, with
 * what is waiting on them first. Read only: each card links to the page
 * where the thing is done, so the rules stay in one place.
 */

require_once __DIR__ . '/../hr-requests.php';

require_role('worker');

$cid = hr_self();
if (! $cid) {
    render('unlinked', []);
    return;
}

// Wave 2: the worker has one home, the portal, which holds all of this.
// Only the language travels along, so an old link in French or Spanish stays in it.
$lang = preg_replace('/[^a-z]/', '', strtolower((string) ($_GET['lang'] ?? '')));
redirect('/portal' . ($lang !== '' ? '?lang=' . $lang : '') . '#self-service');
