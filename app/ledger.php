<?php
/**
 * Crewvia's own general ledger (P3-M03).
 *
 * Nothing is keyed twice. What Crewvia records once feeds the books:
 *
 *   record (invoice, payment, credit, bill, claim, payroll period)
 *     -> one balanced journal here, posted by itself (ledger_sync)
 *     -> the QuickBooks export, drawn from these journals (P3-M02)
 *
 * The entries are the ones accounting_entries() builds; each posts once,
 * because a journal's source is unique. A record is corrected by the
 * record's own correction (a payment reversed, a credit note), which posts
 * its own journal. Journals typed by hand are only for what Crewvia never
 * sees: rent, insurance, bank fees, payroll liabilities paid to ADP. They
 * are drafted by one person and posted by another, and go to QuickBooks
 * the same way.
 *
 * Rules:
 *   - every journal balances, or it is not posted
 *   - a journal counts on its posting date, always in an open month: a
 *     record whose month was closed before it reached the ledger counts
 *     on the day it arrived, and keeps its own date beside it
 *   - a posted journal is never changed or deleted (triggers refuse it);
 *     a manual one is cancelled by a correcting journal
 *   - each posted journal carries the SHA-256 of its content and of the
 *     journal before it: a change made around the triggers breaks the chain
 *   - the ledger is synced outside any transaction, under a lock, so two
 *     requests never post the same record or fork the chain
 */

declare(strict_types=1);

require_once __DIR__ . '/accounting.php';
require_once __DIR__ . '/periods.php';

/** Accounts the records post to with their own subledgers: not for manual journals. */
const LEDGER_CONTROL_ACCOUNTS = ['accounts_receivable', 'accounts_payable'];

function ledger_lock(): bool
{
    return (int) val("SELECT GET_LOCK(CONCAT('crewvia_ledger_', DATABASE()), 10)") === 1;
}

function ledger_unlock(): void
{
    val("SELECT RELEASE_LOCK(CONCAT('crewvia_ledger_', DATABASE()))");
}

/** The fingerprint of a journal as stored, chained to the one before it. */
function ledger_hash(array $j, array $lines): string
{
    $amt = static fn($v): string => number_format((float) $v, 2, '.', '');

    return hash('sha256', json_encode([
        (string) $j['prev_hash'], (int) $j['chain_no'], (int) $j['id'], (string) $j['kind'], $j['source'], $j['export_key'],
        (string) $j['doc_date'], (string) $j['posted_on'], (string) $j['memo'], $amt($j['total']), $j['reverses_id'] === null ? null : (int) $j['reverses_id'],
        array_map(fn($l) => [(string) $l['account_key'], $amt($l['debit']), $amt($l['credit']), $l['party'], $l['class']], $lines),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** Post a draft: number it in the chain and seal it. The caller holds the lock. */
function ledger_seal(int $id, ?int $approver): void
{
    $last = row("SELECT chain_no, hash FROM gl_journals WHERE status = 'posted' ORDER BY chain_no DESC LIMIT 1");
    $j = row('SELECT * FROM gl_journals WHERE id = ?', [$id]);
    $j['chain_no'] = $last ? (int) $last['chain_no'] + 1 : 1;
    $j['prev_hash'] = $last ? (string) $last['hash'] : str_repeat('0', 64);
    if ($j['kind'] !== 'source') {
        // Random tail: ids restart after a rollback, keys already exported must not.
        $j['export_key'] = 'gl_journal:' . $id . ':' . bin2hex(random_bytes(4));
    }
    $lines = rows('SELECT * FROM gl_lines WHERE journal_id = ? ORDER BY id', [$id]);
    q("UPDATE gl_journals SET status = 'posted', posted_at = NOW(), approved_by = ?, chain_no = ?, prev_hash = ?, export_key = ?, hash = ? WHERE id = ? AND status = 'draft'",
      [$approver, $j['chain_no'], $j['prev_hash'], $j['export_key'], ledger_hash($j, $lines), $id]);
}

/** Insert a journal as a draft with its lines. */
function ledger_draft(string $kind, ?string $source, string $docDate, string $postedOn, string $memo, array $lines, ?int $reverses = null, ?string $reason = null): int
{
    q('INSERT INTO gl_journals (kind, source, export_key, doc_date, posted_on, memo, total, reverses_id, reason, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)',
      [$kind, $source, $source, $docDate, $postedOn, mb_substr($memo, 0, 500), round(array_sum(array_column($lines, 'debit')), 2), $reverses, $reason,
       $kind === 'source' ? null : (uid() ?: null)]);
    $id = (int) db()->lastInsertId();
    foreach ($lines as $l) {
        q('INSERT INTO gl_lines (journal_id, account_key, debit, credit, party, class) VALUES (?,?,?,?,?,?)',
          [$id, $l['account'], round((float) $l['debit'], 2), round((float) $l['credit'], 2),
           ($l['party'] ?? '') === '' ? null : mb_substr((string) $l['party'], 0, 190), ($l['class'] ?? '') === '' ? null : mb_substr((string) $l['class'], 0, 190)]);
    }

    return $id;
}

function ledger_balanced(array $lines): bool
{
    $dr = round(array_sum(array_column($lines, 'debit')), 2);
    $cr = round(array_sum(array_column($lines, 'credit')), 2);

    return $dr > 0 && abs($dr - $cr) < 0.005;
}

/**
 * Post every record not yet in the ledger, dated today or earlier.
 * Returns [journals posted, entries that could not be posted].
 */
function ledger_sync(): array
{
    if (db()->inTransaction()) {
        throw new LogicException('ledger_sync runs outside a transaction');
    }
    if (! ledger_lock()) {
        return [0, []];
    }
    $today = date('Y-m-d');
    $posted = 0;
    $skipped = [];
    try {
        db()->beginTransaction();
        $have = [];
        foreach (rows("SELECT source FROM gl_journals WHERE kind = 'source'") as $r) {
            $have[$r['source']] = true;
        }
        foreach (accounting_entries($today, true) as $e) {
            if (isset($have[$e['source']])) {
                continue;
            }
            if (! $e['lines'] || ! ledger_balanced($e['lines'])) {
                $skipped[] = $e['source'];
                continue;
            }
            $on = period_closed($e['date']) ? $today : $e['date'];
            ledger_seal(ledger_draft('source', $e['source'], $e['date'], $on, $e['memo'], $e['lines']), null);
            $posted++;
        }
        db()->commit();
    } catch (Throwable $ex) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        ledger_unlock();
        throw $ex;
    }
    ledger_unlock();

    return [$posted, $skipped];
}

/**
 * The posted journals not yet exported to QuickBooks, dated up to $through,
 * in the shape the export takes. Payroll periods only when the export of
 * payroll is on (ADP may post it).
 */
function ledger_unexported(string $through, bool $payroll): array
{
    $done = [];
    foreach (rows('SELECT active_key FROM accounting_sources WHERE active_key IS NOT NULL') as $r) {
        $done[$r['active_key']] = true;
    }
    $journals = [];
    foreach (rows("SELECT * FROM gl_journals WHERE status = 'posted' AND posted_on <= ? ORDER BY posted_on, export_key", [$through]) as $j) {
        if (isset($done[$j['export_key']]) || (! $payroll && str_starts_with((string) $j['source'], 'payroll_run:'))) {
            continue;
        }
        $journals[(int) $j['id']] = ['source' => (string) $j['export_key'], 'date' => (string) $j['posted_on'], 'memo' => (string) $j['memo'], 'lines' => []];
    }
    if ($journals) {
        foreach (rows('SELECT * FROM gl_lines WHERE journal_id IN (' . implode(',', array_keys($journals)) . ') ORDER BY id') as $l) {
            $journals[(int) $l['journal_id']]['lines'][] = ['account' => $l['account_key'], 'debit' => (float) $l['debit'], 'credit' => (float) $l['credit'],
                                                             'party' => $l['party'], 'class' => $l['class']];
        }
    }

    return array_values($journals);
}

// ── manual journals ─────────────────────────────────────────────────────

/** The accounts a manual journal may use. */
function ledger_manual_accounts(): array
{
    return array_filter(accounting_accounts(), fn($a) => (int) $a['is_active'] === 1 && ! in_array($a['account_key'], LEDGER_CONTROL_ACCOUNTS, true));
}

/** Read the posted form's lines: only the rows with an account or an amount. */
function ledger_lines_input(array $post): array
{
    $out = [];
    foreach ((array) ($post['account'] ?? []) as $k => $account) {
        $dr = trim((string) (($post['debit'] ?? [])[$k] ?? ''));
        $cr = trim((string) (($post['credit'] ?? [])[$k] ?? ''));
        if (trim((string) $account) === '' && $dr === '' && $cr === '') {
            continue;
        }
        $out[] = ['account' => (string) $account, 'debit' => $dr, 'credit' => $cr,
                  'party' => trim((string) (($post['party'] ?? [])[$k] ?? '')), 'class' => trim((string) (($post['class'] ?? [])[$k] ?? ''))];
    }

    return $out;
}

function ledger_manual_refusal(string $date, string $memo, array &$lines): ?string
{
    if (! valid_date($date) || $date > date('Y-m-d')) {
        return t('Date the journal today or earlier.');
    }
    if ($why = period_guard($date)) {
        return $why;
    }
    if (mb_strlen(trim($memo)) < 3) {
        return t('Say what the journal records.');
    }
    if (count($lines) < 2 || count($lines) > 20) {
        return t('A journal has between 2 and 20 lines.');
    }
    $allowed = ledger_manual_accounts();
    foreach ($lines as &$l) {
        if (in_array($l['account'], LEDGER_CONTROL_ACCOUNTS, true)) {
            return t('Client and vendor balances are recorded on Receivables and payables, never by journal: they would no longer match the invoices.');
        }
        if (! isset($allowed[$l['account']])) {
            return t('Choose an active account on every line.');
        }
        foreach (['debit', 'credit'] as $side) {
            $v = (string) $l[$side];
            if ($v !== '' && ! preg_match('/^\d{1,11}(\.\d{1,2})?$/', $v)) {
                return t('Amounts are positive, with at most two decimals.');
            }
            $l[$side] = $v === '' ? 0.0 : round((float) $v, 2);
        }
        if (($l['debit'] > 0) === ($l['credit'] > 0)) {
            return t('Each line is either a debit or a credit.');
        }
        if (mb_strlen($l['party']) > 190 || mb_strlen($l['class']) > 190) {
            return t('A name or class is at most 190 characters.');
        }
    }
    unset($l);
    if (! ledger_balanced($lines)) {
        return t('Debits and credits must be equal: debits :dr, credits :cr.', ['dr' => money(array_sum(array_column($lines, 'debit'))), 'cr' => money(array_sum(array_column($lines, 'credit')))]);
    }

    return null;
}

/** Draft a manual journal. Returns [id, refusal]. */
function ledger_manual_create(string $date, string $memo, array $lines): array
{
    if ($why = ledger_manual_refusal($date, $memo, $lines)) {
        return [0, $why];
    }

    return [ledger_draft('manual', null, $date, $date, trim($memo), $lines), null];
}

/** Post a draft. Someone other than its author. The caller holds the lock. */
function ledger_manual_approve(int $id): ?string
{
    $j = row("SELECT * FROM gl_journals WHERE id = ? AND kind = 'manual' FOR UPDATE", [$id]);
    if (! $j || $j['status'] !== 'draft') {
        return t('That draft journal does not exist or is no longer a draft.');
    }
    if ((int) $j['created_by'] === uid()) {
        return t('Someone other than the author posts a journal.');
    }
    $amount = static fn($v): string => (float) $v > 0 ? number_format((float) $v, 2, '.', '') : '';
    $lines = array_map(fn($l) => ['account' => $l['account_key'], 'debit' => $amount($l['debit']), 'credit' => $amount($l['credit']),
                                  'party' => (string) $l['party'], 'class' => (string) $l['class']],
                       rows('SELECT * FROM gl_lines WHERE journal_id = ? ORDER BY id', [$id]));
    // Checked again: the month may have closed, or an account been switched off, since it was drafted.
    if ($why = ledger_manual_refusal((string) $j['doc_date'], (string) $j['memo'], $lines)) {
        return $why;
    }
    ledger_seal($id, uid() ?: null);

    return null;
}

function ledger_manual_discard(int $id): ?string
{
    $j = row("SELECT * FROM gl_journals WHERE id = ? AND kind = 'manual' FOR UPDATE", [$id]);
    if (! $j || $j['status'] !== 'draft') {
        return t('That draft journal does not exist or is no longer a draft.');
    }
    if ((int) $j['created_by'] !== uid() && ! can('admin')) {
        return t('Only its author or an administrator discards a draft.');
    }
    q("UPDATE gl_journals SET status = 'discarded' WHERE id = ?", [$id]);

    return null;
}

/** Cancel a posted manual journal by a correcting one. Returns [id, refusal]. The caller holds the lock. */
function ledger_reverse(int $id, string $date, string $reason): array
{
    $j = row('SELECT * FROM gl_journals WHERE id = ? FOR UPDATE', [$id]);
    if (! $j || $j['status'] !== 'posted') {
        return [0, t('That journal does not exist or is not posted.')];
    }
    if ($j['kind'] !== 'manual') {
        return [0, t('A journal posted from a record is corrected on the record: reverse the payment, or issue a credit note.')];
    }
    if ($j['reversed_by_id'] !== null) {
        return [0, t('That journal is already reversed.')];
    }
    if (mb_strlen(trim($reason)) < 3) {
        return [0, t('Say why the journal is reversed.')];
    }
    if (! valid_date($date) || $date > date('Y-m-d') || $date < (string) $j['posted_on']) {
        return [0, t('Date the reversal between the journal date and today.')];
    }
    if ($why = period_guard($date)) {
        return [0, $why];
    }
    $lines = array_map(fn($l) => ['account' => $l['account_key'], 'debit' => (float) $l['credit'], 'credit' => (float) $l['debit'], 'party' => $l['party'], 'class' => $l['class']],
                       rows('SELECT * FROM gl_lines WHERE journal_id = ? ORDER BY id', [$id]));
    $rev = ledger_draft('reversal', null, $date, $date, 'Reversal of journal ' . $id . ': ' . $j['memo'], $lines, $id, mb_substr(trim($reason), 0, 500));
    ledger_seal($rev, uid() ?: null);
    q('UPDATE gl_journals SET reversed_by_id = ? WHERE id = ?', [$rev, $id]);

    return [$rev, null];
}

// ── chart of accounts ───────────────────────────────────────────────────

function ledger_account_add(string $number, string $label, string $side): ?string
{
    $label = trim($label);
    if (! preg_match('/^\d{4,6}$/', $number)) {
        return t('An account number has 4 to 6 digits.');
    }
    if (val('SELECT COUNT(*) FROM accounting_accounts WHERE number = ?', [$number])) {
        return t('That account number is taken.');
    }
    if (mb_strlen($label) < 3 || mb_strlen($label) > 120 || preg_match('/^[=+@-]/', $label)) {
        return t('Name the account in 3 to 120 characters.');
    }
    if (! in_array($side, ['asset', 'liability', 'equity', 'income', 'expense'], true)) {
        return t('Choose what kind of account it is.');
    }
    q('INSERT INTO accounting_accounts (account_key, label, side, qb_account, sort_order, number, is_system, is_active, updated_by, updated_at) VALUES (?,?,?,?,?,?,0,1,?,NOW())',
      ['acct_' . $number, $label, $side, $label, (int) $number, $number, uid() ?: null]);

    return null;
}

function ledger_account_active(string $key, bool $active): ?string
{
    $a = row('SELECT * FROM accounting_accounts WHERE account_key = ?', [$key]);
    if (! $a) {
        return t('That account does not exist.');
    }
    if ((int) $a['is_system'] === 1) {
        return t('The records post to this account: it stays active.');
    }
    q('UPDATE accounting_accounts SET is_active = ?, updated_by = ?, updated_at = NOW() WHERE account_key = ?', [$active ? 1 : 0, uid() ?: null, $key]);

    return null;
}

// ── what the ledger shows ───────────────────────────────────────────────

/**
 * The chain checked end to end: each journal's fingerprint, its link to
 * the one before, its balance, and whether the protecting triggers exist.
 */
function ledger_integrity(): array
{
    $prev = str_repeat('0', 64);
    $n = 0;
    $broken = null;
    $unbalanced = [];
    $lines = [];
    foreach (rows("SELECT l.* FROM gl_lines l JOIN gl_journals j ON j.id = l.journal_id WHERE j.status = 'posted' ORDER BY l.id") as $l) {
        $lines[(int) $l['journal_id']][] = $l;
    }
    foreach (rows("SELECT * FROM gl_journals WHERE status = 'posted' ORDER BY chain_no") as $j) {
        $n++;
        $mine = $lines[(int) $j['id']] ?? [];
        if ($broken === null && ((int) $j['chain_no'] !== $n || ! hash_equals($prev, (string) $j['prev_hash']) || ! hash_equals(ledger_hash($j, $mine), (string) $j['hash']))) {
            $broken = (int) $j['id'];
        }
        if (! ledger_balanced(array_map(fn($l) => ['debit' => (float) $l['debit'], 'credit' => (float) $l['credit']], $mine))) {
            $unbalanced[] = (int) $j['id'];
        }
        $prev = (string) $j['hash'];
    }
    $triggers = (int) val("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE() AND trigger_name LIKE 'gl\\_%'");

    return ['checked' => $n, 'broken' => $broken, 'unbalanced' => $unbalanced, 'triggers' => $triggers, 'ok' => $broken === null && ! $unbalanced];
}

/**
 * Records that no longer say what their posted journal says: changed after
 * posting, or gone. The journal stands; the difference is for someone to
 * look into, and correct on the record.
 */
function ledger_drift(): array
{
    $canon = static fn(array $lines): array => (function () use ($lines) {
        $out = array_map(fn($l) => implode('|', [$l['account'], number_format((float) $l['debit'], 2, '.', ''), number_format((float) $l['credit'], 2, '.', ''), (string) $l['party'], (string) $l['class']]), $lines);
        sort($out);
        return $out;
    })();
    $now = [];
    foreach (accounting_entries(date('Y-m-d'), true) as $e) {
        $now[$e['source']] = $e;
    }
    $posted = [];
    foreach (rows("SELECT l.*, j.source, j.doc_date FROM gl_lines l JOIN gl_journals j ON j.id = l.journal_id WHERE j.kind = 'source' AND j.status = 'posted' ORDER BY l.id") as $l) {
        $posted[$l['source']]['date'] = (string) $l['doc_date'];
        $posted[$l['source']]['lines'][] = ['account' => $l['account_key'], 'debit' => $l['debit'], 'credit' => $l['credit'], 'party' => $l['party'], 'class' => $l['class']];
    }
    $out = [];
    foreach ($posted as $source => $p) {
        $e = $now[$source] ?? null;
        if (! $e) {
            $out[] = ['source' => $source, 'what' => t('The record no longer makes this entry.')];
        } elseif ($canon($e['lines']) !== $canon($p['lines'])) {
            $out[] = ['source' => $source, 'what' => t('The record now gives other amounts or accounts.')];
        } elseif ($e['date'] !== $p['date']) {
            $out[] = ['source' => $source, 'what' => t('The record is now dated :d, not :p.', ['d' => $e['date'], 'p' => $p['date']])];
        }
    }

    return $out;
}

/** The ledger by account for one month, on posting dates. */
function ledger_trial_balance(string $period): array
{
    [$start, $end] = period_bounds($period);
    $accounts = accounting_accounts();
    $rows = [];
    foreach (rows("SELECT l.account_key,
                          SUM(CASE WHEN j.posted_on < ? THEN l.debit - l.credit ELSE 0 END) AS opening,
                          SUM(CASE WHEN j.posted_on BETWEEN ? AND ? THEN l.debit ELSE 0 END) AS debit,
                          SUM(CASE WHEN j.posted_on BETWEEN ? AND ? THEN l.credit ELSE 0 END) AS credit
                   FROM gl_lines l JOIN gl_journals j ON j.id = l.journal_id
                   WHERE j.status = 'posted' AND j.posted_on <= ? GROUP BY l.account_key", [$start, $start, $end, $start, $end, $end]) as $r) {
        $a = $accounts[$r['account_key']];
        $opening = round((float) $r['opening'], 2);
        $rows[] = ['key' => $r['account_key'], 'number' => (string) $a['number'], 'label' => $a['label'], 'side' => $a['side'],
                   'opening' => $opening, 'debit' => round((float) $r['debit'], 2), 'credit' => round((float) $r['credit'], 2),
                   'closing' => round($opening + (float) $r['debit'] - (float) $r['credit'], 2)];
    }
    usort($rows, fn($x, $y) => [$x['number'], $x['key']] <=> [$y['number'], $y['key']]);
    $sum = fn(string $k) => round(array_sum(array_column($rows, $k)), 2);
    $closingDr = round(array_sum(array_map(fn($r) => max(0, $r['closing']), $rows)), 2);
    $closingCr = round(array_sum(array_map(fn($r) => max(0, -$r['closing']), $rows)), 2);

    return ['rows' => $rows, 'debit' => $sum('debit'), 'credit' => $sum('credit'), 'closing_debit' => $closingDr, 'closing_credit' => $closingCr,
            'balanced' => abs($sum('debit') - $sum('credit')) < 0.005 && abs($closingDr - $closingCr) < 0.005];
}

/** One account's lines between two dates, with the running balance. */
function ledger_account_lines(string $key, string $from, string $to): array
{
    $balance = round((float) val("SELECT COALESCE(SUM(l.debit - l.credit), 0) FROM gl_lines l JOIN gl_journals j ON j.id = l.journal_id
                                  WHERE j.status = 'posted' AND l.account_key = ? AND j.posted_on < ?", [$key, $from]), 2);
    $opening = $balance;
    $out = [];
    foreach (rows("SELECT l.*, j.posted_on, j.doc_date, j.memo, j.kind, j.source FROM gl_lines l JOIN gl_journals j ON j.id = l.journal_id
                   WHERE j.status = 'posted' AND l.account_key = ? AND j.posted_on BETWEEN ? AND ? ORDER BY j.posted_on, j.chain_no, l.id", [$key, $from, $to]) as $l) {
        $balance = round($balance + (float) $l['debit'] - (float) $l['credit'], 2);
        $l['balance'] = $balance;
        $out[] = $l;
    }

    return ['opening' => $opening, 'lines' => $out, 'closing' => $balance];
}

/**
 * Each account in the ledger beside what was exported to QuickBooks, up to
 * a date. The difference is what is still to export, or payroll left to
 * ADP while the payroll export is off.
 */
function ledger_vs_export(string $through): array
{
    $ledger = [];
    foreach (rows("SELECT l.account_key, SUM(l.debit - l.credit) AS net FROM gl_lines l JOIN gl_journals j ON j.id = l.journal_id
                   WHERE j.status = 'posted' AND j.posted_on <= ? GROUP BY l.account_key", [$through]) as $r) {
        $ledger[$r['account_key']] = round((float) $r['net'], 2);
    }
    $sent = [];
    foreach (rows('SELECT account_key, SUM(debit - credit) AS net FROM accounting_lines WHERE txn_date <= ? GROUP BY account_key', [$through]) as $r) {
        $sent[$r['account_key']] = round((float) $r['net'], 2);
    }
    $out = [];
    foreach (accounting_accounts() as $key => $a) {
        if (! isset($ledger[$key]) && ! isset($sent[$key])) {
            continue;
        }
        $l = $ledger[$key] ?? 0.0;
        $s = $sent[$key] ?? 0.0;
        $out[] = ['key' => $key, 'number' => (string) $a['number'], 'label' => $a['label'], 'ledger' => $l, 'exported' => $s, 'difference' => round($l - $s, 2)];
    }
    usort($out, fn($x, $y) => [$x['number'], $x['key']] <=> [$y['number'], $y['key']]);

    return $out;
}
