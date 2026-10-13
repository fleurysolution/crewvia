<?php
/**
 * Hotel folios (T20): each hotel's weekly statement checked against the
 * bookings, then turned into its bill in Payables. See app/folios.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../folios.php';

require_role('hotels', 'payroll');
$foJobId = (int) (current_job()['id'] ?? 0);

/** The folio a form names, on the working project. */
function fo_posted(int $jobId): array
{
    $line = (int) ($_POST['line_id'] ?? 0) ? row('SELECT * FROM hotel_folio_lines WHERE id = ?', [(int) $_POST['line_id']]) : null;
    $folio = fo_folio($line ? (int) $line['folio_id'] : (int) ($_POST['folio_id'] ?? 0), $jobId);
    if (! $folio || ((int) ($_POST['line_id'] ?? 0) && ! $line)) {
        refuse(404, t('That folio is not on this project.'), '/folios');
    }

    return [$folio, $line];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    $back = '/folios';
    if ($do === 'open') {
        [$why, $id] = fo_open($foJobId, (int) ($_POST['hotel_id'] ?? 0), (string) ($_POST['week'] ?? ''), (string) ($_POST['reference'] ?? ''), (string) ($_POST['due_on'] ?? ''), uid());
        $back = $id ? '/folios?folio=' . $id : '/folios';
    } else {
        [$folio, $line] = fo_posted($foJobId);
        $back = '/folios?folio=' . (int) $folio['id'];
        $kind = (string) ($_POST['kind'] ?? ($line['kind'] ?? ''));
        $why = match ($do) {
            'line_save'   => $line ? fo_line_write($folio, $line, (string) $line['kind'], $_POST, uid()) : t('Unknown action.'),
            'line_add'    => in_array($kind, ['room', 'tax', 'incidental'], true) ? fo_line_write($folio, null, $kind, $_POST, uid()) : t('Unknown action.'),
            'line_delete' => $line ? fo_line_delete($folio, $line) : t('Unknown action.'),
            'reconcile'   => fo_reconcile($folio, uid()),
            'reopen'      => fo_reopen($folio),
            'bill'        => can('payroll') ? fo_bill($folio, (string) ($_POST['due_on'] ?? ''), uid()) : t('Payroll turns a folio into a bill.'),
            default       => t('Unknown action.'),
        };
    }
    if ($why !== null) {
        refuse(422, $why, $back);
    }
    flash(match ($do) {
        'open'      => t('Folio opened with a line for each guest booked that week. Correct each line to what the hotel billed.'),
        'reconcile' => t('Folio reconciled.'),
        'bill'      => t('The folio is now the hotel\'s bill in Payables.'),
        default     => t('Saved.'),
    });
    redirect($back);
}

$foFolio = isset($_GET['folio']) ? fo_folio((int) $_GET['folio'], $foJobId) : null;
if (isset($_GET['folio']) && ! $foFolio) {
    refuse(404, t('That folio is not on this project.'), '/folios');
}
$foLines = $foFolio ? fo_lines($foFolio) : [];
$foTotals = $foFolio ? fo_totals($foLines) : null;
$foGuests = $foFolio ? fo_guests($foFolio) : [];
$foList = [];
if (! $foFolio) {
    foreach (rows('SELECT f.*, h.name AS hotel, v.reference AS bill_reference FROM hotel_folios f JOIN hotels h ON h.id = f.hotel_id LEFT JOIN vendor_invoices v ON v.id = f.vendor_invoice_id
                   WHERE f.job_id = ? ORDER BY f.week_ending DESC, h.name LIMIT 200', [$foJobId]) as $f) {
        $lines = fo_lines($f);
        $f['totals'] = fo_totals($lines);
        $f['open_lines'] = count(array_filter($lines, static fn($l) => $l['needs_note'] || $l['needs_charge']));
        $foList[] = $f;
    }
}
$foHotels = rows('SELECT h.id, h.name FROM hotels h LEFT JOIN lodging l ON l.hotel_id = h.id LEFT JOIN placements p ON p.id = l.placement_id
                  GROUP BY h.id, h.name ORDER BY MAX(p.job_id = ?) DESC, h.name', [$foJobId]);
$foFlags = fo_flags();
$pageTitle = t('Hotel folios') . ' · ' . $config['app_name'];
render('folios', compact('foFolio', 'foLines', 'foTotals', 'foGuests', 'foList', 'foHotels', 'foFlags'));
