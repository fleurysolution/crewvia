<?php
/**
 * Recruiting. Chenise and Violeta live on this screen.
 *
 * It is a call list before it is a database: searchable, filterable, and the
 * colour says at a glance who nobody has spoken to yet.
 */

require_role('recruiter');

// ── Actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? '';

    if ($do === 'log_call') {
        $cid     = (int) ($_POST['candidate_id'] ?? 0);
        $outcome = (string) ($_POST['outcome'] ?? 'reached');
        $note    = trim((string) ($_POST['note'] ?? ''));

        $cand = row('SELECT id, full_name, stage FROM candidates WHERE id = ?', [$cid]);

        if (! $cand) {
            flash(t('That candidate no longer exists.'), 'err');
            redirect('/candidates');
        }

        if (! array_key_exists($outcome, contact_outcomes())) { refuse(422, t('Invalid contact outcome.')); }
        q('INSERT INTO candidate_calls (candidate_id, user_id, outcome, note) VALUES (?,?,?,?)',
          [$cid, uid(), $outcome, $note ?: null]);

        // A call moves somebody off "never contacted" - but only forwards.
        // Ringing a man who already accepted must not drag him backwards.
        $advance = in_array($cand['stage'], ['new'], true);

        q('UPDATE candidates SET last_contact_at = NOW(), owner_id = COALESCE(owner_id, ?)'
          . ($advance ? ", stage = 'contacted'" : '') . ' WHERE id = ?', [uid(), $cid]);

        log_activity('logged a call', 'candidate', $cid, $cand['full_name'] . ' - ' . $outcome);
        flash(t('Call logged for :name.', ['name'=>$cand['full_name']]));
        redirect('/candidates?' . ($_POST['back'] ?? ''));
    }

    if ($do === 'stage') {
        $cid   = (int) ($_POST['candidate_id'] ?? 0);
        $stage = (string) ($_POST['stage'] ?? '');
        $ok    = ['new','contacted','screening','offered','accepted','declined','rejected','placed'];

        if (! in_array($stage, $ok, true)) {
            flash(t('Unknown stage.'), 'err');
            redirect('/candidates');
        }

        $cand = row('SELECT id, full_name FROM candidates WHERE id = ?', [$cid]);

        if ($cand) {
            q('UPDATE candidates SET stage = ?, owner_id = COALESCE(owner_id, ?) WHERE id = ?',
              [$stage, uid(), $cid]);
            q('INSERT INTO candidate_events(candidate_id,user_id,event_type,detail) VALUES (?,?,?,?)',[$cid,uid(),'stage',$stage]);
            log_activity('moved stage', 'candidate', $cid, $cand['full_name'] . ' -> ' . $stage);
            flash(t(':name moved to :stage.', ['name'=>$cand['full_name'],'stage'=>t($stage)]));
        }

        redirect('/candidates?' . ($_POST['back'] ?? ''));
    }
}

// ── The list ─────────────────────────────────────────────────────────────
$search     = trim((string) ($_GET['q'] ?? ''));
$stage      = (string) ($_GET['stage'] ?? '');
$discipline = (string) ($_GET['field'] ?? '');
$hasPhone   = ($_GET['phone'] ?? '') === '1';
$mine       = ($_GET['mine'] ?? '') === '1';
$skill      = (string) ($_GET['skill'] ?? '');
$blocked    = ($_GET['blocked'] ?? '') === '1';

$where = [];
$args  = [];

if ($search !== '') {
    $where[] = '(c.full_name LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.city LIKE ?)';
    $like    = '%' . $search . '%';
    array_push($args, $like, $like, $like, $like);
}

if ($stage !== '' && $stage !== 'all') { $where[] = 'c.stage = ?';      $args[] = $stage; }
if ($discipline !== '')                { $where[] = 'c.discipline = ?'; $args[] = $discipline; }
if ($hasPhone)                         { $where[] = "c.phone IS NOT NULL AND c.phone <> ''"; }
if ($mine)                             { $where[] = 'c.owner_id = ?';   $args[] = uid(); }

// Find the welders. Discipline says which department somebody belongs to
// and could never answer this.
if ($skill !== '' && array_key_exists($skill, skills_list(true))) {
    $where[] = 'EXISTS(SELECT 1 FROM candidate_skills cs
                        WHERE cs.candidate_id = c.id AND cs.skill_slug = ?)';
    $args[]  = $skill;
}

// The register itself: everybody the agency must not call.
if ($blocked) {
    $where[] = "EXISTS(SELECT 1 FROM employee_profiles ep
                        WHERE ep.candidate_id = c.id
                          AND ep.rehire_status IN ('ineligible','do_not_use'))";
}

if(isset($_GET['worked_before'])) $where[]="EXISTS(SELECT 1 FROM placements p WHERE p.candidate_id=c.id AND p.status IN ('on_site','completed'))";
if(isset($_GET['rejected_before'])) $where[]="(c.stage='rejected' OR EXISTS(SELECT 1 FROM applications a WHERE a.candidate_id=c.id AND a.stage='rejected') OR EXISTS(SELECT 1 FROM application_events ae JOIN applications ap ON ap.id=ae.application_id WHERE ap.candidate_id=c.id AND ae.stage='rejected') OR EXISTS(SELECT 1 FROM candidate_events ev WHERE ev.candidate_id=c.id AND ev.event_type='stage' AND ev.detail='rejected'))";
if(isset($_GET['screened_before'])) $where[]="EXISTS(SELECT 1 FROM screening_cases sc JOIN placements sp ON sp.id=sc.placement_id WHERE sp.candidate_id=c.id AND sc.status='cleared')";
// Every blocked state, not just the first one that existed: with
// 'do_not_use' added, listing it as available for rehire would be the
// exact mistake the register is there to prevent.
if(isset($_GET['available'])) $where[]="EXISTS(SELECT 1 FROM employee_profiles ep WHERE ep.candidate_id=c.id AND ep.availability='available' AND ep.rehire_status NOT IN ('ineligible','do_not_use'))";
$sql = 'SELECT c.*, u.name AS owner_name, ep.rehire_status,
               (SELECT COUNT(*) FROM candidate_calls k WHERE k.candidate_id = c.id) AS calls,
               (SELECT k.outcome FROM candidate_calls k WHERE k.candidate_id = c.id
                 ORDER BY k.id DESC LIMIT 1) AS last_outcome,
               (SELECT GROUP_CONCAT(cs.skill_slug ORDER BY cs.confirmed DESC SEPARATOR ",")
                  FROM candidate_skills cs WHERE cs.candidate_id = c.id) AS skill_slugs
        FROM candidates c
        LEFT JOIN users u ON u.id = c.owner_id
        LEFT JOIN employee_profiles ep ON ep.candidate_id = c.id';

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

// Never-contacted first: this is a call list, so the top of it should be the
// next person to ring.
$page=max(1,(int)($_GET['page'] ?? 1));$offset=($page-1)*100;
$listTotal=(int)val('SELECT COUNT(*) FROM candidates c'.($where?' WHERE '.implode(' AND ',$where):''),$args);
$sql .= " ORDER BY FIELD(c.stage,'new','contacted','screening','offered','accepted','placed','declined','rejected'),
                   c.last_contact_at IS NOT NULL, c.last_contact_at ASC, c.full_name ASC
          LIMIT 100 OFFSET ".$offset;

$list   = rows($sql, $args);
$counts = array_column(rows('SELECT stage, COUNT(*) n FROM candidates GROUP BY stage'), 'n', 'stage');
$total  = (int) val('SELECT COUNT(*) FROM candidates');
$qs     = http_build_query(array_filter($_GET, static fn ($v) => $v !== '' && $v !== null));

$pageTitle = t('Candidates').' · '.$config['app_name'];
render('candidates', compact('list','counts','total','search','stage','discipline','hasPhone','mine','skill','blocked','qs','page','listTotal'));
