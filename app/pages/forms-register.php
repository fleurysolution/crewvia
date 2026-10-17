<?php
/**
 * P5-M68: the register of the W-4s and I-9s, for the owner, an auditor, an inspection. One row per form:
 * who signed, when, in person and in front of whom, who accepted it, Section 2 (by whom, when, on time),
 * E-Verify, the copies kept, how many times it was opened - and what is wrong, in red. CSV export, never
 * with a Social Security number. Administrators, and the readers they authorize for identity documents.
 */

require_login();
require_once __DIR__ . '/../tax-forms.php';
if (! can('admin') && ! id_is_reader(uid())) {
    require_role('admin');
}

$form = in_array($_GET['form'] ?? '', ['w4', 'i9'], true) ? $_GET['form'] : '';
$status = in_array($_GET['status'] ?? '', ['submitted', 'accepted', 'returned', 'replaced'], true) ? $_GET['status'] : '';
$from = valid_date((string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : '';
$to = valid_date((string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : '';
$q = trim((string) ($_GET['q'] ?? ''));

$where = ["f.status <> 'draft'"];
$args = [];
if ($form) { $where[] = 'f.form = ?'; $args[] = $form; }
if ($status) { $where[] = 'f.status = ?'; $args[] = $status; }
if ($from) { $where[] = 'f.signed_at >= ?'; $args[] = $from . ' 00:00:00'; }
if ($to) { $where[] = 'f.signed_at <= ?'; $args[] = $to . ' 23:59:59'; }
if ($q !== '') { $where[] = 'c.full_name LIKE ?'; $args[] = '%' . $q . '%'; }

$rows = rows('SELECT f.id, f.form, f.status, f.candidate_id, f.signer_name, f.signed_at, f.witness_id, f.reviewed_at, f.everify_case, f.everify_result,
                     c.full_name, w.name AS witness, r.name AS reviewer,
                     e.verifier_name, e.verified_at, e.first_day, e.review_due_on, e.i9_form_id,
                     (SELECT COUNT(*) FROM i9_document_copies k WHERE k.candidate_id = f.candidate_id) AS copies,
                     (SELECT COUNT(*) FROM worker_tax_form_access a WHERE a.form_id = f.id AND a.action = \'opened\') AS opened,
                     f.encrypted_payload, f.payload_hash
              FROM worker_tax_forms f JOIN candidates c ON c.id = f.candidate_id
              LEFT JOIN users w ON w.id = f.witness_id LEFT JOIN users r ON r.id = f.reviewed_by
              LEFT JOIN employment_clearance e ON e.candidate_id = f.candidate_id
              WHERE ' . implode(' AND ', $where) . ' ORDER BY f.signed_at DESC, f.id DESC LIMIT 2000', $args);

foreach ($rows as &$x) {
    // What is wrong, said plainly. The record is checked against what was signed.
    $x['issues'] = [];
    if (! tf_payload($x)) {
        $x['issues'][] = t('Does not match what was signed');
    }
    if (! $x['witness_id']) {
        $x['issues'][] = t('Signed without a witness');
    }
    if ($x['form'] === 'i9' && $x['status'] === 'accepted' && (int) $x['i9_form_id'] === (int) $x['id']) {
        if ($x['review_due_on'] && $x['verified_at'] && substr((string) $x['verified_at'], 0, 10) > $x['review_due_on']) {
            $x['issues'][] = t('Section 2 late');
        }
    }
    if ($x['form'] === 'i9' && $x['status'] === 'submitted' && $x['first_day'] === null) {
        $due = (string) val("SELECT MIN(start_date) FROM placements WHERE candidate_id = ? AND status IN ('confirmed','travelling','on_site','completed')", [(int) $x['candidate_id']]);
        if ($due && tf_business_days_after($due, 3) < date('Y-m-d')) {
            $x['issues'][] = t('Section 2 overdue');
        }
    }
    unset($x['encrypted_payload'], $x['payload_hash']);
}
unset($x);

$statusWord = ['submitted' => t('Signed, being checked'), 'accepted' => t('Accepted'), 'returned' => t('Sent back'), 'replaced' => t('Replaced by a newer one')];

if (isset($_GET['csv'])) {
    log_activity('exported the forms register', 'report', 0, count($rows) . ' forms');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="crewvia-forms-register-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Form id', 'Person', 'Form', 'Status', 'Signed by', 'Signed at', 'Signed in person', 'Witness', 'Accepted at', 'Accepted by',
                   'Section 2 by', 'Section 2 at', 'First day', 'Section 2 due', 'E-Verify case', 'E-Verify result', 'Copies kept', 'Times opened', 'Issues']);
    foreach ($rows as $x) {
        fputcsv($out, array_map('csv_cell', [$x['id'], $x['full_name'], strtoupper($x['form'] === 'w4' ? 'W-4' : 'I-9'), $statusWord[$x['status']] ?? $x['status'], $x['signer_name'],
            $x['signed_at'], $x['witness_id'] ? 'yes' : 'no', $x['witness'], $x['reviewed_at'], $x['reviewer'],
            $x['form'] === 'i9' ? $x['verifier_name'] : '', $x['form'] === 'i9' ? $x['verified_at'] : '', $x['first_day'], $x['form'] === 'i9' ? $x['review_due_on'] : '',
            $x['everify_case'], $x['everify_result'], $x['form'] === 'i9' ? $x['copies'] : '', $x['opened'], implode('; ', $x['issues'])]));
    }
    fclose($out);
    exit;
}

$pageTitle = t('Forms register');
render('forms-register', compact('rows', 'form', 'status', 'from', 'to', 'q', 'statusWord'));
