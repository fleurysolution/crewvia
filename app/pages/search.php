<?php
/**
 * One box that finds anything.
 *
 * The header already had a search, and it only ever looked at employee
 * folders - so finding a hotel, a project or a client order meant knowing
 * which screen to open first. People kept that map in their head.
 *
 * This looks in every place a name or a number might be, and shows each
 * answer with enough around it to recognise. Each group is only searched when
 * the person's desk can open it, so a search never reveals the existence of
 * something they could not otherwise reach.
 */

require_login();

$q = trim((string) ($_GET['q'] ?? ''));

$groups = [];
$total  = 0;

if ($q !== '' && mb_strlen($q) >= 2) {
    $like   = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
    $digits = preg_replace('/[^0-9]/', '', $q);
    $phone  = $digits !== '' ? '%' . $digits . '%' : null;

    $add = static function (string $title, string $icon, array $rows) use (&$groups, &$total): void {
        if ($rows) {
            $groups[] = ['title' => $title, 'icon' => $icon, 'rows' => $rows];
            $total += count($rows);
        }
    };

    // ── people ───────────────────────────────────────────────────────────
    if (can('recruiter') || can('payroll') || can('hotels')) {
        $add('People', 'person', rows(
            "SELECT c.id, c.full_name, c.discipline, c.phone, c.email, c.city, c.state, c.stage,
                    (SELECT COUNT(*) FROM placements p WHERE p.candidate_id = c.id) placements
             FROM candidates c
             WHERE c.full_name LIKE ? OR c.email LIKE ?
                " . ($phone ? 'OR c.normalized_phone LIKE ?' : '') . "
             ORDER BY c.full_name LIMIT 25",
            $phone ? [$like, $like, $phone] : [$like, $like]));
    }

    // ── projects and the orders on them ──────────────────────────────────
    if (can('recruiter') || can('payroll') || can('hotels') || can('admin')) {
        $add('Projects', 'project', rows(
            'SELECT j.id, j.title, j.status, j.site_name, j.site_city, j.site_state,
                    cl.name AS client_name
             FROM jobs j LEFT JOIN clients cl ON cl.id = j.client_id
             WHERE j.title LIKE ? OR j.site_name LIKE ? OR j.site_city LIKE ? OR cl.name LIKE ?
             ORDER BY j.id DESC LIMIT 15',
            [$like, $like, $like, $like]));
    }

    if (can('recruiter')) {
        $add('Client orders', 'order', rows(
            'SELECT v.id, v.title, v.is_open, v.discipline, v.openings, j.title AS project
             FROM vacancies v JOIN jobs j ON j.id = v.job_id
             WHERE v.title LIKE ? OR v.description LIKE ?
             ORDER BY v.id DESC LIMIT 15',
            [$like, $like]));
    }

    // ── beds ─────────────────────────────────────────────────────────────
    if (can('hotels')) {
        $add('Hotels', 'hotel', rows(
            'SELECT h.id, h.name, h.city, h.state, h.phone, h.nightly_rate,
                    (SELECT COUNT(*) FROM lodging l
                      WHERE l.hotel_id = h.id AND l.status <> ?) rooms
             FROM hotels h
             WHERE h.name LIKE ? OR h.city LIKE ? OR h.address LIKE ?
             ORDER BY h.name LIMIT 15',
            ['cancelled', $like, $like, $like]));
    }

    // ── paperwork ────────────────────────────────────────────────────────
    if (can('recruiter')) {
        $add('Contracts', 'contract', rows(
            'SELECT ct.id, ct.title, ct.status, ct.revision, c.full_name
             FROM employment_contracts ct
             JOIN applications a ON a.id = ct.application_id
             JOIN candidates c ON c.id = a.candidate_id
             WHERE ct.title LIKE ? OR c.full_name LIKE ?
             ORDER BY ct.id DESC LIMIT 15',
            [$like, $like]));
    }

    // ── the team ─────────────────────────────────────────────────────────
    if (can('admin')) {
        $add('Accounts', 'account', rows(
            'SELECT id, name, email, role, is_active
             FROM users WHERE name LIKE ? OR email LIKE ?
             ORDER BY name LIMIT 15',
            [$like, $like]));
    }
}

// ── who is where ────────────────────────────────────────────────────────
// With nothing typed, the screen is a directory rather than a prompt. The
// same question every desk asks all day - where is this person working,
// sleeping, and who runs them - answered without having to know a name to
// type first.
//
// Scoped by desk, like the search itself: a supervisor sees the crew they
// are responsible for, and nobody else appears.
$directory = [];
$scope     = '';

if ($q === '') {
    $where  = ["p.status IN ('confirmed','travelling','on_site')"];
    $args   = [];

    if (can('admin') || can('recruiter') || can('hotels') || can('payroll')) {
        $scope = 'Everybody out on a project.';
    } elseif (can('supervisor')) {
        $where[] = 'd.supervisor_id = ?';
        $args[]  = uid();
        $scope   = 'The crew you are responsible for.';
    } else {
        $where[] = '0';
        $scope   = '';
    }

    if ($scope !== '') {
        $directory = rows(
            "SELECT p.id, p.status, p.start_date,
                    c.id candidate_id, c.full_name, c.discipline, c.phone,
                    j.title project, j.site_name, j.site_city, j.site_state,
                    h.name hotel, l.room_number, l.private_room,
                    u.name supervisor, d.trade, d.shift_label
             FROM placements p
             JOIN candidates c ON c.id = p.candidate_id
             JOIN jobs j       ON j.id = p.job_id
             LEFT JOIN assignment_details d ON d.placement_id = p.id
             LEFT JOIN users u  ON u.id = d.supervisor_id
             LEFT JOIN lodging l ON l.placement_id = p.id
                  AND l.status IN ('held','booked','checked_in')
             LEFT JOIN hotels h ON h.id = l.hotel_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY j.title, c.full_name
             LIMIT 500", $args);
    }
}

$pageTitle = ($q !== '' ? $q . ' · ' : '') . t('Search') . ' · ' . $config['app_name'];

render('search', compact('q', 'groups', 'total', 'directory', 'scope'));
