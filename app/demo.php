<?php
/**
 * P5-M35: the demonstration data, and how it is removed.
 *
 * install/demo-load.php creates a demonstration workspace (accounts named
 * "Employee Number 12", "Supervisor Number 3"... with plus-addresses of one
 * mailbox, three plants, about 1,000 people on site) and writes the id of
 * every person, account, client, plant, hotel and van it creates into
 * demo_records. install/demo-flush.php removes them, and everything that
 * hangs off them: an assignment of a demo person, a timesheet of that
 * assignment, a notification to a demo account, an application to a demo
 * requisition... found by the key columns below, again and again until
 * nothing new is found. A row that belongs to nobody in the demo is never
 * touched. The flush shows what it would remove, table by table, before it
 * removes anything.
 */

declare(strict_types=1);

/** The sets the registry seeds, and the column names that point into each. */
const DEMO_KEYS = [
    'users'                     => ['user_id'],
    'candidates'                => ['candidate_id'],
    'clients'                   => ['client_id'],
    'jobs'                      => ['job_id'],
    'hotels'                    => ['hotel_id'],
    'vehicles'                  => ['vehicle_id'],
    'placements'                => ['placement_id'],
    'vacancies'                 => ['vacancy_id'],
    'applications'              => ['application_id'],
    'timesheets'                => ['timesheet_id'],
    'job_order_lines'           => ['order_line_id'],
    'email_campaigns'           => ['campaign_id'],
    'lodging'                   => ['lodging_id'],
    'worker_documents'          => ['document_id'],
    'invitations'               => ['invitation_id'],
    'approval_requests'         => ['request_id'],
    'employment_contracts'      => ['contract_id'],
];

function demo_registry_ready(): void
{
    q('CREATE TABLE IF NOT EXISTS demo_records (
         table_name VARCHAR(64) NOT NULL,
         record_id  INT UNSIGNED NOT NULL,
         created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (table_name, record_id)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
}

function demo_mark(string $table, int $id): void
{
    q('INSERT IGNORE INTO demo_records (table_name, record_id) VALUES (?,?)', [$table, $id]);
}

function demo_loaded(): int
{
    try {
        return (int) val('SELECT COUNT(*) FROM demo_records');
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Tables the database itself keeps append-only (a trigger refuses a delete):
 * the audit log, the general ledger. The demonstration's lines in them stay,
 * as the record of what was done; the flush reports them.
 */
function demo_append_only(): array
{
    return array_column(rows("SELECT DISTINCT event_object_table AS t FROM information_schema.triggers WHERE trigger_schema = DATABASE() AND event_manipulation = 'DELETE'"), 't');
}

/** Every base table, with its columns and primary key. */
function demo_schema(): array
{
    $tables = [];
    foreach (rows("SELECT c.table_name AS t, c.column_name AS c, c.column_key AS k FROM information_schema.columns c
                   JOIN information_schema.tables x ON x.table_schema = c.table_schema AND x.table_name = c.table_name AND x.table_type = 'BASE TABLE'
                   WHERE c.table_schema = DATABASE() ORDER BY c.table_name, c.ordinal_position") as $r) {
        $tables[$r['t']]['columns'][] = $r['c'];
        if ($r['k'] === 'PRI') {
            $tables[$r['t']]['pk'][] = $r['c'];
        }
    }

    return $tables;
}

/**
 * What a flush would remove: [table => [row keys...]] where a row key is
 * the row's primary key values. Found from the registry, then by every key
 * column that points into a set, until nothing new turns up.
 */
function demo_plan(): array
{
    $schema = demo_schema();
    $sets = [];
    foreach (rows('SELECT table_name, record_id FROM demo_records') as $r) {
        $sets[$r['table_name']][(int) $r['record_id']] = true;
    }
    $columnSet = [];
    foreach (DEMO_KEYS as $table => $columns) {
        foreach ($columns as $c) {
            $columnSet[$c] = $table;
        }
    }
    $remove = [];
    $keep = array_flip(demo_append_only());
    for ($pass = 0; $pass < 12; $pass++) {
        $grew = false;
        foreach ($schema as $table => $info) {
            if ($table === 'demo_records' || empty($info['pk']) || isset($keep[$table])) {
                continue;
            }
            $where = [];
            $byWho = [];
            // The table's own rows, when it is one of the sets (by its id).
            if (isset(DEMO_KEYS[$table]) && ! empty($sets[$table]) && $info['pk'] === ['id']) {
                $ids = array_keys($sets[$table]);
                $where[] = 'id IN (' . implode(',', array_map('intval', $ids)) . ')';
            }
            foreach ($info['columns'] as $c) {
                $target = $columnSet[$c] ?? null;
                // jobs.client_id, vacancies.job_id... and a table's own id column is never a pointer.
                if ($target !== null && ! empty($sets[$target]) && ! ($target === $table && $c === 'id')) {
                    $in = '`' . $c . '` IN (' . implode(',', array_map('intval', array_keys($sets[$target]))) . ')';
                    // P5-M109: user_id says who DID it. A row about a real person (a note, a call, who opened their bank
                    // details) written by a demonstration account is that person's record and audit trail: it stays.
                    if ($target === 'users' && $table !== 'users' && ($real = demo_names_real_person($info['columns'], $sets['candidates'] ?? [])) !== null) {
                        $byWho[] = '(' . $in . ' AND NOT COALESCE(' . $real . ', 0))';
                    } else {
                        $where[] = $in;
                    }
                }
            }
            $where = array_merge($where, $byWho);
            if (! $where) {
                continue;
            }
            $pk = $info['pk'];
            foreach (rows('SELECT ' . implode(',', array_map(fn($c) => '`' . $c . '`', $pk)) . ' FROM `' . $table . '` WHERE ' . implode(' OR ', $where)) as $row) {
                $key = implode("\x1f", array_map('strval', array_values($row)));
                if (! isset($remove[$table][$key])) {
                    $remove[$table][$key] = $row;
                    $grew = true;
                    if (isset(DEMO_KEYS[$table]) && $pk === ['id']) {
                        $sets[$table][(int) $row['id']] = true;
                    }
                }
            }
        }
        if (! $grew) {
            break;
        }
    }
    // A demonstration account named by a row that stays is kept - retired by the flush, not removed - so the trail
    // still says who did it.
    foreach (demo_users_kept($remove) as $id) {
        unset($remove['users'][(string) $id]);
    }
    if (isset($remove['users']) && ! $remove['users']) {
        unset($remove['users']);
    }

    return $remove;
}

/**
 * P5-M109: the SQL condition "this row is about a real person" for a table with these columns - its candidate, or
 * the candidate of its application or placement, is not in the demonstration. Null when the table names nobody.
 */
function demo_names_real_person(array $columns, array $demoPeople): ?string
{
    $not = $demoPeople ? ' NOT IN (' . implode(',', array_map('intval', array_keys($demoPeople))) . ')' : ' IS NOT NULL';
    if (in_array('candidate_id', $columns, true)) {
        return '(`candidate_id` IS NOT NULL AND `candidate_id`' . $not . ')';
    }
    if (in_array('application_id', $columns, true)) {
        return '(`application_id` IS NOT NULL AND `application_id` IN (SELECT id FROM applications WHERE candidate_id' . $not . '))';
    }
    if (in_array('placement_id', $columns, true)) {
        return '(`placement_id` IS NOT NULL AND `placement_id` IN (SELECT id FROM placements WHERE candidate_id' . $not . '))';
    }

    return null;
}

/**
 * Demonstration accounts that wrote a row about a real person (user_id, and the row's candidate is not in the
 * demonstration): that row stays, so the account is retired, not removed. Other rows naming them (their sign-ins in
 * the append-only audit log...) do not keep an account.
 */
function demo_users_kept(array $plan): array
{
    $users = array_map('intval', array_column(rows("SELECT record_id FROM demo_records WHERE table_name = 'users'"), 'record_id'));
    if (! $users) {
        return [];
    }
    $people = array_flip(array_map('intval', array_column(rows("SELECT record_id FROM demo_records WHERE table_name = 'candidates'"), 'record_id')));
    $kept = [];
    foreach (demo_schema() as $table => $info) {
        if (in_array($table, ['users', 'demo_records'], true) || ! in_array('user_id', $info['columns'], true)
            || ($real = demo_names_real_person($info['columns'], $people)) === null) {
            continue;
        }
        foreach (rows('SELECT DISTINCT user_id FROM `' . $table . '` WHERE user_id IN (' . implode(',', $users) . ') AND COALESCE(' . $real . ', 0)') as $r) {
            $kept[(int) $r['user_id']] = true;
        }
    }

    return array_keys($kept);
}

/**
 * P5-M109: the real records a flush would take with it - rows of the plan that belong to a person who is NOT a
 * demonstration record (an application, a placement, a call, a document...). A flush is refused while there is one:
 * real requisitions raised on a demonstration project (RSS's live jobs on "Plant 1") were found that way.
 * Returns [table => rows], only the tables with some.
 */
function demo_real_at_risk(array $plan): array
{
    $schema = demo_schema();
    $demoPeople = array_map('intval', array_column(rows("SELECT record_id FROM demo_records WHERE table_name = 'candidates'"), 'record_id'));
    $out = [];
    foreach ($plan as $table => $planRows) {
        if (($schema[$table]['pk'] ?? []) !== ['id'] || ! $planRows) {
            continue;
        }
        $ids = implode(',', array_map(static fn($r) => (int) $r['id'], $planRows));
        $cols = $schema[$table]['columns'];
        if ($table === 'candidates') {
            $n = (int) val('SELECT COUNT(*) FROM candidates WHERE id IN (' . $ids . ')' . ($demoPeople ? ' AND id NOT IN (' . implode(',', $demoPeople) . ')' : ''));
        } elseif (in_array('candidate_id', $cols, true)) {
            $n = (int) val('SELECT COUNT(*) FROM `' . $table . '` WHERE id IN (' . $ids . ') AND candidate_id IS NOT NULL' . ($demoPeople ? ' AND candidate_id NOT IN (' . implode(',', $demoPeople) . ')' : ''));
        } elseif (in_array('application_id', $cols, true)) {
            $n = (int) val('SELECT COUNT(*) FROM `' . $table . '` t JOIN applications a ON a.id = t.application_id WHERE t.id IN (' . $ids . ')' . ($demoPeople ? ' AND a.candidate_id NOT IN (' . implode(',', $demoPeople) . ')' : ''));
        } elseif (in_array('placement_id', $cols, true)) {
            $n = (int) val('SELECT COUNT(*) FROM `' . $table . '` t JOIN placements p ON p.id = t.placement_id WHERE t.id IN (' . $ids . ')' . ($demoPeople ? ' AND p.candidate_id NOT IN (' . implode(',', $demoPeople) . ')' : ''));
        } else {
            continue;
        }
        if ($n) {
            $out[$table] = $n;
        }
    }

    return $out;
}

/** Removes what the plan found, in one transaction, then the registry. Returns rows removed per table. */
function demo_flush(array $plan): array
{
    $schema = demo_schema();
    $done = [];
    db()->beginTransaction();
    try {
        db()->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($plan as $table => $rows) {
            $pk = $schema[$table]['pk'];
            $n = 0;
            foreach (array_chunk(array_values($rows), 500) as $chunk) {
                if (count($pk) === 1) {
                    $n += q('DELETE FROM `' . $table . '` WHERE `' . $pk[0] . '` IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', array_map(fn($r) => $r[$pk[0]], $chunk))->rowCount();
                } else {
                    foreach ($chunk as $r) {
                        $n += q('DELETE FROM `' . $table . '` WHERE ' . implode(' AND ', array_map(fn($c) => '`' . $c . '` = ?', $pk)), array_map(fn($c) => $r[$c], $pk))->rowCount();
                    }
                }
            }
            $done[$table] = $n;
        }
        // The accounts the kept rows name: they can no longer sign in or receive mail; their name stays readable.
        foreach (demo_users_kept($plan) as $id) {
            q("UPDATE users SET name = CONCAT(LEFT(name, 95), ' (demo, retired)'), email = CONCAT('retired-demo-', id, '@retired.invalid'),
               password_hash = ?, is_active = 0 WHERE id = ?", [password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), $id]);
            $done['users retired'] = ($done['users retired'] ?? 0) + 1;
        }
        q('DELETE FROM demo_records');
        db()->exec('SET FOREIGN_KEY_CHECKS = 1');
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        db()->exec('SET FOREIGN_KEY_CHECKS = 1');
        throw $e;
    }

    return $done;
}
