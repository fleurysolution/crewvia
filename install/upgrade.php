<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit('CLI only');
require_once __DIR__.'/../app/bootstrap.php';
q("ALTER TABLE users MODIFY role ENUM('admin','recruiter','hotels','payroll','worker','supervisor','client') NOT NULL DEFAULT 'recruiter'");
if (val("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='placements' AND index_name='uq_candidate_job'")) {
 q('ALTER TABLE placements DROP INDEX uq_candidate_job, ADD INDEX ix_candidate_job(candidate_id,job_id)');
}
// ── names brought into line with the rest of the schema ────────────────
// Crewvia groups a table under the entity it belongs to: candidate_*,
// screening_*, onboarding_*, assignment_*. Two tables added this week
// sat outside that and are renamed into the assignment_* family.
//
// A rename, not a new table: site_checkins is live with roll-call rows
// in it, and creating the new name beside it would orphan every mark
// somebody has made.
foreach ([
    'site_checkins'   => 'assignment_checkins',
    'review_criteria' => 'assignment_review_criteria',
] as $was => $now) {
    $hasOld = (int) val("SELECT COUNT(*) FROM information_schema.tables
                         WHERE table_schema=DATABASE() AND table_name=?", [$was]);
    $hasNew = (int) val("SELECT COUNT(*) FROM information_schema.tables
                         WHERE table_schema=DATABASE() AND table_name=?", [$now]);

    if ($hasOld && ! $hasNew) {
        q('RENAME TABLE `' . $was . '` TO `' . $now . '`');
        echo 'Renamed ' . $was . ' to ' . $now . ', with its rows.' . PHP_EOL;
    } elseif ($hasOld && $hasNew) {
        // Both present means a half-finished rename. Say so rather than
        // guessing which one the application should be reading.
        fwrite(STDERR, 'Both ' . $was . ' and ' . $now . ' exist. '
                     . 'Merge them by hand before running this again.' . PHP_EOL);
        exit(1);
    }
}

$sql=file_get_contents(__DIR__.'/extension.sql');
foreach(explode(';',$sql) as $stmt) if(trim($stmt)) db()->exec($stmt);
q('ALTER TABLE vehicle_assignments MODIFY checked_out_at DATETIME NULL DEFAULT NULL');
$sql=file_get_contents(__DIR__.'/saas.sql');
foreach(explode(';',$sql) as $stmt) if(trim($stmt)) db()->exec($stmt);
foreach(['workflow.sql','recruiting-integrations.sql','client-portal.sql','completion.sql','contracts.sql'] as $migration) {
 $sql=file_get_contents(__DIR__.'/'.$migration);foreach(explode(';',$sql) as $stmt) if(trim($stmt)) db()->exec($stmt);
}
// Per-account language. Guarded rather than a plain ALTER, because this
// script must survive being run twice and ADD COLUMN is not idempotent.
// NULL means "never chose" and follows the browser; a stored 'en' means
// the person chose English and keeps it on a French browser.
if (!val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='locale'")) {
 q("ALTER TABLE users ADD COLUMN locale CHAR(2) NULL AFTER role");
}

// Indexed identity comparisons for large applicant pools; identity still needs human review.
foreach(['normalized_email'=>"LOWER(TRIM(email))",'normalized_name'=>"LOWER(TRIM(full_name))",'normalized_phone'=>"REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(phone),' ',''),'-',''),'(',''),')',''),'+','')"] as $column=>$expression) {
 if(!val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='candidates' AND column_name=?",[$column]))q("ALTER TABLE candidates ADD COLUMN ".$column." VARCHAR(190) GENERATED ALWAYS AS (".$expression.") STORED, ADD INDEX ix_".$column." (".$column.")");
}
// A requisition describes a job. It used to carry a title and a paragraph,
// which meant the discipline, the headcount, the shift and the start date
// were either buried in prose or absent - and a recruiter had nothing
// structured to screen against. Each column is added only if missing.
foreach ([
    'discipline'   => "ENUM('mechanical','chemical','electrical','instrumentation','operator','other') NOT NULL DEFAULT 'other'",
    'openings'     => 'SMALLINT UNSIGNED NOT NULL DEFAULT 1',
    'shift'        => "VARCHAR(60) NULL",
    'requirements' => 'TEXT NULL',
    'degree'       => 'VARCHAR(190) NULL',
    'years_experience' => 'TINYINT UNSIGNED NULL',
    'starts_on'    => 'DATE NULL',
] as $column => $definition) {
 if (!val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='vacancies' AND column_name='".$column."'")) {
  q('ALTER TABLE vacancies ADD COLUMN `'.$column.'` '.$definition);
 }
}

// One collation, or joins fail.
//
// Tables written as DEFAULT CHARSET=utf8mb4 resolve to utf8mb4_general_ci;
// tables that name no charset inherit the database's. Where an install
// ended up with both, any text join across the two raises "Illegal mix of
// collations" and the statement fails outright - which is what stopped
// contracts from ever being issued.
//
// Everything converges on utf8mb4_general_ci: the collation the large
// majority of tables already use, and the one that equates fewer strings,
// so converting a UNIQUE column cannot collapse two rows that were
// legitimately distinct.
$target = 'utf8mb4_general_ci';

$wrong = rows("SELECT table_name FROM information_schema.tables
               WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
                 AND table_collation IS NOT NULL AND table_collation <> ?",
              [$target]);

if ($wrong) {
    // Foreign keys reference columns being rewritten, so the constraint
    // check is suspended for the conversion and restored immediately -
    // the data itself is untouched, only how it is compared.
    q('SET FOREIGN_KEY_CHECKS = 0');

    foreach ($wrong as $t) {
        $name = (string) $t['table_name'];

        if (! preg_match('/^[A-Za-z0-9_]+$/D', $name)) { continue; }

        q('ALTER TABLE `' . $name . '` CONVERT TO CHARACTER SET utf8mb4 COLLATE ' . $target);
    }

    q('SET FOREIGN_KEY_CHECKS = 1');
    echo 'Aligned ' . count($wrong) . " table(s) to " . $target . "." . PHP_EOL;
}

// Approval chains, ported from BPMS247.
//
// Read explicitly rather than assumed: file_get_contents returns false
// when a deployment did not carry the file, and false quietly becomes an
// empty statement list - so the upgrade would print success having
// created nothing at all.
$approvalSql = __DIR__ . '/approvals.sql';

if (! is_file($approvalSql)) {
    fwrite(STDERR, "approvals.sql is missing from this deployment." . PHP_EOL);
    fwrite(STDERR, "Nothing was changed. Extract the archive again." . PHP_EOL);
    exit(1);
}

foreach (preg_split('/;\s*\n/', (string) file_get_contents($approvalSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

foreach (['approval_chains', 'approval_chain_steps', 'approval_requests'] as $table) {
    if (! val("SELECT COUNT(*) FROM information_schema.tables
               WHERE table_schema = DATABASE() AND table_name = ?", [$table])) {
        fwrite(STDERR, 'Expected table ' . $table . ' was not created.' . PHP_EOL);
        exit(1);
    }
}

// A project earns its stage. The old three-value status stays in step,
// because the switcher and several screens still read it.
if (! val("SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema=DATABASE() AND table_name='jobs'
             AND column_name='lifecycle_stage'")) {
    q("ALTER TABLE jobs ADD COLUMN lifecycle_stage
       ENUM('planning','mobilising','on_site','demobilising','closed')
       NOT NULL DEFAULT 'planning'");
    q("UPDATE jobs SET lifecycle_stage = CASE status
         WHEN 'active' THEN 'on_site' WHEN 'closed' THEN 'closed'
         ELSE 'planning' END");
    echo 'Projects: lifecycle stage added.' . PHP_EOL;
}

// A requisition can ask for an electrician, an instrument tech or an
// operator, and a candidate could only ever be recorded as mechanical,
// chemical or other - so the discipline a job asked for could not be the
// discipline anybody was filed under, and matching them was guesswork.
// The two lists are made the same. Nothing is reclassified: whatever a
// candidate is today, they stay.
//
// Guarded on the column still being an ENUM. Once the trade catalogue has
// turned it into plain text this must not run again: it would narrow the
// column back to six values, and a value outside an ENUM is emptied here
// rather than refused - erasing the trade of anybody filed under one of
// the trades the agency added itself.
$candidateDiscipline = (string) val("SELECT column_type FROM information_schema.columns
                                     WHERE table_schema=DATABASE() AND table_name='candidates'
                                       AND column_name='discipline'");

if (str_starts_with(strtolower($candidateDiscipline), 'enum')
    && ! str_contains($candidateDiscipline, 'instrumentation')) {
    q("ALTER TABLE candidates MODIFY discipline
       ENUM('mechanical','chemical','electrical','instrumentation','operator','other')
       NOT NULL DEFAULT 'other'");
    echo 'Candidates: discipline now matches what a requisition can ask for.' . PHP_EOL;
}

// A requisition is a role, and a role has its own money: the client
// agrees mechanical engineers at one rate and operators at another.
// NULL means "use the project's agreed default", so an installation that
// never sets these behaves exactly as it did.
foreach ([
    'pay_rate'        => 'DECIMAL(10,2) NULL',
    'bill_rate'       => 'DECIMAL(10,2) NULL',
    'per_diem_rate'   => 'DECIMAL(8,2) NULL',
    'guarantee_hours' => 'SMALLINT UNSIGNED NULL',
] as $column => $definition) {
    if (! val("SELECT COUNT(*) FROM information_schema.columns
               WHERE table_schema=DATABASE() AND table_name='vacancies'
                 AND column_name=?", [$column])) {
        q('ALTER TABLE vacancies ADD COLUMN `' . $column . '` ' . $definition);
        $addedRequisitionMoney = true;
    }
}

if (! empty($addedRequisitionMoney)) {
    echo 'Requisitions: each role can now carry its own rates.' . PHP_EOL;
}

// A placement already had its own rates and the payroll engine already
// preferred them. What it never had was a guarantee of its own, so a
// person agreed a different guaranteed week could not be recorded.
if (! val("SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema=DATABASE() AND table_name='placements'
             AND column_name='guarantee_hours'")) {
    q('ALTER TABLE placements ADD COLUMN guarantee_hours SMALLINT UNSIGNED NULL');
    echo 'Placements: a person can be agreed their own guaranteed week.' . PHP_EOL;
}

// Screening questions become scored, typed and able to disqualify.
//
// A question belonged to a project and had only a required flag. It now
// belongs to a role as well, knows what kind of answer it wants, carries
// a weight toward a score, and can name the answer that disqualifies.
// Every column is nullable or defaulted, so questions already written
// keep working as plain required questions.
foreach ([
    'vacancy_id'      => 'INT UNSIGNED NULL',
    'answer_type'     => "ENUM('yes_no','text','number','choice') NOT NULL DEFAULT 'text'",
    'choices'         => 'VARCHAR(500) NULL',
    'weight'          => 'SMALLINT UNSIGNED NOT NULL DEFAULT 5',
    'knockout_answer' => 'VARCHAR(190) NULL',
    'sort_order'      => 'INT NOT NULL DEFAULT 0',
] as $column => $definition) {
    if (! val("SELECT COUNT(*) FROM information_schema.columns
               WHERE table_schema=DATABASE() AND table_name='screening_questions'
                 AND column_name=?", [$column])) {
        q('ALTER TABLE screening_questions ADD COLUMN `' . $column . '` ' . $definition);
        $screeningChanged = true;
    }
}

// The result of answering them, kept on the application so a board can
// sort on it without recomputing every row.
foreach ([
    'screening_score'  => 'DECIMAL(5,2) NULL',
    'screened_out'     => 'TINYINT(1) NOT NULL DEFAULT 0',
    'screened_out_why' => 'VARCHAR(255) NULL',
] as $column => $definition) {
    if (! val("SELECT COUNT(*) FROM information_schema.columns
               WHERE table_schema=DATABASE() AND table_name='applications'
                 AND column_name=?", [$column])) {
        q('ALTER TABLE applications ADD COLUMN `' . $column . '` ' . $definition);
        $screeningChanged = true;
    }
}

if (! empty($screeningChanged)) {
    echo 'Screening: questions are typed, weighted and can disqualify.' . PHP_EOL;
}

// An applicant answering their own questionnaire is not a member of staff.
// recorded_by means "which of us typed this in", and when the candidate
// answered it themselves the honest value is nobody - but the column
// refused NULL, so every public submission died on a constraint.
if ('NO' === (string) val("SELECT is_nullable FROM information_schema.columns
                           WHERE table_schema=DATABASE() AND table_name='screening_answers'
                             AND column_name='recorded_by'")) {
    q('ALTER TABLE screening_answers MODIFY recorded_by INT UNSIGNED NULL');
    echo 'Screening: an applicant can answer their own questionnaire.' . PHP_EOL;
}

// ── The scope of work ───────────────────────────────────────────────────
$scopeSql = __DIR__ . '/scope-of-work.sql';

if (! is_file($scopeSql)) {
    fwrite(STDERR, 'scope-of-work.sql is missing from this deployment.' . PHP_EOL);
    exit(1);
}

foreach (preg_split('/;\s*\n/', (string) file_get_contents($scopeSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

// An agreement says what it is for, and what it covers beyond the hourly
// rate. These were nowhere, so the one thing a client signs - the
// description of the work - could not be recorded at all.
foreach ([
    'description'        => 'TEXT NULL',
    'order_reference'    => 'VARCHAR(120) NULL',
    'lodging_provided'   => 'TINYINT(1) NOT NULL DEFAULT 1',
    'travel_provided'    => 'TINYINT(1) NOT NULL DEFAULT 1',
    'transport_provided' => 'TINYINT(1) NOT NULL DEFAULT 1',
] as $column => $definition) {
    if (! val("SELECT COUNT(*) FROM information_schema.columns
               WHERE table_schema=DATABASE() AND table_name='jobs' AND column_name=?",
              [$column])) {
        q('ALTER TABLE jobs ADD COLUMN `' . $column . '` ' . $definition);
        $agreementChanged = true;
    }
}

// A requisition is raised against a line of the order and takes its terms.
if (! val("SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema=DATABASE() AND table_name='vacancies'
             AND column_name='order_line_id'")) {
    q('ALTER TABLE vacancies ADD COLUMN order_line_id INT UNSIGNED NULL');
    $agreementChanged = true;
}

// Whatever a project already had becomes its first line, so no rate that
// somebody agreed is thrown away by this change.
foreach (rows('SELECT * FROM jobs j
               WHERE NOT EXISTS (SELECT 1 FROM job_order_lines l WHERE l.job_id = j.id)')
         as $job) {
    q('INSERT INTO job_order_lines
         (job_id, role_title, discipline, quantity, pay_rate, bill_rate,
          per_diem_rate, guarantee_hours, strike_guarantee_hours, sort_order, notes)
       VALUES (?,?,?,?,?,?,?,?,?,1,?)',
      [(int) $job['id'],
       'Crew',
       'other',
       max(1, (int) $job['headcount_target']),
       $job['pay_rate'], $job['bill_rate'], $job['per_diem_rate'],
       $job['guarantee_hours'], $job['strike_hours'],
       'Carried over from the single rate this project used to hold.']);

    $linesMade = true;
}

// Every requisition already raised is attached to its project's line, so
// the rate chain - line, then requisition, then the person - has something
// behind it for work already in flight. Only a project with exactly one
// line can be matched without guessing, which is precisely the project the
// migration above just created one line for.
$attached = 0;

foreach (rows('SELECT j.id job_id, MIN(l.id) line_id
               FROM jobs j
               JOIN job_order_lines l ON l.job_id = j.id
               GROUP BY j.id
               HAVING COUNT(l.id) = 1') as $only) {
    // Counted before the update rather than read back from ROW_COUNT(),
    // which the prepare in between is not guaranteed to leave alone.
    $attached += (int) val('SELECT COUNT(*) FROM vacancies
                            WHERE job_id = ? AND order_line_id IS NULL',
                           [(int) $only['job_id']]);

    q('UPDATE vacancies SET order_line_id = ?
       WHERE job_id = ? AND order_line_id IS NULL',
      [(int) $only['line_id'], (int) $only['job_id']]);
}

if ($attached > 0) {
    echo 'Requisitions: ' . $attached . ' attached to their line of the scope of work.' . PHP_EOL;
}

// A project's headcount is now the total of its order rather than a number
// typed separately, so any disagreement left over is settled in favour of
// the scope - the thing the client actually signed.
q('UPDATE jobs j SET headcount_target =
     (SELECT COALESCE(SUM(l.quantity), 0) FROM job_order_lines l WHERE l.job_id = j.id)
   WHERE EXISTS (SELECT 1 FROM job_order_lines l WHERE l.job_id = j.id)');

if (! empty($agreementChanged) || ! empty($linesMade)) {
    echo 'Projects: an agreement now has a description and a schedule of order lines.' . PHP_EOL;
}

// A recruiter reaches somebody by telephone or by email. The column
// only admitted telephone outcomes, and a value outside an ENUM is
// stored as an empty string rather than refused, so the contact would
// have been recorded as having no outcome at all.
$outcomes = (string) val("SELECT COLUMN_TYPE FROM information_schema.columns
                          WHERE table_schema=DATABASE() AND table_name='candidate_calls'
                            AND column_name='outcome'");

if ($outcomes !== '' && ! str_contains($outcomes, 'emailed')) {
    q("ALTER TABLE candidate_calls MODIFY outcome
         ENUM('reached','voicemail','no_answer','callback','emailed',
              'replied_email','not_interested','wrong_number') NOT NULL DEFAULT 'reached'");

    echo 'Candidates: a contact can now be an email as well as a call.' . PHP_EOL;
}

// A hotel holds a block of rooms for a job. Without it the board could say
// how many rooms had been filled and never how many were left, so the
// booking that overruns the block went through in silence.
$blockAdded = false;

foreach ([
    'rooms_held'   => 'SMALLINT UNSIGNED NULL',
    'block_starts' => 'DATE NULL',
    'block_ends'   => 'DATE NULL',
] as $column => $definition) {
    if (! val("SELECT COUNT(*) FROM information_schema.columns
               WHERE table_schema=DATABASE() AND table_name='hotels' AND column_name=?",
              [$column])) {
        q('ALTER TABLE hotels ADD COLUMN `' . $column . '` ' . $definition);
        $blockAdded = true;
    }
}

if ($blockAdded) {
    echo 'Hotels: a hotel can now hold a block of rooms, and the board counts them down.' . PHP_EOL;
}

// Presence on the site, which is not the same question as hours worked.
$checkinSql = __DIR__ . '/assignment-checkins.sql';

if (! is_file($checkinSql)) {
    fwrite(STDERR, 'assignment-checkins.sql is missing from this deployment.' . PHP_EOL);
    exit(1);
}

$hadCheckins = (int) val("SELECT COUNT(*) FROM information_schema.tables
                          WHERE table_schema=DATABASE() AND table_name='assignment_checkins'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($checkinSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

if (! $hadCheckins) {
    echo 'Deployment: the site roll call is in place.' . PHP_EOL;
}

// The trades this agency staffs: data, not an ENUM repeated in three
// tables. A client asking for welders should not need a schema change.
$tradeSql = __DIR__ . '/disciplines.sql';

if (! is_file($tradeSql)) {
    fwrite(STDERR, 'disciplines.sql is missing from this deployment.' . PHP_EOL);
    exit(1);
}

$hadTrades = (int) val("SELECT COUNT(*) FROM information_schema.tables
                        WHERE table_schema=DATABASE() AND table_name='disciplines'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($tradeSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

// Whatever is already filed under a trade keeps its meaning: any value in
// use that the catalogue does not list is added to it rather than lost.
foreach ([['candidates', 'discipline'], ['vacancies', 'discipline'],
          ['job_order_lines', 'discipline']] as [$table, $column]) {
    if (! val("SELECT COUNT(*) FROM information_schema.tables
               WHERE table_schema=DATABASE() AND table_name=?", [$table])) {
        continue;
    }

    foreach (rows('SELECT DISTINCT `' . $column . '` v FROM `' . $table . '`
                   WHERE `' . $column . '` IS NOT NULL') as $inUse) {
        $slug = (string) $inUse['v'];

        if ($slug === '') { continue; }

        q('INSERT IGNORE INTO disciplines (slug, label, sort_order) VALUES (?,?,50)',
          [$slug, ucfirst(str_replace('_', ' ', $slug))]);
    }

    // Plain text, so adding a trade never needs the table altered again.
    $type = (string) val("SELECT COLUMN_TYPE FROM information_schema.columns
                          WHERE table_schema=DATABASE() AND table_name=? AND column_name=?",
                         [$table, $column]);

    if (str_starts_with(strtolower($type), 'enum')) {
        q('ALTER TABLE `' . $table . '` MODIFY `' . $column . "` VARCHAR(40) NOT NULL DEFAULT 'other'");
        $tradesFreed = true;
    }
}

if (! $hadTrades || ! empty($tradesFreed)) {
    echo 'Trades: the discipline list is now editable, and no longer frozen into the schema.' . PHP_EOL;
}

// Who must never be called again, and what people can actually do.
$registerSql = __DIR__ . '/do-not-use.sql';

if (! is_file($registerSql)) {
    fwrite(STDERR, 'do-not-use.sql is missing from this deployment.' . PHP_EOL);
    exit(1);
}

$hadSkills = (int) val("SELECT COUNT(*) FROM information_schema.tables
                        WHERE table_schema=DATABASE() AND table_name='skills'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($registerSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

// Why somebody is on the register, who put them there, and when. Without
// these the flag is an accusation nobody can check or argue with.
$registerChanged = false;

foreach ([
    'exclusion_reason' => 'VARCHAR(1000) NULL',
    'excluded_by'      => 'INT UNSIGNED NULL',
    'excluded_at'      => 'DATETIME NULL',
    'available_from'   => 'DATE NULL',
    'checked_in_at'    => 'DATETIME NULL',
] as $column => $definition) {
    if (! val("SELECT COUNT(*) FROM information_schema.columns
               WHERE table_schema=DATABASE() AND table_name='employee_profiles'
                 AND column_name=?", [$column])) {
        q('ALTER TABLE employee_profiles ADD COLUMN `' . $column . '` ' . $definition);
        $registerChanged = true;
    }
}

// Do not use is stronger than no rehire: no rehire means do not put them
// back on a job, do not use means do not contact them at all.
$rehireType = (string) val("SELECT COLUMN_TYPE FROM information_schema.columns
                            WHERE table_schema=DATABASE() AND table_name='employee_profiles'
                              AND column_name='rehire_status'");

if ($rehireType !== '' && ! str_contains($rehireType, 'do_not_use')) {
    q("ALTER TABLE employee_profiles MODIFY rehire_status
         ENUM('review','eligible','ineligible','do_not_use') NOT NULL DEFAULT 'review'");
    $registerChanged = true;
}

if (! $hadSkills || $registerChanged) {
    echo 'People: the do-not-use register and the skills list are in place.' . PHP_EOL;
}

// End-of-assignment reviews, and advances against wages. Both carried
// over from the Fleury Solutions HR system - the concepts, not its
// tables, which store dates and money as text with no foreign keys.
$hrSql = __DIR__ . '/hr-modules.sql';

if (! is_file($hrSql)) {
    fwrite(STDERR, 'hr-modules.sql is missing from this deployment.' . PHP_EOL);
    exit(1);
}

$hadReviews = (int) val("SELECT COUNT(*) FROM information_schema.tables
                         WHERE table_schema=DATABASE() AND table_name='assignment_reviews'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($hrSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

if (! $hadReviews) {
    echo 'People: end-of-assignment reviews and wage advances are in place.' . PHP_EOL;
}

// Where somebody's pay goes, and what kind of leave they took.
$hr2Sql = __DIR__ . '/hr-modules-2.sql';

if (! is_file($hr2Sql)) {
    fwrite(STDERR, 'hr-modules-2.sql is missing from this deployment.' . PHP_EOL);
    exit(1);
}

$hadBank = (int) val("SELECT COUNT(*) FROM information_schema.tables
                      WHERE table_schema=DATABASE() AND table_name='worker_bank_details'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($hr2Sql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

// A kind of leave, beside the free text that used to be the only record
// of it. The text stays: it is the label somebody typed, and things
// already read it.
if (! val("SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema=DATABASE() AND table_name='time_off_requests'
             AND column_name='leave_type'")) {
    q('ALTER TABLE time_off_requests ADD COLUMN leave_type VARCHAR(40) NULL AFTER request_type');

    // Only where the text leaves no doubt. A request typed "family" is
    // not obviously personal or bereavement, and guessing it wrong is
    // worse than leaving it unclassified.
    foreach ([
        'sick'        => ['sick', 'illness', 'ill', 'medical'],
        'unpaid'      => ['unpaid', 'leave without pay', 'lwop'],
        'bereavement' => ['bereavement', 'funeral'],
        'jury'        => ['jury'],
        'personal'    => ['personal'],
    ] as $slug => $words) {
        foreach ($words as $word) {
            q('UPDATE time_off_requests SET leave_type = ?
               WHERE leave_type IS NULL AND LOWER(request_type) LIKE ?',
              [$slug, '%' . $word . '%']);
        }
    }

    $classified = (int) val('SELECT COUNT(*) FROM time_off_requests WHERE leave_type IS NOT NULL');
    $total = (int) val('SELECT COUNT(*) FROM time_off_requests');

    if ($total > 0) {
        echo 'Time off: ' . $classified . ' of ' . $total
           . ' past requests matched to a leave type; the rest keep their text.' . PHP_EOL;
    }

    $bank2 = true;
}

if (! $hadBank || ! empty($bank2)) {
    echo 'People: bank details and leave types are in place.' . PHP_EOL;
}

echo 'Approvals: 3 tables in place.' . PHP_EOL;

// An approval step is announced once; before this column every later
// decision re-announced every step still open.
if (val("SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_name='approval_requests'")
    && ! val("SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='approval_requests'
                AND column_name='notified_at'")) {
    q('ALTER TABLE approval_requests ADD COLUMN notified_at DATETIME NULL');
}

// ── P1-M01: classification history and where an assignment came from ──
// Reversed by install/rollback/p1-m01.sql.
$relationshipsSql = __DIR__ . '/hr-relationships.sql';

if (! is_file($relationshipsSql)) {
    fwrite(STDERR, "Missing install/hr-relationships.sql\n");
    exit(1);
}

$hadClassifications = (int) val("SELECT COUNT(*) FROM information_schema.tables
                                 WHERE table_schema=DATABASE() AND table_name='employee_classifications'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($relationshipsSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

if (! val("SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema=DATABASE() AND table_name='employee_profiles'
             AND column_name='flsa_status'")) {
    q("ALTER TABLE employee_profiles ADD COLUMN flsa_status
         ENUM('non_exempt','exempt','not_applicable','not_determined')
         NOT NULL DEFAULT 'not_determined' AFTER employment_type");
    echo 'People: overtime status added to employee profiles.' . PHP_EOL;
}

if (! $hadClassifications) {
    // One row per existing profile, saying only what is known: the type it
    // held, and no date, because nobody recorded since when.
    require_once __DIR__ . '/../app/classification.php';

    $profiles = rows('SELECT candidate_id, employment_type FROM employee_profiles');

    foreach ($profiles as $profile) {
        classification_record_initial((int) $profile['candidate_id'],
                                      (string) $profile['employment_type'],
                                      'Held before classification history was kept.');
    }

    $undecided = (int) val("SELECT COUNT(*) FROM employee_profiles WHERE flsa_status = 'not_determined'");

    echo 'People: classification history started for ' . count($profiles) . ' profile(s); '
       . $undecided . ' still need an overtime status decided.' . PHP_EOL;
}

$linkColumns = [
    'vacancy_id'    => 'INT UNSIGNED NULL AFTER job_id, ADD INDEX ix_placement_vacancy (vacancy_id)',
    'order_line_id' => 'INT UNSIGNED NULL AFTER vacancy_id, ADD INDEX ix_placement_order_line (order_line_id)',
];
$linksAdded = false;

foreach ($linkColumns as $column => $definition) {
    if (! val("SELECT COUNT(*) FROM information_schema.columns
               WHERE table_schema=DATABASE() AND table_name='placements' AND column_name=?", [$column])) {
        q('ALTER TABLE placements ADD COLUMN `' . $column . '` ' . $definition);
        $linksAdded = true;
    }
}

if ($linksAdded) {
    // Only where the answer is not a guess: the person applied to exactly
    // one requisition on that project. Two applications could be two
    // trades, and picking the latest is how the wrong rate gets quoted.
    $linked = q('UPDATE placements p
                 JOIN (SELECT a.candidate_id, v.job_id, MIN(v.id) AS vacancy_id
                       FROM applications a JOIN vacancies v ON v.id = a.vacancy_id
                       GROUP BY a.candidate_id, v.job_id
                       HAVING COUNT(DISTINCT v.id) = 1) one
                   ON one.candidate_id = p.candidate_id AND one.job_id = p.job_id
                 JOIN vacancies v ON v.id = one.vacancy_id
                 SET p.vacancy_id = v.id, p.order_line_id = v.order_line_id
                 WHERE p.vacancy_id IS NULL')->rowCount();

    $unlinked = (int) val('SELECT COUNT(*) FROM placements WHERE vacancy_id IS NULL');

    echo 'Assignments: ' . $linked . ' linked to the requisition they came from; '
       . $unlinked . ' left unlinked because it is not certain which.' . PHP_EOL;
}

// ── P1-M02: attendance corrections and staff-entered days ─────────────
// Reversed by install/rollback/p1-m02.sql.
$attendanceSql = __DIR__ . '/attendance-controls.sql';

if (! is_file($attendanceSql)) {
    fwrite(STDERR, "Missing install/attendance-controls.sql\n");
    exit(1);
}

$hadCorrections = (int) val("SELECT COUNT(*) FROM information_schema.tables
                             WHERE table_schema=DATABASE() AND table_name='attendance_corrections'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($attendanceSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

$attendanceColumns = [
    // Who put the day in: the worker from their portal, or a supervisor or
    // payroll on their behalf when they could not.
    'source' => "ENUM('worker','staff') NOT NULL DEFAULT 'worker' AFTER hours",
    // Required for a staff-entered day: why the worker did not enter it.
    'note'   => 'VARCHAR(500) NULL AFTER source',
];
$attendanceChanged = ! $hadCorrections;

foreach ($attendanceColumns as $column => $definition) {
    if (! val("SELECT COUNT(*) FROM information_schema.columns
               WHERE table_schema=DATABASE() AND table_name='attendance_records' AND column_name=?", [$column])) {
        q('ALTER TABLE attendance_records ADD COLUMN `' . $column . '` ' . $definition);
        $attendanceChanged = true;
    }
}

if ($attendanceChanged) {
    echo 'Attendance: corrections and staff-entered days are in place; '
       . (int) val('SELECT COUNT(*) FROM attendance_records') . ' existing day(s) kept as entered by the worker.' . PHP_EOL;
}

// ── P1-M03: pay rules ──────────────────────────────────────────────────
// Reversed by install/rollback/p1-m03.sql.
$payRulesSql = __DIR__ . '/pay-rules.sql';

if (! is_file($payRulesSql)) {
    fwrite(STDERR, "Missing install/pay-rules.sql\n");
    exit(1);
}

$hadPayRules = (int) val("SELECT COUNT(*) FROM information_schema.tables
                          WHERE table_schema=DATABASE() AND table_name='pay_rule_sets'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($payRulesSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

if (! val("SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema=DATABASE() AND table_name='jobs' AND column_name='pay_rule_set_id'")) {
    // No default: a project uses pay rules only once somebody chooses a
    // confirmed set for it. Until then it is paid exactly as before.
    q('ALTER TABLE jobs ADD COLUMN pay_rule_set_id INT UNSIGNED NULL, ADD INDEX ix_job_pay_rule_set (pay_rule_set_id)');
    echo 'Pay rules: projects can now be given a confirmed pay rule set; none is assigned.' . PHP_EOL;
}

if (! $hadPayRules) {
    // A starting point, not a ruling: the federal weekly line, as a draft
    // that cannot be used until a person confirms it for a jurisdiction.
    q("INSERT INTO pay_rule_sets (name, jurisdiction, weekly_overtime_after, weekly_overtime_multiplier, notes, status)
       VALUES ('US federal baseline', 'United States (federal)', 40, 1.50,
               'Federal weekly overtime only. Many states add daily overtime, double time or a seventh-day rule. Confirm with the payroll provider before activating, and copy it for each state that differs.',
               'draft')");
    echo 'Pay rules: a draft federal baseline was added; it is not active until confirmed.' . PHP_EOL;
}

// ── P1-M04: leave accrual, carryover, eligibility, paid leave on the sheet ─
// Reversed by install/rollback/p1-m04.sql. Every default reproduces what a
// leave type did before: a yearly allowance, no carryover, no waiting
// period, open to every kind of employment.
$leaveColumns = [
    'accrual_method'            => "ENUM('annual','hours_worked') NOT NULL DEFAULT 'annual' AFTER days_allowed",
    'accrual_hours_per_day'     => 'DECIMAL(6,2) NULL AFTER accrual_method',
    'accrual_cap_days'          => 'SMALLINT UNSIGNED NULL AFTER accrual_hours_per_day',
    'carryover_max_days'        => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER accrual_cap_days',
    'eligible_after_days'       => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER carryover_max_days',
    'eligible_employment_types' => 'VARCHAR(100) NULL AFTER eligible_after_days',
    'hours_per_day'             => 'DECIMAL(4,2) NOT NULL DEFAULT 8.00 AFTER is_paid',
];
$leaveAdded = 0;

foreach ($leaveColumns as $column => $definition) {
    if (! val("SELECT COUNT(*) FROM information_schema.columns
               WHERE table_schema=DATABASE() AND table_name='leave_types' AND column_name=?", [$column])) {
        q('ALTER TABLE leave_types ADD COLUMN `' . $column . '` ' . $definition);
        $leaveAdded++;
    }
}

if (! val("SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema=DATABASE() AND table_name='timesheets' AND column_name='paid_leave_hours'")) {
    // Paid leave is paid, but it is not time worked: it never counts
    // toward overtime, so it is kept apart from hours_worked.
    q('ALTER TABLE timesheets ADD COLUMN paid_leave_hours DECIMAL(6,2) NOT NULL DEFAULT 0 AFTER hours_worked');
    $leaveAdded++;
}

if ($leaveAdded > 0) {
    echo 'Leave: accrual, carryover, eligibility and paid leave on the weekly sheet are in place; '
       . (int) val('SELECT COUNT(*) FROM leave_types') . ' existing leave type(s) keep their yearly allowance.' . PHP_EOL;
}

// ── P1-M05: deductions, employer contributions, gross to net ──────────
// Reversed by install/rollback/p1-m05.sql.
$grossToNetSql = __DIR__ . '/gross-to-net.sql';

if (! is_file($grossToNetSql)) {
    fwrite(STDERR, "Missing install/gross-to-net.sql\n");
    exit(1);
}

$hadPayItems = (int) val("SELECT COUNT(*) FROM information_schema.tables
                          WHERE table_schema=DATABASE() AND table_name='pay_items'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($grossToNetSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

if (! val("SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema=DATABASE() AND table_name='wage_advance_payments' AND column_name='timesheet_id'")) {
    // Which weekly sheet a repayment was taken from, so a week can never
    // take the same advance twice.
    q('ALTER TABLE wage_advance_payments ADD COLUMN timesheet_id INT UNSIGNED NULL AFTER advance_id,
       ADD UNIQUE KEY uq_advance_sheet (advance_id, timesheet_id)');
}

if (! $hadPayItems) {
    // The one item every installation has: open advances are repaid out
    // of the weeks that follow, at the repayment agreed for each.
    q("INSERT INTO pay_items (code, label, side, method, pre_tax, sort_order)
       VALUES ('advance_repayment', 'Advance repayment', 'deduction', 'advance_repayment', 0, 100)");
    echo 'Pay: deductions and employer contributions are in place; open advances are now repaid from approved weeks.' . PHP_EOL;
}

// ── P1-M06: pay periods, adjustments, payroll audit ───────────────────
// Reversed by install/rollback/p1-m06.sql. No period is created here: a
// week without one behaves exactly as before, so nothing already being
// worked on is frozen by the upgrade.
$payPeriodsSql = __DIR__ . '/pay-periods.sql';

if (! is_file($payPeriodsSql)) {
    fwrite(STDERR, "Missing install/pay-periods.sql\n");
    exit(1);
}

$hadPayPeriods = (int) val("SELECT COUNT(*) FROM information_schema.tables
                            WHERE table_schema=DATABASE() AND table_name='payroll_runs'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($payPeriodsSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

if (! $hadPayPeriods) {
    echo 'Pay periods: approval, locking, adjustments and payslips are in place; no period is open yet.' . PHP_EOL;
}

// ── Procurement (R41-R47) ─────────────────────────────────────────────
// Reversed by install/rollback/procurement.sql.
$procurementSql = __DIR__ . '/procurement.sql';

if (! is_file($procurementSql)) {
    fwrite(STDERR, "Missing install/procurement.sql\n");
    exit(1);
}

$hadProcurement = (int) val("SELECT COUNT(*) FROM information_schema.tables
                             WHERE table_schema=DATABASE() AND table_name='purchase_orders'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($procurementSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

foreach ([
    // Who approves the project's purchase orders (R44). Unset, an
    // administrator does.
    'budget_owner_id' => 'INT UNSIGNED NULL',
    // Raise a lodging request for every new hire on the project (R42).
    // Off until somebody turns it on: not every job puts people in hotels.
    'auto_lodging'    => 'TINYINT(1) NOT NULL DEFAULT 0',
] as $column => $definition) {
    if (! val("SELECT COUNT(*) FROM information_schema.columns
               WHERE table_schema=DATABASE() AND table_name='jobs' AND column_name=?", [$column])) {
        q('ALTER TABLE jobs ADD COLUMN `' . $column . '` ' . $definition);
    }
}

if (! val("SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema=DATABASE() AND table_name='vendor_invoices' AND column_name='purchase_order_id'")) {
    // The order a bill is for, so what is still owed on it is known (R46).
    q('ALTER TABLE vendor_invoices ADD COLUMN purchase_order_id INT UNSIGNED NULL,
       ADD CONSTRAINT fk_invoice_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id)');
}

if (! $hadProcurement) {
    foreach ([['room', 'Room'], ['room_night', 'Room-night'], ['van_day', 'Van-day'], ['pair', 'Pair'], ['each', 'Each']] as [$code, $label]) {
        q('INSERT IGNORE INTO procurement_units (code, label) VALUES (?,?)', [$code, $label]);
    }
    echo 'Procurement: requests, purchase orders, receipts and commitments are in place; '
       . 'projects raise lodging requests for new hires only once turned on.' . PHP_EOL;
}

echo "Upgrade complete. Existing records preserved.\n";
