<?php
/**
 * P5-M104: the four emails every applicant receives, whatever the way they applied and whether or not they have a
 * portal - received, under review, retained, not retained. Sent once per application each, from a template the agency
 * edits; "not retained" waits 24 hours so a rejection clicked by mistake can be undone. Every email is written on the
 * applicant's record (candidate_email_log and candidate_events): a refusal is uniform and traceable.
 * Test mode (Email delivery) applies: in test mode they go to the test inbox.
 */

declare(strict_types=1);

const CE_KEYS = ['received', 'in_review', 'retained', 'not_retained'];
const CE_REFUSAL_DELAY_HOURS = 24;
/** P5-M104b: more refusals than this within 10 minutes is a clean-up, not a decision per person: the batch is held. */
const CE_BATCH_LIMIT = 10;

/** What each email is for, in the agency's screen. */
function ce_labels(): array
{
    return [
        'received'     => t('Application received'),
        'in_review'    => t('Application under review'),
        'retained'     => t('Applicant retained'),
        'not_retained' => t('Applicant not retained'),
    ];
}

function ce_when(): array
{
    return [
        'received'     => t('At once, when the application arrives (careers page, the agency\'s website, a job board link).'),
        'in_review'    => t('When a recruiter takes the application in hand: moved to screening or interview, or sent to a hiring manager for review.'),
        'retained'     => t('When the hiring manager approves the applicant, or an offer is made.'),
        'not_retained' => t('24 hours after the application is rejected - not sent if it is reopened in the meantime.'),
    ];
}

/** The texts a new installation starts with, in the three languages. */
function ce_defaults(): array
{
    return [
        'en' => [
            'received'     => ['We received your application: {job_title}', "Hello {first_name},\n\nThank you for applying for {job_title}. We have received your application and a recruiter will review it.\n\nIf your profile fits, we will contact you about the next steps.\n\n{agency}"],
            'in_review'    => ['Your application is being reviewed: {job_title}', "Hello {first_name},\n\nA recruiter is now reviewing your application for {job_title}. We may call you to talk about the position.\n\n{agency}"],
            'retained'     => ['Good news about your application: {job_title}', "Hello {first_name},\n\nYour application for {job_title} has been retained. A member of our team will contact you shortly about the next steps.\n\n{agency}"],
            'not_retained' => ['Your application: {job_title}', "Hello {first_name},\n\nThank you for your interest in {job_title}. After review, we will not move forward with your application for this position.\n\nWe keep your details and may contact you about other positions.\n\n{agency}"],
        ],
        'fr' => [
            'received'     => ['Nous avons reçu votre candidature : {job_title}', "Bonjour {first_name},\n\nMerci d'avoir postulé pour le poste {job_title}. Nous avons bien reçu votre candidature et un recruteur va l'examiner.\n\nSi votre profil correspond, nous vous contacterons pour la suite.\n\n{agency}"],
            'in_review'    => ['Votre candidature est en cours d\'examen : {job_title}', "Bonjour {first_name},\n\nUn recruteur examine maintenant votre candidature pour le poste {job_title}. Il se peut que nous vous appelions pour en parler.\n\n{agency}"],
            'retained'     => ['Bonne nouvelle pour votre candidature : {job_title}', "Bonjour {first_name},\n\nVotre candidature pour le poste {job_title} a été retenue. Un membre de notre équipe vous contactera très bientôt pour la suite.\n\n{agency}"],
            'not_retained' => ['Votre candidature : {job_title}', "Bonjour {first_name},\n\nMerci de l'intérêt que vous portez au poste {job_title}. Après examen, nous ne donnerons pas suite à votre candidature pour ce poste.\n\nNous conservons vos coordonnées et pourrons vous contacter pour d'autres postes.\n\n{agency}"],
        ],
        'es' => [
            'received'     => ['Recibimos su solicitud: {job_title}', "Hola {first_name},\n\nGracias por postularse para {job_title}. Recibimos su solicitud y un reclutador la revisará.\n\nSi su perfil coincide, le contactaremos sobre los siguientes pasos.\n\n{agency}"],
            'in_review'    => ['Su solicitud está en revisión: {job_title}', "Hola {first_name},\n\nUn reclutador está revisando ahora su solicitud para {job_title}. Es posible que le llamemos para hablar del puesto.\n\n{agency}"],
            'retained'     => ['Buenas noticias sobre su solicitud: {job_title}', "Hola {first_name},\n\nSu solicitud para {job_title} fue seleccionada. Un miembro de nuestro equipo le contactará pronto sobre los siguientes pasos.\n\n{agency}"],
            'not_retained' => ['Su solicitud: {job_title}', "Hola {first_name},\n\nGracias por su interés en {job_title}. Tras revisarla, no seguiremos adelante con su solicitud para este puesto.\n\nConservamos sus datos y podremos contactarle para otros puestos.\n\n{agency}"],
        ],
    ];
}

/** The language the emails are written in (the agency chooses it on the templates screen). */
function ce_locale(): string
{
    $l = (string) val("SELECT setting_value FROM platform_settings WHERE setting_key = 'candidate_email_locale'");

    return in_array($l, ['en', 'fr', 'es'], true) ? $l : 'en';
}

/** A template as it stands now: the agency's text, or the default when it never edited it. */
function ce_template(string $key, ?string $locale = null): array
{
    $locale ??= ce_locale();
    $row = row('SELECT subject, body FROM candidate_email_templates WHERE template_key = ? AND locale = ?', [$key, $locale]);
    if ($row) {
        return ['subject' => (string) $row['subject'], 'body' => (string) $row['body'], 'enabled' => ce_enabled($key), 'edited' => true];
    }
    [$subject, $body] = ce_defaults()[$locale][$key];

    return ['subject' => $subject, 'body' => $body, 'enabled' => ce_enabled($key), 'edited' => false];
}

function ce_enabled(string $key): bool
{
    return val("SELECT setting_value FROM platform_settings WHERE setting_key = ?", ['candidate_email_off_' . $key]) !== '1';
}

/** {first_name}, {job_title}, {agency} filled in. */
function ce_render(string $text, array $vars): string
{
    return strtr($text, ['{first_name}' => $vars['first_name'], '{job_title}' => $vars['job_title'], '{agency}' => $vars['agency']]);
}

function ce_vars(int $applicationId): ?array
{
    $a = row('SELECT a.id, a.candidate_id, a.stage, c.full_name, c.email, v.title FROM applications a
              JOIN candidates c ON c.id = a.candidate_id JOIN vacancies v ON v.id = a.vacancy_id WHERE a.id = ?', [$applicationId]);
    if (! $a || ! filter_var((string) $a['email'], FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    $first = trim((string) strtok((string) $a['full_name'], ' '));

    return ['application' => $a, 'first_name' => $first !== '' ? $first : (string) $a['full_name'], 'job_title' => (string) $a['title'], 'agency' => agency_name()];
}

/** Queue one email of an application now, once. Returns whether it was queued. */
function ce_send(int $applicationId, string $key): bool
{
    if (! in_array($key, CE_KEYS, true) || ! ce_enabled($key)) {
        return false;
    }
    $logged = row('SELECT status FROM candidate_email_log WHERE application_id = ? AND template_key = ?', [$applicationId, $key]);
    if ($logged && $logged['status'] !== 'scheduled') {
        return false;   // once per application
    }
    $vars = ce_vars($applicationId);
    if (! $vars) {
        return false;
    }
    $t = ce_template($key);
    $subject = ce_render($t['subject'], $vars);
    $body = ce_render($t['body'], $vars);
    require_once __DIR__ . '/notices.php';
    $cid = (int) $vars['application']['candidate_id'];
    mail_queue($cid, (string) $vars['application']['email'], $subject, $body, 'candidate:' . $key . ':' . $applicationId);
    $fingerprint = substr(hash('sha256', $t['subject'] . "\n" . $t['body']), 0, 16);
    q("INSERT INTO candidate_email_log (application_id, candidate_id, template_key, status, send_after, sent_at, subject, template_fingerprint)
       VALUES (?,?,?, 'sent', NOW(), NOW(), ?, ?)
       ON DUPLICATE KEY UPDATE status = 'sent', sent_at = NOW(), subject = VALUES(subject), template_fingerprint = VALUES(template_fingerprint)",
      [$applicationId, $cid, $key, mb_substr($subject, 0, 255), $fingerprint]);
    q('INSERT INTO candidate_events (candidate_id, event_type, detail) VALUES (?,?,?)', [$cid, 'email sent', ce_labels()[$key] . ': ' . mb_substr($subject, 0, 200)]);

    return true;
}

/** "Not retained" waits; a rejection undone in the meantime is never told. */
function ce_schedule_refusal(int $applicationId): void
{
    if (! ce_enabled('not_retained') || row('SELECT id FROM candidate_email_log WHERE application_id = ? AND template_key = ?', [$applicationId, 'not_retained'])) {
        return;
    }
    $cid = (int) val('SELECT candidate_id FROM applications WHERE id = ?', [$applicationId]);
    // P5-M104b: a burst of refusals (a clean-up of old applications) is held - none leaves until an administrator
    // decides on Emails to applicants. The whole burst is held, not only what comes after the limit.
    $recent = (int) val("SELECT COUNT(*) FROM candidate_email_log WHERE template_key = 'not_retained' AND status IN ('scheduled','held') AND created_at >= NOW() - INTERVAL 10 MINUTE");
    $held = $recent + 1 > CE_BATCH_LIMIT;
    q("INSERT INTO candidate_email_log (application_id, candidate_id, template_key, status, send_after) VALUES (?,?, 'not_retained', ?, DATE_ADD(NOW(), INTERVAL " . CE_REFUSAL_DELAY_HOURS . " HOUR))",
      [$applicationId, $cid, $held ? 'held' : 'scheduled']);
    if ($held) {
        $wasHeld = (int) val("SELECT COUNT(*) FROM candidate_email_log WHERE template_key = 'not_retained' AND status = 'held'") > 1;
        q("UPDATE candidate_email_log SET status = 'held' WHERE template_key = 'not_retained' AND status = 'scheduled' AND created_at >= NOW() - INTERVAL 10 MINUTE");
        if (! $wasHeld) {
            require_once __DIR__ . '/notices.php';
            notify_desks([], t('Many refusals at once: they are held, nothing is sent until you decide.'), '/candidate-emails#held');
        }
    }
}

/** P5-M104b: the held refusals - send them (each 24 hours after its rejection, at the earliest now), or never. */
function ce_held_count(): int
{
    return (int) val("SELECT COUNT(*) FROM candidate_email_log WHERE template_key = 'not_retained' AND status = 'held'");
}

function ce_release_held(bool $send): int
{
    $n = ce_held_count();
    q($send ? "UPDATE candidate_email_log SET status = 'scheduled' WHERE template_key = 'not_retained' AND status = 'held'"
            : "UPDATE candidate_email_log SET status = 'cancelled' WHERE template_key = 'not_retained' AND status = 'held'");

    return $n;
}

/** The due refusals, sent by the delivery job (and Send now). Returns how many were sent. */
function ce_due(): int
{
    $sent = 0;
    foreach (rows("SELECT l.id, l.application_id, a.stage FROM candidate_email_log l JOIN applications a ON a.id = l.application_id
                   WHERE l.status = 'scheduled' AND l.send_after <= NOW() ORDER BY l.id LIMIT 200") as $r) {
        if ($r['stage'] !== 'rejected') {
            q("UPDATE candidate_email_log SET status = 'cancelled' WHERE id = ?", [(int) $r['id']]);
            continue;
        }
        $sent += ce_send((int) $r['application_id'], 'not_retained') ? 1 : 0;
    }

    return $sent;
}

/** Called wherever an application's stage changes. */
function ce_on_stage(int $applicationId): void
{
    $stage = (string) val('SELECT stage FROM applications WHERE id = ?', [$applicationId]);
    if (in_array($stage, ['screening', 'interview'], true)) {
        // Under review is news only before the applicant was retained.
        if (! row("SELECT id FROM candidate_email_log WHERE application_id = ? AND template_key = 'retained' AND status = 'sent'", [$applicationId])) {
            ce_send($applicationId, 'in_review');
        }
    } elseif (in_array($stage, ['offered', 'accepted'], true)) {
        ce_send($applicationId, 'retained');
    } elseif ($stage === 'rejected') {
        ce_schedule_refusal($applicationId);
    }
}
