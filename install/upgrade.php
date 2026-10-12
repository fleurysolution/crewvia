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

// ── HR self-service (R22-R24) ─────────────────────────────────────────
// Reversed by install/rollback/self-service.sql.
$selfServiceSql = __DIR__ . '/self-service.sql';

if (! is_file($selfServiceSql)) {
    fwrite(STDERR, "Missing install/self-service.sql\n");
    exit(1);
}

$hadSelfService = (int) val("SELECT COUNT(*) FROM information_schema.tables
                             WHERE table_schema=DATABASE() AND table_name='profile_change_requests'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($selfServiceSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

if (! $hadSelfService) {
    echo 'Self-service: workers can propose their bank details and changes to their details, for staff to validate.' . PHP_EOL;
}

// ── P2-M01: grades, pay bands, effective-dated pay changes ────────────
// Reversed by install/rollback/p2-m01.sql.
$compensationSql = __DIR__ . '/compensation.sql';

if (! is_file($compensationSql)) {
    fwrite(STDERR, "Missing install/compensation.sql\n");
    exit(1);
}

$hadGrades = (int) val("SELECT COUNT(*) FROM information_schema.tables
                        WHERE table_schema=DATABASE() AND table_name='pay_grades'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($compensationSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

if (! val("SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema=DATABASE() AND table_name='employee_profiles' AND column_name='grade_id'")) {
    // The grade in effect today; compensation_changes holds how it got there.
    q('ALTER TABLE employee_profiles ADD COLUMN grade_id INT UNSIGNED NULL');
}

if (! $hadGrades) {
    echo 'Compensation: grades with pay bands and dated pay changes are in place; nobody has a grade yet.' . PHP_EOL;
}

// ── Assets: categories, lifecycle, condition, repairs, inspections ────
// Reversed by install/rollback/assets.sql.
$assetsSql = __DIR__ . '/assets.sql';

if (! is_file($assetsSql)) {
    fwrite(STDERR, "Missing install/assets.sql\n");
    exit(1);
}

$hadAssets = (int) val("SELECT COUNT(*) FROM information_schema.tables
                        WHERE table_schema=DATABASE() AND table_name='asset_categories'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($assetsSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

foreach (['equipment' => [
              'category_id'       => 'INT UNSIGNED NULL',
              // Issued is not stored: it is an open line in equipment_issues.
              'status'            => "ENUM('available','in_repair','lost','retired') NOT NULL DEFAULT 'available'",
              'purchase_date'     => 'DATE NULL',
              'purchase_cost'     => 'DECIMAL(10,2) NULL',
              'purchase_order_id' => 'INT UNSIGNED NULL',
              'inspection_due'    => 'DATE NULL',
              'notes'             => 'VARCHAR(500) NULL',
          ],
          'equipment_issues' => [
              'issue_condition'  => "ENUM('good','worn','damaged') NULL",
              'return_condition' => "ENUM('good','worn','damaged','lost') NULL",
              'issued_by'        => 'INT UNSIGNED NULL',
              'returned_by'      => 'INT UNSIGNED NULL',
          ]] as $table => $columns) {
    foreach ($columns as $column => $definition) {
        if (! val("SELECT COUNT(*) FROM information_schema.columns
                   WHERE table_schema=DATABASE() AND table_name=? AND column_name=?", [$table, $column])) {
            q('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
        }
    }
}

if (! $hadAssets) {
    foreach ([['ppe', 'Protective equipment', 365], ['fall_protection', 'Fall protection', 180], ['gas_detection', 'Gas detection', 30],
              ['tools', 'Tools', null], ['radios', 'Radios and electronics', null], ['other', 'Other', null]] as [$code, $label, $days]) {
        q('INSERT IGNORE INTO asset_categories (code, label, inspection_days) VALUES (?,?,?)', [$code, $label, $days]);
    }
    echo 'Assets: categories, status, condition, repairs and inspections are in place; '
       . (int) val('SELECT COUNT(*) FROM equipment') . ' existing item(s) start as available, uncategorised.' . PHP_EOL;
}

// ── P2-M02: performance appraisals ───────────────────────────────────────
// Reversed by install/rollback/p2-m02.sql.
$appraisalsSql = __DIR__ . '/appraisals.sql';

if (! is_file($appraisalsSql)) {
    fwrite(STDERR, "Missing install/appraisals.sql\n");
    exit(1);
}

$hadAppraisals = (int) val("SELECT COUNT(*) FROM information_schema.tables
                            WHERE table_schema=DATABASE() AND table_name='appraisal_templates'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($appraisalsSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

if (! $hadAppraisals) {
    // The end-of-assignment review as a template: the same criteria, on the
    // same 1-5 scale, safety counting double.
    q("INSERT IGNORE INTO appraisal_templates (code, label, kind, scale_max, self_review) VALUES ('end_of_assignment', 'End of assignment', 'end_of_assignment', 5, 1)");
    $template = (int) val("SELECT id FROM appraisal_templates WHERE code = 'end_of_assignment'");
    foreach (rows('SELECT slug, sort_order FROM assignment_review_criteria WHERE is_active = 1 ORDER BY sort_order') as $c) {
        q('INSERT IGNORE INTO appraisal_template_criteria (template_id, criterion_slug, weight, sort_order) VALUES (?,?,?,?)',
          [$template, $c['slug'], $c['slug'] === 'safety' ? 2 : 1, (int) $c['sort_order']]);
    }
    echo 'Appraisals: templates, self and supervisor scoring, approval and history are in place; the end-of-assignment template scores '
       . (int) val('SELECT COUNT(*) FROM appraisal_template_criteria WHERE template_id = ?', [$template]) . ' criteria.' . PHP_EOL;
}

// ── P3-M01: project costing, budgets ──────────────────────────────────────
// Reversed by install/rollback/p3-m01.sql.
$costingSql = __DIR__ . '/project-costing.sql';

if (! is_file($costingSql)) {
    fwrite(STDERR, "Missing install/project-costing.sql\n");
    exit(1);
}

$hadCosting = (int) val("SELECT COUNT(*) FROM information_schema.tables
                         WHERE table_schema=DATABASE() AND table_name='project_budget_changes'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($costingSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

foreach ([
    // Employer taxes and insurance ADP charges on top of wages, as a
    // percentage. Unset, the report shows no estimate and says so.
    'burden_percent'   => 'DECIMAL(5,2) NULL',
    // The share of revenue the project carries for the agency's overhead.
    'overhead_percent' => 'DECIMAL(5,2) NULL',
] as $column => $definition) {
    if (! val("SELECT COUNT(*) FROM information_schema.columns
               WHERE table_schema=DATABASE() AND table_name='jobs' AND column_name=?", [$column])) {
        q('ALTER TABLE jobs ADD COLUMN `' . $column . '` ' . $definition);
    }
}

if (! $hadCosting) {
    echo 'Project costing: budgets with their history, burden and overhead rates are in place; '
       . (int) val('SELECT COUNT(*) FROM jobs') . ' project(s) start with no budget and no rates.' . PHP_EOL;
}

// ── P3-M02: chart-of-accounts mapping, QuickBooks export ─────────────────
// Reversed by install/rollback/p3-m02.sql.
$accountingSql = __DIR__ . '/accounting.sql';

if (! is_file($accountingSql)) {
    fwrite(STDERR, "Missing install/accounting.sql\n");
    exit(1);
}

$hadAccounting = (int) val("SELECT COUNT(*) FROM information_schema.tables
                            WHERE table_schema=DATABASE() AND table_name='accounting_accounts'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($accountingSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

// When an invoice went out and when it was paid. Unknown for invoices
// issued before this: the export then uses the date it was created.
foreach (['issued_at' => 'DATETIME NULL', 'paid_at' => 'DATETIME NULL'] as $column => $definition) {
    if (! val("SELECT COUNT(*) FROM information_schema.columns
               WHERE table_schema=DATABASE() AND table_name='client_invoices' AND column_name=?", [$column])) {
        q('ALTER TABLE client_invoices ADD COLUMN `' . $column . '` ' . $definition);
    }
}

if (! $hadAccounting) {
    // Usual QuickBooks Online names, unconfirmed: an administrator checks
    // each against the company's chart before anything is exported.
    $order = 0;
    foreach ([
        ['accounts_receivable', 'Client invoices owed', 'asset', 'Accounts Receivable (A/R)'],
        ['bank', 'Bank account payments go through', 'asset', 'Checking'],
        ['accounts_payable', 'Vendor bills owed', 'liability', 'Accounts Payable (A/P)'],
        ['net_pay_payable', 'Net pay owed to workers', 'liability', 'Payroll Clearing'],
        ['deductions_payable', 'Deductions withheld from pay', 'liability', 'Payroll Liabilities'],
        ['employer_payable', 'Employer contributions owed', 'liability', 'Payroll Liabilities'],
        ['revenue', 'Staffing revenue', 'income', 'Services'],
        ['wages_expense', 'Wages', 'expense', 'Cost of Labor'],
        ['employer_expense', 'Employer contributions', 'expense', 'Payroll Expenses:Taxes'],
        ['per_diem_expense', 'Per diem and reimbursed expenses', 'expense', 'Travel Meals'],
        ['hotels_expense', 'Hotels', 'expense', 'Travel'],
        ['transportation_expense', 'Transportation', 'expense', 'Travel'],
        ['equipment_expense', 'Equipment', 'expense', 'Supplies & Materials'],
        ['other_expense', 'Other costs', 'expense', 'Other Business Expenses'],
    ] as [$key, $label, $side, $qb]) {
        q('INSERT IGNORE INTO accounting_accounts (account_key, label, side, qb_account, sort_order) VALUES (?,?,?,?,?)', [$key, $label, $side, $qb, ++$order]);
    }
    echo 'Accounting: chart-of-accounts mapping and QuickBooks export are in place; '
       . (int) val('SELECT COUNT(*) FROM accounting_accounts') . ' accounts await confirmation, payroll export is off.' . PHP_EOL;
}

// ── P3-M04: receivables and payables ─────────────────────────────────────
// Reversed by install/rollback/p3-m04.sql.
$balancesSql = __DIR__ . '/balances.sql';

if (! is_file($balancesSql)) {
    fwrite(STDERR, "Missing install/balances.sql\n");
    exit(1);
}

$hadBalances = (int) val("SELECT COUNT(*) FROM information_schema.tables
                          WHERE table_schema=DATABASE() AND table_name='ar_payments'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($balancesSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

if (! val("SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema=DATABASE() AND table_name='clients' AND column_name='payment_terms_days'")) {
    // Days a client has to pay an invoice. 30 unless agreed otherwise.
    q('ALTER TABLE clients ADD COLUMN payment_terms_days SMALLINT UNSIGNED NOT NULL DEFAULT 30');
}

$dueAdded = false;
if (! val("SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema=DATABASE() AND table_name='client_invoices' AND column_name='due_on'")) {
    q('ALTER TABLE client_invoices ADD COLUMN due_on DATE NULL');
    $dueAdded = true;
}

if (! $hadBalances) {
    // Invoices already out are due 30 days after they went out, or after
    // they were created when that is not known.
    $dated = 0;
    if ($dueAdded) {
        $dated = (int) q("UPDATE client_invoices SET due_on = DATE_ADD(DATE(COALESCE(issued_at, created_at)), INTERVAL 30 DAY)
                          WHERE status IN ('issued','paid') AND due_on IS NULL")->rowCount();
    }
    echo 'Receivables and payables: payments, applications, credit notes and aging are in place; '
       . $dated . ' invoice(s) given a due date 30 days after issue; invoices already paid stay paid.' . PHP_EOL;
}

// ── P3-M05: financial periods ────────────────────────────────────────────
// Reversed by install/rollback/p3-m05.sql.
$periodsSql = __DIR__ . '/periods.sql';

if (! is_file($periodsSql)) {
    fwrite(STDERR, "Missing install/periods.sql\n");
    exit(1);
}

$hadPeriods = (int) val("SELECT COUNT(*) FROM information_schema.tables
                         WHERE table_schema=DATABASE() AND table_name='financial_periods'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($periodsSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

if (! $hadPeriods) {
    echo 'Financial periods: months can be closed against new entries and exports; every month starts open.' . PHP_EOL;
}

// ── P3-M06: approved vendors, thresholds, purchasing allocations ──────────
// Reversed by install/rollback/p3-m06.sql.
$vendorsSql = __DIR__ . '/vendors.sql';

if (! is_file($vendorsSql)) {
    fwrite(STDERR, "Missing install/vendors.sql\n");
    exit(1);
}

$hadVendors = (int) val("SELECT COUNT(*) FROM information_schema.tables
                         WHERE table_schema=DATABASE() AND table_name='vendors'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($vendorsSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

foreach ([
    // Who must approve, fixed when the order is raised.
    'approvers'   => "ENUM('budget_owner','admin','both') NULL",
    // The order took a project past a budget line when it was raised.
    'over_budget' => 'TINYINT(1) NOT NULL DEFAULT 0',
] as $column => $definition) {
    if (! val("SELECT COUNT(*) FROM information_schema.columns
               WHERE table_schema=DATABASE() AND table_name='purchase_orders' AND column_name=?", [$column])) {
        q('ALTER TABLE purchase_orders ADD COLUMN `' . $column . '` ' . $definition);
    }
}

if (! $hadVendors) {
    // Vendors already ordered from, billed or housing crews stay usable:
    // approved, for what they already supplied, marked as carried over.
    $carried = [];
    foreach (rows("SELECT o.vendor_name AS name, r.category FROM purchase_orders o JOIN purchase_requests r ON r.id = o.request_id
                   UNION SELECT v.vendor_name, CASE WHEN v.hotel_id IS NULL THEN 'other' ELSE 'lodging' END FROM vendor_invoices v
                   UNION SELECT h.name, 'lodging' FROM hotels h") as $r) {
        $name = trim((string) $r['name']);
        if ($name !== '') {
            // Names compare without case, as the database does.
            $key = mb_strtolower($name);
            $carried[$key]['name'] ??= $name;
            $carried[$key]['cats'][$r['category']] = true;
        }
    }
    $carriedCount = 0;
    foreach ($carried as $v) {
        $carriedCount += q("INSERT IGNORE INTO vendors (name, status, categories, w9_on_file, note, decided_at) VALUES (?, 'approved', ?, 1, ?, NOW())",
          [mb_substr($v['name'], 0, 190), implode(',', array_keys($v['cats'])), 'Carried over at upgrade: already in use. Check the W-9 and insurance.'])->rowCount();
    }
    foreach ([[5000, 'budget_owner'], [25000, 'admin'], [null, 'both']] as [$upTo, $who]) {
        q('INSERT IGNORE INTO procurement_thresholds (up_to, approvers) VALUES (?,?)', [$upTo, $who]);
    }
    // Every existing order is carried wholly by its own project.
    q('INSERT INTO purchase_order_allocations (purchase_order_id, job_id, amount) SELECT o.id, o.job_id, o.total FROM purchase_orders o
       WHERE NOT EXISTS (SELECT 1 FROM purchase_order_allocations a WHERE a.purchase_order_id = o.id)');
    echo 'Approved vendors: ' . $carriedCount . ' vendor(s) already in use carried over as approved; thresholds set at 5,000 (budget owner), '
       . '25,000 (administrator), above (both); every existing order allocated to its own project.' . PHP_EOL;
}

// ── P3-M07: requests for quotation, order revisions, authorization ────────
// Reversed by install/rollback/p3-m07.sql.
$rfqSql = __DIR__ . '/rfq.sql';

if (! is_file($rfqSql)) {
    fwrite(STDERR, "Missing install/rfq.sql\n");
    exit(1);
}

$hadRfq = (int) val("SELECT COUNT(*) FROM information_schema.tables
                     WHERE table_schema=DATABASE() AND table_name='purchase_rfqs'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($rfqSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

foreach ([
    ['purchase_quotations', 'rfq_id', 'INT UNSIGNED NULL'],
    // A quotation holds until this date; an order is not raised on it after.
    ['purchase_quotations', 'valid_until', 'DATE NULL'],
    // 0 as raised, then one more for every revision.
    ['purchase_orders', 'revision', 'SMALLINT UNSIGNED NOT NULL DEFAULT 0'],
    ['purchase_orders', 'quotation_id', 'INT UNSIGNED NULL'],
] as [$table, $column, $definition]) {
    if (! val("SELECT COUNT(*) FROM information_schema.columns
               WHERE table_schema=DATABASE() AND table_name=? AND column_name=?", [$table, $column])) {
        q('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
    }
}

// Approvals belong to a revision: a revised order is authorised again.
if (! val("SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema=DATABASE() AND table_name='purchase_order_approvals' AND column_name='revision'")) {
    q('ALTER TABLE purchase_order_approvals ADD COLUMN revision SMALLINT UNSIGNED NOT NULL DEFAULT 0,
       ADD UNIQUE KEY uq_po_approval_revision (purchase_order_id, revision, approver), DROP INDEX uq_po_approval');
}

if (! $hadRfq) {
    echo 'Requests for quotation and order revisions: in place; quotations are listed as received, never ranked; '
       . (int) val('SELECT COUNT(*) FROM purchase_orders') . ' existing order(s) start at revision 0.' . PHP_EOL;
}

// ── P3-M08: receiving, three-way matching, payment readiness ─────────────
// Reversed by install/rollback/p3-m08.sql.
$matchingSql = __DIR__ . '/matching.sql';

if (! is_file($matchingSql)) {
    fwrite(STDERR, "Missing install/matching.sql\n");
    exit(1);
}

$hadMatching = (int) val("SELECT COUNT(*) FROM information_schema.tables
                          WHERE table_schema=DATABASE() AND table_name='bill_match_clearances'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($matchingSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

foreach ([
    // What arrived and was refused: damaged, wrong, short. Not received.
    ['purchase_receipts', 'rejected_quantity', 'DECIMAL(10,2) NOT NULL DEFAULT 0'],
    ['purchase_receipts', 'rejection_reason', 'VARCHAR(500) NULL'],
    // What the bill charges for, when it says: checked against what arrived.
    ['vendor_invoices', 'quantity', 'DECIMAL(10,2) NULL'],
] as [$table, $column, $definition]) {
    if (! val("SELECT COUNT(*) FROM information_schema.columns
               WHERE table_schema=DATABASE() AND table_name=? AND column_name=?", [$table, $column])) {
        q('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
    }
}

if (! $hadMatching) {
    // The difference allowed before a bill is an exception: 2 %, or $10, whichever is more.
    q("INSERT IGNORE INTO platform_settings (setting_key, setting_value) VALUES ('match_tolerance_percent', '2'), ('match_tolerance_amount', '10')");
    $open = (int) val("SELECT COUNT(*) FROM vendor_invoices WHERE status IN ('received','approved') AND purchase_order_id IS NOT NULL");
    echo 'Three-way matching: bills against an order are matched to it and to what was received before they are paid; '
       . $open . ' open bill(s) against an order are now checked; tolerance 2% or $10.' . PHP_EOL;
}

// ── P2-M03: cycles, goals, development plans ─────────────────────────────
// Reversed by install/rollback/p2-m03.sql.
$performanceSql = __DIR__ . '/performance.sql';

if (! is_file($performanceSql)) {
    fwrite(STDERR, "Missing install/performance.sql\n");
    exit(1);
}

$hadPerformance = (int) val("SELECT COUNT(*) FROM information_schema.tables
                             WHERE table_schema=DATABASE() AND table_name='performance_goals'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($performanceSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

if (! val("SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema=DATABASE() AND table_name='appraisals' AND column_name='cycle_id'")) {
    // The round a review was opened in, if any.
    q('ALTER TABLE appraisals ADD COLUMN cycle_id INT UNSIGNED NULL,
       ADD CONSTRAINT fk_appraisal_cycle FOREIGN KEY (cycle_id) REFERENCES appraisal_cycles(id)');
}

if (! $hadPerformance) {
    echo 'Performance: evaluation cycles, goals with their progress, and development plans with follow-up are in place; '
       . (int) val('SELECT COUNT(*) FROM appraisals') . ' existing review(s) belong to no cycle.' . PHP_EOL;
}

// ── P2-M04: benefits ─────────────────────────────────────────────────────
// Reversed by install/rollback/p2-m04.sql.
$benefitsSql = __DIR__ . '/benefits.sql';

if (! is_file($benefitsSql)) {
    fwrite(STDERR, "Missing install/benefits.sql\n");
    exit(1);
}

$hadBenefits = (int) val("SELECT COUNT(*) FROM information_schema.tables
                          WHERE table_schema=DATABASE() AND table_name='benefit_plans'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($benefitsSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

if (! val("SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema=DATABASE() AND table_name='employee_pay_items' AND column_name='benefit_enrollment_id'")) {
    // A pay item an enrollment put on the person: it is changed through the enrollment, not by hand.
    q('ALTER TABLE employee_pay_items ADD COLUMN benefit_enrollment_id INT UNSIGNED NULL,
       ADD CONSTRAINT fk_pay_item_enrollment FOREIGN KEY (benefit_enrollment_id) REFERENCES benefit_enrollments(id)');
}

if (! $hadBenefits) {
    echo 'Benefits: plans, eligibility, enrollment and waivers are in place; enrollments feed payroll as deductions and employer contributions. No plan yet.' . PHP_EOL;
}

// ── P2-M05: loans and advances ───────────────────────────────────────────
// Reversed by install/rollback/p2-m05.sql.
$loansSql = __DIR__ . '/loans.sql';

if (! is_file($loansSql)) {
    fwrite(STDERR, "Missing install/loans.sql\n");
    exit(1);
}

$hadLoans = (int) val("SELECT COUNT(*) FROM information_schema.tables
                       WHERE table_schema=DATABASE() AND table_name='advance_pauses'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($loansSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

foreach ([
    // An advance is against the next weeks' wages; a loan is larger and longer. Same mechanics.
    'kind'               => "ENUM('advance','loan') NOT NULL DEFAULT 'advance'",
    // The week repayment starts. Unset: the week it was paid out.
    'first_week'         => 'DATE NULL',
    'written_off_amount' => 'DECIMAL(10,2) NULL',
    'written_off_reason' => 'VARCHAR(500) NULL',
    'written_off_by'     => 'INT UNSIGNED NULL',
    'written_off_at'     => 'DATETIME NULL',
] as $column => $definition) {
    if (! val("SELECT COUNT(*) FROM information_schema.columns
               WHERE table_schema=DATABASE() AND table_name='wage_advances' AND column_name=?", [$column])) {
        q('ALTER TABLE wage_advances ADD COLUMN `' . $column . '` ' . $definition);
    }
}
if (! str_contains((string) val("SELECT column_type FROM information_schema.columns
                                  WHERE table_schema=DATABASE() AND table_name='wage_advances' AND column_name='status'"), 'written_off')) {
    q("ALTER TABLE wage_advances MODIFY status ENUM('requested','approved','paid_out','cleared','cancelled','written_off') NOT NULL DEFAULT 'requested'");
}

if (! $hadLoans) {
    // Above this, or when it takes what the person owes above it, an administrator approves.
    q("INSERT IGNORE INTO platform_settings (setting_key, setting_value) VALUES ('advance_admin_above', '1000')");
    echo 'Loans and advances: schedules, approval limits, pauses and write-offs are in place; '
       . (int) val("SELECT COUNT(*) FROM wage_advances WHERE status = 'paid_out'") . ' advance(s) being repaid keep their weekly amount; above $1,000 an administrator approves.' . PHP_EOL;
}

// ── P2-M06: recognition, disciplinary cases, separations, HR access ───────
// Reversed by install/rollback/p2-m06.sql.
$hrRecordsSql = __DIR__ . '/hr-records.sql';

if (! is_file($hrRecordsSql)) {
    fwrite(STDERR, "Missing install/hr-records.sql\n");
    exit(1);
}

$hadHrRecords = (int) val("SELECT COUNT(*) FROM information_schema.tables
                           WHERE table_schema=DATABASE() AND table_name='disciplinary_cases'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($hrRecordsSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

if (! $hadHrRecords) {
    echo 'HR records: recognition, disciplinary cases, separation records and controlled access are in place; '
       . 'only administrators see cases and separations until they grant HR access.' . PHP_EOL;
}

// ── P2-M07: HR requests and employment letters ────────────────────────────
// Reversed by install/rollback/p2-m07.sql.
$hrRequestsSql = __DIR__ . '/hr-requests.sql';

if (! is_file($hrRequestsSql)) {
    fwrite(STDERR, "Missing install/hr-requests.sql\n");
    exit(1);
}

$hadHrRequests = (int) val("SELECT COUNT(*) FROM information_schema.tables
                            WHERE table_schema=DATABASE() AND table_name='hr_requests'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($hrRequestsSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

if (! $hadHrRequests) {
    echo 'Self-service: workers have one home for their details, time off, pay, benefits, reviews, goals and HR record, '
       . 'and can send HR requests; ' . (int) val('SELECT COUNT(*) FROM worker_accounts') . ' worker account(s) see it.' . PHP_EOL;
}

// ── P3-M03: Crewvia's own general ledger ─────────────────────────────────
// Reversed by install/rollback/p3-m03.sql.
$ledgerSql = __DIR__ . '/ledger.sql';

if (! is_file($ledgerSql)) {
    fwrite(STDERR, "Missing install/ledger.sql\n");
    exit(1);
}

$hadLedger = (int) val("SELECT COUNT(*) FROM information_schema.tables
                        WHERE table_schema=DATABASE() AND table_name='gl_journals'");

// The chart of accounts gains numbers, an equity side, and accounts an
// administrator adds. The accounts the records post to are the system's
// and cannot be switched off.
if (! str_contains((string) val("SELECT column_type FROM information_schema.columns
                                 WHERE table_schema=DATABASE() AND table_name='accounting_accounts' AND column_name='side'"), 'equity')) {
    q("ALTER TABLE accounting_accounts MODIFY side ENUM('asset','liability','equity','income','expense') NOT NULL");
}
$numbered = (bool) val("SELECT COUNT(*) FROM information_schema.columns
                        WHERE table_schema=DATABASE() AND table_name='accounting_accounts' AND column_name='number'");
foreach (['number' => 'VARCHAR(10) NULL', 'is_system' => 'TINYINT(1) NOT NULL DEFAULT 0', 'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1'] as $column => $definition) {
    if (! val("SELECT COUNT(*) FROM information_schema.columns
               WHERE table_schema=DATABASE() AND table_name='accounting_accounts' AND column_name=?", [$column])) {
        q('ALTER TABLE accounting_accounts ADD COLUMN `' . $column . '` ' . $definition);
    }
}
if (! val("SELECT COUNT(*) FROM information_schema.statistics
           WHERE table_schema=DATABASE() AND table_name='accounting_accounts' AND index_name='uq_account_number'")) {
    q('ALTER TABLE accounting_accounts ADD UNIQUE KEY uq_account_number (number)');
}
if (! $numbered) {
    q('INSERT IGNORE INTO accounting_accounts (account_key, label, side, qb_account, sort_order) VALUES (?,?,?,?,?)', ['owner_equity', 'Owner equity', 'equity', 'Owner Investment', 0]);
    q('INSERT IGNORE INTO accounting_accounts (account_key, label, side, qb_account, sort_order) VALUES (?,?,?,?,?)', ['retained_earnings', 'Retained earnings', 'equity', 'Retained Earnings', 0]);
    foreach (['bank' => 1000, 'accounts_receivable' => 1100, 'accounts_payable' => 2000, 'net_pay_payable' => 2100, 'deductions_payable' => 2110,
              'employer_payable' => 2120, 'owner_equity' => 3000, 'retained_earnings' => 3900, 'revenue' => 4000, 'wages_expense' => 5000,
              'employer_expense' => 5010, 'per_diem_expense' => 5020, 'hotels_expense' => 5100, 'transportation_expense' => 5110,
              'equipment_expense' => 5120, 'other_expense' => 5900] as $key => $number) {
        q('UPDATE accounting_accounts SET number = ?, sort_order = ?, is_system = 1 WHERE account_key = ? AND number IS NULL', [(string) $number, $number, $key]);
    }
}

foreach (preg_split('/;\s*\n/', (string) file_get_contents($ledgerSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

// A posted journal and its lines are never changed or deleted. Only the
// link to its correcting journal is written later. The triggers need the
// TRIGGER privilege: without it the ledger still refuses changes in the
// application, and its hash chain still shows any made around it.
$guard = static fn(string $cond, string $msg): string => "BEGIN IF $cond THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '$msg'; END IF; END";
$journalSame = implode(' AND ', array_map(fn($c) => "NEW.$c <=> OLD.$c", ['id', 'kind', 'source', 'export_key', 'doc_date', 'posted_on', 'memo', 'status', 'total',
                                                                             'reverses_id', 'reason', 'created_by', 'approved_by', 'posted_at', 'chain_no', 'prev_hash', 'hash']));
$posted = static fn(string $row): string => "(SELECT status FROM gl_journals WHERE id = $row.journal_id) = 'posted'";
$triggers = [
    'gl_journals_no_update' => ['BEFORE UPDATE ON gl_journals', $guard("OLD.status = 'posted' AND NOT ($journalSame)", 'A posted journal cannot be changed')],
    'gl_journals_no_delete' => ['BEFORE DELETE ON gl_journals', $guard("OLD.status = 'posted'", 'A posted journal cannot be deleted')],
    'gl_lines_no_insert'    => ['BEFORE INSERT ON gl_lines', $guard($posted('NEW'), 'A posted journal cannot be changed')],
    'gl_lines_no_update'    => ['BEFORE UPDATE ON gl_lines', $guard($posted('OLD') . ' OR ' . $posted('NEW'), 'A posted journal cannot be changed')],
    'gl_lines_no_delete'    => ['BEFORE DELETE ON gl_lines', $guard($posted('OLD'), 'A posted journal cannot be deleted')],
];
$triggerWarning = null;
foreach ($triggers as $name => [$when, $body]) {
    try {
        db()->exec("CREATE TRIGGER IF NOT EXISTS $name $when FOR EACH ROW $body");
    } catch (Throwable $e) {
        $triggerWarning = $e->getMessage();
    }
}
if ($triggerWarning !== null) {
    echo 'Ledger: WARNING, the database refused the triggers that protect posted journals (' . $triggerWarning . '). '
       . 'The application still refuses changes and the hash chain shows any; grant TRIGGER and run the upgrade again.' . PHP_EOL;
}

if (! $hadLedger) {
    echo 'Ledger: Crewvia keeps its own books; each recorded invoice, payment, credit, bill, claim and payroll period posts itself once, '
       . 'and the QuickBooks export is drawn from it; ' . (int) val('SELECT COUNT(*) FROM accounting_accounts') . ' accounts are numbered.' . PHP_EOL;
}

// ── P3-M05: financial statements and budgets ─────────────────────────────
// Reversed by install/rollback/p3-m05-statements.sql.
$statementsSql = __DIR__ . '/statements.sql';

if (! is_file($statementsSql)) {
    fwrite(STDERR, "Missing install/statements.sql\n");
    exit(1);
}

$hadBudgets = (int) val("SELECT COUNT(*) FROM information_schema.tables
                         WHERE table_schema=DATABASE() AND table_name='gl_budgets'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($statementsSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

if (! $hadBudgets) {
    echo 'Statements: income statement, balance sheet and cash flow are read from the ledger; budgets by account and month, none set yet.' . PHP_EOL;
}

// ── P4-M03: report builder, export policy, schedules, delivery ───────────
// Reversed by install/rollback/p4-m03.sql.
$reportBuilderSql = __DIR__ . '/report-builder.sql';

if (! is_file($reportBuilderSql)) {
    fwrite(STDERR, "Missing install/report-builder.sql\n");
    exit(1);
}

$hadReportBuilder = (int) val("SELECT COUNT(*) FROM information_schema.tables
                               WHERE table_schema=DATABASE() AND table_name='report_definitions'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($reportBuilderSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

if (! $hadReportBuilder) {
    echo 'Report builder: saved reports, exports logged with their fingerprint, schedules delivered inside Crewvia (nothing emailed); '
       . 'run install/run-report-schedules.php hourly to deliver them.' . PHP_EOL;
}

// ── P4-M04: integrations audit, provider tokens, retries ─────────────────
// Reversed by install/rollback/p4-m04.sql.
$integrationsSql = __DIR__ . '/integrations.sql';

if (! is_file($integrationsSql)) {
    fwrite(STDERR, "Missing install/integrations.sql\n");
    exit(1);
}

$hadIntegrations = (int) val("SELECT COUNT(*) FROM information_schema.tables
                              WHERE table_schema=DATABASE() AND table_name='integration_events'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($integrationsSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

// A Gmail connection knows whether Google still accepts it, and a Connect
// event when to try again and why it last failed.
foreach (['gmail_connections' => ['status' => "ENUM('active','reconnect') NOT NULL DEFAULT 'active'", 'scopes' => 'VARCHAR(500) NULL',
                                  'last_refreshed_at' => 'DATETIME NULL', 'last_error' => 'VARCHAR(190) NULL'],
          'docusign_connect_events' => ['next_attempt_at' => 'DATETIME NULL', 'last_error' => 'VARCHAR(190) NULL', 'processed_at' => 'DATETIME NULL']] as $table => $columns) {
    foreach ($columns as $column => $definition) {
        if (! val("SELECT COUNT(*) FROM information_schema.columns
                   WHERE table_schema=DATABASE() AND table_name=? AND column_name=?", [$table, $column])) {
            q('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
        }
    }
}

if (! $hadIntegrations) {
    echo 'Integrations: Gmail, DocuSign and Stripe calls are audited and retried only when safe; '
       . (int) val('SELECT COUNT(*) FROM gmail_connections') . ' Gmail connection(s), '
       . (int) val("SELECT COUNT(*) FROM docusign_connect_events WHERE status='pending'") . ' Connect event(s) pending; every provider stays off until configured.' . PHP_EOL;
}

// ── P4-M05: payroll and applicant-tracking connectors, sandbox first ─────
// Reversed by install/rollback/p4-m05.sql.
$connectorsSql = __DIR__ . '/connectors.sql';

if (! is_file($connectorsSql)) {
    fwrite(STDERR, "Missing install/connectors.sql\n");
    exit(1);
}

$hadConnectors = (int) val("SELECT COUNT(*) FROM information_schema.tables
                            WHERE table_schema=DATABASE() AND table_name='connector_modes'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($connectorsSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

// The audit trail now covers ADP and applicant tracking too.
if (! str_contains((string) val("SELECT column_type FROM information_schema.columns
                                 WHERE table_schema=DATABASE() AND table_name='integration_events' AND column_name='provider'"), "'ats'")) {
    q("ALTER TABLE integration_events MODIFY provider ENUM('gmail','docusign','stripe','adp','ats') NOT NULL");
}

if (! $hadConnectors) {
    // What already worked keeps working: an ADP setup already filled in, and
    // each partner channel already enabled, are recorded live. Anything new
    // starts in sandbox.
    $adpLive = trim((string) val("SELECT setting_value FROM platform_settings WHERE setting_key = 'adp_company_code'")) !== '';
    q("INSERT IGNORE INTO connector_modes (connector, kind, mode, note) VALUES ('adp_run', 'payroll', ?, ?)",
      [$adpLive ? 'live' : 'sandbox', $adpLive ? 'ADP codes were set up before sandbox gating (P4-M05)' : 'Starts in sandbox: ADP files are marked TEST until a test import is accepted']);
    $channels = 0;
    foreach (($config['recruiting_webhooks'] ?? []) as $channel => $entry) {
        if (is_string($channel) && preg_match('/^[a-z0-9_-]{1,60}$/', $channel) && ! empty($entry['enabled'])) {
            q("INSERT IGNORE INTO connector_modes (connector, kind, mode, note) VALUES (?, 'ats', 'live', 'Enabled before sandbox gating (P4-M05)')", ['ats:' . $channel]);
            $channels++;
        }
    }
    echo 'Connectors: ADP ' . ($adpLive ? 'live (its codes were set up)' : 'in sandbox (files marked TEST until ADP accepts one)') . ', ' . $channels
       . ' applicant-tracking channel(s) live as before; new channels start in sandbox; no background-screening provider is connected.' . PHP_EOL;
}

// ── P4-M06: privacy notices and consent, audit log address, append-only ──
// Reversed by install/rollback/p4-m06.sql.
$securityReviewSql = __DIR__ . '/security-review.sql';

if (! is_file($securityReviewSql)) {
    fwrite(STDERR, "Missing install/security-review.sql\n");
    exit(1);
}

$hadSecurityReview = (int) val("SELECT COUNT(*) FROM information_schema.tables
                                WHERE table_schema=DATABASE() AND table_name='privacy_notices'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($securityReviewSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

// The audit log keeps the address each entry came from.
if (! val("SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema=DATABASE() AND table_name='activity' AND column_name='ip'")) {
    q('ALTER TABLE activity ADD COLUMN ip VARCHAR(45) NULL');
}

// And nobody changes or deletes an entry, the application included.
$auditWarning = null;
foreach (['activity_no_update' => 'BEFORE UPDATE', 'activity_no_delete' => 'BEFORE DELETE'] as $name => $when) {
    try {
        db()->exec("CREATE TRIGGER IF NOT EXISTS $name $when ON activity FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The audit log cannot be changed'; END");
    } catch (Throwable $e) {
        $auditWarning = $e->getMessage();
    }
}
if ($auditWarning !== null) {
    echo 'Audit log: WARNING, the database refused the triggers that keep it append-only (' . $auditWarning . '). Grant TRIGGER and run the upgrade again.' . PHP_EOL;
}

if (! $hadSecurityReview) {
    echo 'Security review: sessions bound to their workspace, the audit log append-only with addresses, consent recorded against the privacy notice; '
       . 'no privacy notice is published yet (an administrator writes it under Privacy) and two-step verification is not required until an administrator requires it.' . PHP_EOL;
}

// ── P4-M07: operations - the record of backups, drills and job runs ──
// Reversed by install/rollback/p4-m07.sql.
$operationsSql = __DIR__ . '/operations.sql';

if (! is_file($operationsSql)) {
    fwrite(STDERR, "Missing install/operations.sql\n");
    exit(1);
}

$hadOperations = (int) val("SELECT COUNT(*) FROM information_schema.tables
                            WHERE table_schema=DATABASE() AND table_name='ops_runs'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($operationsSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

if (! $hadOperations) {
    echo 'Operations: errors logged privately with a reference, health at /health and under System health; '
       . 'no backup is taken until install/backup.php runs (by cron, see docs/OPERATIONS.md).' . PHP_EOL;
}

// ── P5-M01: project milestones, tasks and task history ──
// Reversed by install/rollback/p5-m01.sql.
$projectTasksSql = __DIR__ . '/project-tasks.sql';

if (! is_file($projectTasksSql)) {
    fwrite(STDERR, "Missing install/project-tasks.sql\n");
    exit(1);
}

$hadProjectTasks = (int) val("SELECT COUNT(*) FROM information_schema.tables
                              WHERE table_schema=DATABASE() AND table_name='project_tasks'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($projectTasksSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

if (! $hadProjectTasks) {
    echo 'Project tasks: milestones, tasks with assignees, priorities, deadlines and history, and progress per project; '
       . 'no task exists yet (administrators, recruiters and the supervisors of a crew create them under Project tasks).' . PHP_EOL;
}

// ── P5-M02: subtasks, dependencies, estimates, sprints, capacity ──
// Reversed by install/rollback/p5-m02.sql.
$planningSql = __DIR__ . '/project-planning.sql';

if (! is_file($planningSql)) {
    fwrite(STDERR, "Missing install/project-planning.sql\n");
    exit(1);
}

$hadPlanning = (int) val("SELECT COUNT(*) FROM information_schema.tables
                          WHERE table_schema=DATABASE() AND table_name='project_sprints'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($planningSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

// The task columns, each added once.
$taskColumn = fn(string $c): bool => (bool) val("SELECT COUNT(*) FROM information_schema.columns
                                                  WHERE table_schema=DATABASE() AND table_name='project_tasks' AND column_name=?", [$c]);
if (! $taskColumn('parent_id')) {
    q('ALTER TABLE project_tasks ADD COLUMN parent_id INT UNSIGNED NULL AFTER milestone_id,
       ADD CONSTRAINT fk_task_parent FOREIGN KEY (parent_id) REFERENCES project_tasks(id)');
}
if (! $taskColumn('estimate_hours')) {
    q('ALTER TABLE project_tasks ADD COLUMN estimate_hours DECIMAL(6,1) NULL AFTER due_on');
}
if (! $taskColumn('sprint_id')) {
    q('ALTER TABLE project_tasks ADD COLUMN sprint_id INT UNSIGNED NULL AFTER parent_id,
       ADD CONSTRAINT fk_task_sprint FOREIGN KEY (sprint_id) REFERENCES project_sprints(id)');
}

if (! $hadPlanning) {
    echo 'Project planning: a board per project, subtasks, dependencies, estimates, weekly capacity (40 h unless set) and workload; '
       . 'sprints only where a project creates one. Every existing task stays as it was, without an estimate.' . PHP_EOL;
}

// ── P5-M03: asset inspections, maintenance, service, lifecycle, valuation ──
// Reversed by install/rollback/p5-m03.sql.
$assetLifecycleSql = __DIR__ . '/asset-lifecycle.sql';

if (! is_file($assetLifecycleSql)) {
    fwrite(STDERR, "Missing install/asset-lifecycle.sql\n");
    exit(1);
}

$hadAssetLifecycle = (int) val("SELECT COUNT(*) FROM information_schema.tables
                                WHERE table_schema=DATABASE() AND table_name='asset_service_records'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($assetLifecycleSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

foreach (['asset_categories' => [
              'asset_class'        => "ENUM('safety','tools','electronics','vehicles_plant','other') NOT NULL DEFAULT 'other'",
              'useful_life_months' => 'SMALLINT UNSIGNED NULL',
              'residual_percent'   => 'DECIMAL(5,2) NULL',
          ],
          'equipment' => [
              'in_service_on'      => 'DATE NULL',
              'useful_life_months' => 'SMALLINT UNSIGNED NULL',
              'residual_value'     => 'DECIMAL(10,2) NULL',
              'retired_on'         => 'DATE NULL',
              'disposed_on'        => 'DATE NULL',
              'disposal_method'    => "ENUM('sold','scrapped','returned_to_client','donated','written_off') NULL",
              'disposal_proceeds'  => 'DECIMAL(10,2) NULL',
          ]] as $table => $columns) {
    foreach ($columns as $column => $definition) {
        if (! val("SELECT COUNT(*) FROM information_schema.columns
                   WHERE table_schema=DATABASE() AND table_name=? AND column_name=?", [$table, $column])) {
            q('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
        }
    }
}
// A disposed item has left the agency for good.
if (! str_contains((string) val("SELECT column_type FROM information_schema.columns
                                 WHERE table_schema=DATABASE() AND table_name='equipment' AND column_name='status'"), 'disposed')) {
    q("ALTER TABLE equipment MODIFY status ENUM('available','in_repair','lost','retired','disposed') NOT NULL DEFAULT 'available'");
}

if (! $hadAssetLifecycle) {
    // The seeded categories get their class; their life and residual stay for the agency to set.
    foreach (['ppe' => 'safety', 'fall_protection' => 'safety', 'gas_detection' => 'safety', 'tools' => 'tools', 'radios' => 'electronics'] as $code => $class) {
        q("UPDATE asset_categories SET asset_class = ? WHERE code = ? AND asset_class = 'other'", [$class, $code]);
    }
    // Repairs already costed in the history become service records, once.
    $carried = q("INSERT INTO asset_service_records (equipment_id, kind, performed_on, cost, notes, user_id, created_at)
                  SELECT e.equipment_id, 'repair', DATE(e.created_at), e.cost, e.detail, e.user_id, e.created_at
                    FROM asset_events e WHERE e.event = 'repaired'")->rowCount();
    echo 'Asset lifecycle: inspections with results, maintenance plans, service records, disposal and straight-line valuation; '
       . $carried . ' past repair(s) carried into the service history. No item has a useful life until its category or the item is given one.' . PHP_EOL;
}

// ── P5-M04: training courses, assessments, plans, requirements ──
// Reversed by install/rollback/p5-m04.sql.
$trainingSql = __DIR__ . '/training.sql';

if (! is_file($trainingSql)) {
    fwrite(STDERR, "Missing install/training.sql\n");
    exit(1);
}

$hadTraining = (int) val("SELECT COUNT(*) FROM information_schema.tables
                          WHERE table_schema=DATABASE() AND table_name='training_courses'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($trainingSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

if (! $hadTraining) {
    echo 'Training: agency courses with assessments, plans, requirements by desk role, trade or project, renewals and a compliance matrix; '
       . 'no course exists yet (recruiters and administrators write them under Training). Project briefings stay under Safety & learning.' . PHP_EOL;
}

// ── P5-M05: recognition and engagement ──
// Reversed by install/rollback/p5-m05.sql.
$engagementSql = __DIR__ . '/engagement.sql';

if (! is_file($engagementSql)) {
    fwrite(STDERR, "Missing install/engagement.sql\n");
    exit(1);
}

$hadEngagement = (int) val("SELECT COUNT(*) FROM information_schema.tables
                            WHERE table_schema=DATABASE() AND table_name='kudos'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($engagementSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

// A cash reward is paid as a payroll adjustment of its own kind.
$adjustmentKind = (string) val("SELECT COLUMN_TYPE FROM information_schema.columns
                                WHERE table_schema=DATABASE() AND table_name='payroll_adjustments' AND column_name='kind'");

if ($adjustmentKind !== '' && ! str_contains($adjustmentKind, "'reward'")) {
    db()->exec("ALTER TABLE payroll_adjustments MODIFY kind ENUM('correction','back_pay','reimbursement','recovery','reward') NOT NULL");
}

if (! $hadEngagement) {
    echo 'Recognition and engagement: thanks between colleagues, awards with nominations, points and a rewards catalogue '
       . '(cash paid through payroll, time off added to leave), feedback, and anonymous engagement surveys; no award, reward or survey exists yet. '
       . 'Recognition in HR records, performance reviews and HR cases stay where they are.' . PHP_EOL;
}

// ── P5-M06b: trusted phones and offline attendance ──
// Reversed by install/rollback/p5-m06b.sql.
$offlineSql = __DIR__ . '/offline-sync.sql';

if (! is_file($offlineSql)) {
    fwrite(STDERR, "Missing install/offline-sync.sql\n");
    exit(1);
}

$hadOffline = (int) val("SELECT COUNT(*) FROM information_schema.tables
                         WHERE table_schema=DATABASE() AND table_name='device_grants'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($offlineSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

if (! $hadOffline) {
    echo 'Offline attendance: workers and supervisors may trust their phone (14 days) to type hours and gate marks without signal; '
       . 'the server decides each one by the screens\' rules and logs when it was typed. No phone is trusted yet.' . PHP_EOL;
}

// ── P5-M06c: push notifications (off until an administrator turns them on) ──
// Reversed by install/rollback/p5-m06c.sql.
$pushSql = __DIR__ . '/push.sql';

if (! is_file($pushSql)) {
    fwrite(STDERR, "Missing install/push.sql\n");
    exit(1);
}

$hadPush = (int) val("SELECT COUNT(*) FROM information_schema.tables
                      WHERE table_schema=DATABASE() AND table_name='push_subscriptions'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($pushSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

if (! $hadPush) {
    echo 'Push notifications: built and OFF. A push carries no name or message. To use them later: php install/push-keys.php, '
       . 'then an administrator turns them on under Phones and notifications.' . PHP_EOL;
}

// ── P5-M07: texting and calling through the staff member's phone ──
// Reversed by install/rollback/p5-m07.sql.
$messagingSql = __DIR__ . '/messaging.sql';

if (! is_file($messagingSql)) {
    fwrite(STDERR, "Missing install/messaging.sql\n");
    exit(1);
}

$hadMessaging = (int) val("SELECT COUNT(*) FROM information_schema.tables
                           WHERE table_schema=DATABASE() AND table_name='message_templates'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($messagingSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

// A text is a contact like a call or an email, in the same register.
$contactOutcomes = (string) val("SELECT COLUMN_TYPE FROM information_schema.columns
                                 WHERE table_schema=DATABASE() AND table_name='candidate_calls' AND column_name='outcome'");

if ($contactOutcomes !== '' && ! str_contains($contactOutcomes, "'texted'")) {
    q("ALTER TABLE candidate_calls MODIFY outcome
         ENUM('reached','voicemail','no_answer','callback','emailed','replied_email','texted','replied_text',
              'not_interested','wrong_number') NOT NULL DEFAULT 'reached'");
}

if (! val("SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema=DATABASE() AND table_name='candidate_calls' AND column_name='template_id'")) {
    q('ALTER TABLE candidate_calls ADD COLUMN template_id INT UNSIGNED NULL AFTER outcome');
}

if (! $hadMessaging) {
    echo 'Texting: messages written once and filled from Crewvia, opened on the staff member\'s phone or copied for Google Voice, '
       . 'logged as contacts; do-not-text and do-not-call recorded. ' . (int) val('SELECT COUNT(*) FROM message_templates') . ' message(s) to start from. '
       . 'No SMS or travel provider is connected.' . PHP_EOL;
}

// ── P5-M08: Twilio for texts and calls, prepared, in sandbox ──
// Reversed by install/rollback/p5-m08.sql.
$twilioSql = __DIR__ . '/twilio.sql';

if (! is_file($twilioSql)) {
    fwrite(STDERR, "Missing install/twilio.sql\n");
    exit(1);
}

$hadTwilio = (int) val("SELECT COUNT(*) FROM information_schema.tables
                        WHERE table_schema=DATABASE() AND table_name='sms_messages'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($twilioSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

// Twilio's outcomes are audited like the other providers', and it is a connector of its own kind.
if (! str_contains((string) val("SELECT column_type FROM information_schema.columns
                                 WHERE table_schema=DATABASE() AND table_name='integration_events' AND column_name='provider'"), "'twilio'")) {
    q("ALTER TABLE integration_events MODIFY provider ENUM('gmail','docusign','stripe','adp','ats','twilio') NOT NULL");
}

if (! str_contains((string) val("SELECT column_type FROM information_schema.columns
                                 WHERE table_schema=DATABASE() AND table_name='connector_modes' AND column_name='kind'"), "'messaging'")) {
    q("ALTER TABLE connector_modes MODIFY kind ENUM('payroll','ats','screening','messaging') NOT NULL");
}

if (! $hadTwilio) {
    echo 'Twilio: texts and calls from Crewvia prepared, in sandbox; nothing is sent until an administrator enters the credentials on Integrations, '
       . 'the account check passes and live is authorized.' . PHP_EOL;
}

// ── P5-M09: driving-record checks (MVR, Clearinghouse, PSP), recorded ──
// Reversed by install/rollback/p5-m09.sql.
$driverSql = __DIR__ . '/driver-records.sql';

if (! is_file($driverSql)) {
    fwrite(STDERR, "Missing install/driver-records.sql\n");
    exit(1);
}

$hadDriver = (int) val("SELECT COUNT(*) FROM information_schema.tables
                        WHERE table_schema=DATABASE() AND table_name='driver_checks'");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($driverSql)) as $chunk) {
    $lines = array_filter(explode("\n", $chunk), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statement = trim(implode("\n", $lines));

    if ($statement !== '') { db()->exec($statement); }
}

if (! $hadDriver) {
    echo 'Driving records: MVR (MVRNOW, with the CDL), the FMCSA Clearinghouse and PSP, run in their own sites and recorded here with the consent, '
       . 'the decision and the yearly due date; a valid CDL and the medical certificate go into Qualifications. No provider is connected.' . PHP_EOL;
}

echo "Upgrade complete. Existing records preserved.\n";
