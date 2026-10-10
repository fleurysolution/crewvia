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

$home = self_service_home($cid);

render('self-service', compact('home'));
