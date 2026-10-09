<?php
/**
 * Building a chain: who is asked, in what order, for which kind of record.
 *
 * BPMS has this as a visual builder so a company can change its own approval
 * route without anybody editing SQL. Same idea here, trimmed to what an
 * agency needs.
 */

require_role('admin');
require_once __DIR__ . '/../approvals.php';

if (! approvals_available()) {
    refuse(503, t('Approvals are not set up on this workspace yet. Run the upgrade.'));
}

/** Only desks that exist can be asked to approve something. */
const CHAIN_ROLES = ['recruiter', 'hotels', 'payroll', 'admin'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');

    if ($do === 'create_chain') {
        $name    = trim((string) ($_POST['name'] ?? ''));
        $subject = (string) ($_POST['applies_to'] ?? '');

        if ($name === '' || mb_strlen($name) > 120) {
            flash(t('Give the chain a name.'), 'err');
            redirect('/approval-chains');
        }

        if (! array_key_exists($subject, approval_subjects())) {
            flash(t('Choose what this chain approves.'), 'err');
            redirect('/approval-chains');
        }

        if (row('SELECT id FROM approval_chains WHERE name = ?', [$name])) {
            flash(t('A chain with that name already exists.'), 'err');
            redirect('/approval-chains');
        }

        // One default per kind, so resolving a chain is never ambiguous.
        q('UPDATE approval_chains SET is_default = 0 WHERE applies_to = ?', [$subject]);
        q('INSERT INTO approval_chains (name, applies_to, is_default) VALUES (?,?,1)',
          [$name, $subject]);

        log_activity('created approval chain', 'approval_chain', (int) db()->lastInsertId(), $name);
        flash(t(':name created. Add the steps it should ask for.', ['name' => $name]));
        redirect('/approval-chains');
    }

    if ($do === 'add_step') {
        $chain = row('SELECT * FROM approval_chains WHERE id = ?',
                     [(int) ($_POST['chain_id'] ?? 0)]);

        if (! $chain) {
            refuse(404, t('That chain no longer exists.'));
        }

        $label = trim((string) ($_POST['label'] ?? ''));
        $role  = (string) ($_POST['role_slug'] ?? '');
        $gate  = ($_POST['gate_type'] ?? 'sequential') === 'parallel' ? 'parallel' : 'sequential';

        if ($label === '' || mb_strlen($label) > 120 || ! in_array($role, CHAIN_ROLES, true)) {
            flash(t('A step needs a name and a desk that exists.'), 'err');
            redirect('/approval-chains');
        }

        $next = (int) val('SELECT COALESCE(MAX(step_order), 0) + 1 FROM approval_chain_steps
                           WHERE chain_id = ?', [$chain['id']]);

        q('INSERT INTO approval_chain_steps
             (chain_id, step_order, label, role_slug, gate_type, is_required)
           VALUES (?,?,?,?,?,?)',
          [$chain['id'], $next, $label, $role, $gate,
           isset($_POST['is_required']) ? 1 : 0]);

        log_activity('added approval step', 'approval_chain', (int) $chain['id'], $label);
        flash(t('Step added.'));
        redirect('/approval-chains');
    }

    if ($do === 'remove_step') {
        $step = row('SELECT * FROM approval_chain_steps WHERE id = ?',
                    [(int) ($_POST['step_id'] ?? 0)]);

        if ($step) {
            q('DELETE FROM approval_chain_steps WHERE id = ?', [$step['id']]);
            log_activity('removed approval step', 'approval_chain', (int) $step['chain_id'],
                         (string) $step['label']);
            flash(t('Step removed. Records already in the chain keep the steps they were given.'));
        }

        redirect('/approval-chains');
    }

    if ($do === 'toggle_chain') {
        $chain = row('SELECT * FROM approval_chains WHERE id = ?',
                     [(int) ($_POST['chain_id'] ?? 0)]);

        if ($chain) {
            q('UPDATE approval_chains SET is_active = ? WHERE id = ?',
              [$chain['is_active'] ? 0 : 1, $chain['id']]);
            flash($chain['is_active']
                ? t('Chain switched off. Nothing new will be routed through it.')
                : t('Chain switched on.'));
        }

        redirect('/approval-chains');
    }

    refuse(422, t('Unknown approval chain action.'));
}

$chains = rows('SELECT * FROM approval_chains ORDER BY applies_to, is_default DESC, name');

foreach ($chains as &$chain) {
    $chain['steps'] = rows('SELECT * FROM approval_chain_steps
                            WHERE chain_id = ? ORDER BY step_order, id', [$chain['id']]);
    $chain['in_use'] = (int) val("SELECT COUNT(DISTINCT subject_id) FROM approval_requests
                                  WHERE chain_id = ? AND status <> 'cancelled'", [$chain['id']]);
}

unset($chain);

$pageTitle = t('Approval chains') . ' · ' . $config['app_name'];

render('approval-chains', compact('chains'));
