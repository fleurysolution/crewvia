<?php
/**
 * What somebody is employed as, and since when.
 *
 * employee_profiles.employment_type is the value the hours, the payroll
 * export and the salaried check all read. Before this, changing it was a
 * silent UPDATE: no date, no reason, no record of what it had been - and
 * it decides whether a week is paid by the hour or as a salary.
 *
 * Here a change is a row with an effective date and a reason, and the
 * profile follows the newest row. Two rules keep the record readable:
 *
 *   - no future date. A scheduled change would leave the profile saying
 *     one thing and the history another until somebody remembered.
 *   - no date before the latest change. Inserting into the middle of the
 *     history rewrites what was true when weeks were approved.
 *
 * The overtime status (FLSA) is held beside it because the next module's
 * overtime rules cannot be chosen without it, and nothing recorded it.
 */

declare(strict_types=1);

/** The kinds of employment, in words. One list, for every screen. */
function employment_types(): array
{
    return [
        'hourly'     => t('Hourly employee'),
        'salaried'   => t('Salaried employee'),
        'contractor' => t('Independent contractor'),
        'external'   => t('Employed by another company'),
    ];
}

/** Whether overtime law applies to the person, in words. */
function flsa_statuses(): array
{
    return [
        'non_exempt'     => t('Overtime applies (non-exempt)'),
        'exempt'         => t('Exempt from overtime'),
        'not_applicable' => t('Not an employee of the agency'),
        'not_determined' => t('Not yet determined'),
    ];
}

/**
 * The status a classification starts with when nobody has said.
 *
 * Hourly pay is the ordinary non-exempt case. A contractor or somebody
 * else's employee is not ours to classify. A salary alone does not make
 * anybody exempt - that depends on duties and level - so it is left to a
 * person to decide.
 */
function flsa_default(string $type): string
{
    return match ($type) {
        'hourly'                  => 'non_exempt',
        'contractor', 'external'  => 'not_applicable',
        default                   => 'not_determined',
    };
}

/** Newest first. */
function classification_history(int $candidateId): array
{
    return rows('SELECT k.*, u.name AS recorded_by_name
                 FROM employee_classifications k
                 LEFT JOIN users u ON u.id = k.recorded_by
                 WHERE k.candidate_id = ?
                 ORDER BY COALESCE(k.effective_from, \'1000-01-01\') DESC, k.id DESC',
                [$candidateId]);
}

/**
 * The classification that held on a date.
 *
 * Falls back to the profile for a person with no history at all, which is
 * every profile created by a path that has not been taught to write one.
 *
 * @return array{employment_type:string,flsa_status:string,effective_from:?string,source:string}
 */
function classification_on(int $candidateId, ?string $date = null): array
{
    $date ??= date('Y-m-d');

    $found = row('SELECT employment_type, flsa_status, effective_from
                  FROM employee_classifications
                  WHERE candidate_id = ? AND (effective_from IS NULL OR effective_from <= ?)
                  ORDER BY COALESCE(effective_from, \'1000-01-01\') DESC, id DESC
                  LIMIT 1', [$candidateId, $date]);

    if ($found) {
        return $found + ['source' => 'history'];
    }

    $profile = row('SELECT employment_type, flsa_status FROM employee_profiles
                    WHERE candidate_id = ?', [$candidateId]);

    return [
        'employment_type' => (string) ($profile['employment_type'] ?? 'hourly'),
        'flsa_status'     => (string) ($profile['flsa_status'] ?? 'not_determined'),
        'effective_from'  => null,
        'source'          => 'profile',
    ];
}

/**
 * The first row for a person whose profile is created knowing its type -
 * an import, or the backfill for profiles that predate this history.
 */
function classification_record_initial(int $candidateId, string $type, string $reason,
                                       ?int $userId = null, ?string $effective = null): void
{
    // A literal list, not employment_types(): this also runs from the
    // command-line upgrade, where nothing needs translating.
    if (! in_array($type, ['hourly', 'salaried', 'contractor', 'external'], true)) {
        return;
    }

    if (val('SELECT COUNT(*) FROM employee_classifications WHERE candidate_id = ?', [$candidateId])) {
        return;
    }

    $flsa = flsa_default($type);

    q('INSERT INTO employee_classifications
         (candidate_id, employment_type, flsa_status, effective_from, reason, recorded_by)
       VALUES (?,?,?,?,?,?)',
      [$candidateId, $type, $flsa, $effective, mb_substr($reason, 0, 500), $userId]);

    q('UPDATE employee_profiles SET flsa_status = ? WHERE candidate_id = ?', [$flsa, $candidateId]);
}

/**
 * Record a change. Returns the reason it was refused, or null.
 *
 * The caller owns the transaction and the permission check; this owns
 * the rules.
 */
function classification_change(int $candidateId, string $type, string $flsa,
                               string $effective, string $reason, int $userId): ?string
{
    if (! array_key_exists($type, employment_types())) {
        return t('Choose a kind of employment from the list.');
    }

    if (! array_key_exists($flsa, flsa_statuses())) {
        return t('Choose an overtime status from the list.');
    }

    // Somebody the agency does not employ has no overtime status with it,
    // and an employee always has one, even if it is still to be decided.
    $outside = in_array($type, ['contractor', 'external'], true);

    if ($outside !== ($flsa === 'not_applicable')) {
        return $outside
            ? t('A contractor or another company\'s employee is not classified for overtime by the agency.')
            : t('An employee of the agency needs an overtime status, even if it is not yet determined.');
    }

    if (! valid_date($effective)) {
        return t('Give the date the change takes effect.');
    }

    if ($effective > date('Y-m-d')) {
        return t('A change cannot take effect in the future. Record it on the day it applies.');
    }

    $reason = trim($reason);

    if (mb_strlen($reason) < 3) {
        return t('Say why the classification changed.');
    }

    if (mb_strlen($reason) > 500) {
        return t('That reason is too long.');
    }

    $latest = val('SELECT MAX(effective_from) FROM employee_classifications WHERE candidate_id = ?',
                  [$candidateId]);

    if ($latest && $effective < $latest) {
        return t('The last change took effect on :date. A new one cannot take effect before it.',
                 ['date' => d((string) $latest)]);
    }

    $current = classification_on($candidateId);

    if ($current['employment_type'] === $type && $current['flsa_status'] === $flsa) {
        return t('That is already the classification on record.');
    }

    // A person whose profile predates the history gets the value it held
    // written down first, so the record shows what it changed from.
    if ($current['source'] === 'profile') {
        q('INSERT INTO employee_classifications
             (candidate_id, employment_type, flsa_status, effective_from, reason, recorded_by)
           VALUES (?,?,?,NULL,?,NULL)',
          [$candidateId, $current['employment_type'], $current['flsa_status'],
           'Held before classification history was kept.']);
    }

    q('INSERT INTO employee_classifications
         (candidate_id, employment_type, flsa_status, effective_from, reason, recorded_by)
       VALUES (?,?,?,?,?,?)',
      [$candidateId, $type, $flsa, $effective, $reason, $userId]);

    q('UPDATE employee_profiles SET employment_type = ?, flsa_status = ? WHERE candidate_id = ?',
      [$type, $flsa, $candidateId]);

    return null;
}
