<?php
/**
 * The workforce at a glance, for the working project.
 *
 * Four figures for today and two daily series, drawn from the records the
 * desks already keep: the roll call (assignment_checkins), approved time
 * off and the assignments themselves. Nothing here is stored; every
 * number is a count of rows, so it can be checked against the screen that
 * holds them.
 *
 * Staff only. Workers, supervisors and clients have their own pages, and
 * a head count of other people's attendance is not theirs to read.
 */

declare(strict_types=1);

function workforce_overview_visible(): bool
{
    $role = (string) (user()['role'] ?? '');

    return ! in_array($role, ['worker', 'supervisor', 'client'], true)
        && (can('recruiter') || can('payroll') || can('hotels'));
}

/** @return array{on_assignment:int,present:int,absent:int,on_leave:int,unmarked:int} */
function workforce_today(int $jobId): array
{
    $today = date('Y-m-d');

    $onAssignment = (int) val("SELECT COUNT(*) FROM placements
                               WHERE job_id = ? AND status IN ('confirmed','travelling','on_site')", [$jobId]);

    $marks = row("SELECT COALESCE(SUM(k.present = 1), 0) AS present, COALESCE(SUM(k.present = 0), 0) AS absent
                  FROM assignment_checkins k JOIN placements p ON p.id = k.placement_id
                  WHERE p.job_id = ? AND k.work_date = ?", [$jobId, $today]) ?: ['present' => 0, 'absent' => 0];

    $onLeave = (int) val("SELECT COUNT(DISTINCT t.placement_id) FROM time_off_requests t
                          JOIN placements p ON p.id = t.placement_id
                          WHERE p.job_id = ? AND t.status = 'approved' AND ? BETWEEN t.starts_on AND t.ends_on",
                         [$jobId, $today]);

    $present = (int) $marks['present'];
    $absent  = (int) $marks['absent'];

    return [
        'on_assignment' => $onAssignment,
        'present'       => $present,
        'absent'        => $absent,
        'on_leave'      => $onLeave,
        'unmarked'      => max(0, $onAssignment - $present - $absent - $onLeave),
    ];
}

/**
 * One count per day, oldest first, for the last $days days including
 * today. Days with nothing recorded are zero, not missing, so the chart
 * shows the gap.
 *
 * @return array<string,int>
 */
function workforce_daily(int $jobId, int $days, bool $present): array
{
    $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $series = [];

    for ($i = $days - 1; $i >= 0; $i--) {
        $series[date('Y-m-d', strtotime('-' . $i . ' days'))] = 0;
    }

    foreach (rows('SELECT k.work_date, COUNT(*) AS n FROM assignment_checkins k
                   JOIN placements p ON p.id = k.placement_id
                   WHERE p.job_id = ? AND k.present = ? AND k.work_date BETWEEN ? AND CURDATE()
                   GROUP BY k.work_date', [$jobId, $present ? 1 : 0, $from]) as $r) {
        $series[(string) $r['work_date']] = (int) $r['n'];
    }

    return $series;
}

/** The people most recently put on the project. */
function workforce_recently_placed(int $jobId, int $limit = 5): array
{
    return rows("SELECT p.id, c.full_name, p.start_date, p.status, d.trade
                 FROM placements p JOIN candidates c ON c.id = p.candidate_id
                 LEFT JOIN assignment_details d ON d.placement_id = p.id
                 WHERE p.job_id = ? AND p.status <> 'cancelled'
                 ORDER BY p.id DESC LIMIT " . max(1, min(20, $limit)), [$jobId]);
}

/**
 * A bar chart as inline SVG. Server-side, no library, readable without
 * JavaScript; colours come from the stylesheet so it follows the theme.
 */
function workforce_bar_chart(array $series, string $label, string $tone): string
{
    $w = 640; $h = 200; $left = 34; $bottom = 26; $top = 10; $right = 8;
    $max = max(1, ...array_values($series ?: [0]));
    // A round ceiling, so the axis reads 0, 5, 10 rather than 0, 3.3, 6.7.
    $step = $max <= 5 ? 1 : ($max <= 20 ? 5 : ($max <= 100 ? 20 : 50));
    $ceil = (int) (ceil($max / $step) * $step);
    $plotW = $w - $left - $right;
    $plotH = $h - $top - $bottom;
    $n = max(1, count($series));
    $slot = $plotW / $n;
    $bar = max(2.0, $slot * 0.62);

    $svg = '<svg class="wf-chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . e($label) . '">';
    $svg .= '<title>' . e($label) . '</title>';

    for ($v = 0; $v <= $ceil; $v += $step) {
        $y = $top + $plotH - ($v / $ceil) * $plotH;
        $svg .= '<line class="wf-grid" x1="' . $left . '" x2="' . ($w - $right) . '" y1="' . round($y, 1) . '" y2="' . round($y, 1) . '"/>';
        $svg .= '<text class="wf-axis" x="' . ($left - 6) . '" y="' . round($y + 4, 1) . '" text-anchor="end">' . $v . '</text>';
    }

    $i = 0;
    $every = max(1, (int) ceil($n / 8));

    foreach ($series as $date => $value) {
        $x = $left + $i * $slot + ($slot - $bar) / 2;
        $bh = ($value / $ceil) * $plotH;
        $y = $top + $plotH - $bh;

        if ($value > 0) {
            $svg .= '<rect class="wf-bar ' . e($tone) . '" x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($bar, 1)
                  . '" height="' . round($bh, 1) . '" rx="2"><title>' . e(d((string) $date) . ': ' . $value) . '</title></rect>';
        }

        if ($i % $every === 0 || $i === $n - 1) {
            $svg .= '<text class="wf-axis" x="' . round($x + $bar / 2, 1) . '" y="' . ($h - 8) . '" text-anchor="middle">'
                  . e(d((string) $date, 'j M')) . '</text>';
        }

        $i++;
    }

    return $svg . '</svg>';
}
