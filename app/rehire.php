<?php
/**
 * P5-M51: the do-not-rehire register follows the person, not the record.
 *
 * The register (rehire_status "No rehire" or "DO NOT USE", with a reason,
 * who and when; set on the candidate's record or at separation) used to
 * hold only on the record it was set on. Somebody blocked who applied again
 * (a job poster's QR code, a job board import, an email added by hand) came
 * in as a new record, and nothing said so. Identity is still never merged
 * automatically (walkthrough finding 21): the new application is matched to
 * the register by the same email, or the same phone (its last ten digits,
 * so +1 and the area code compare), and held the same way.
 *
 * Held means: shown in red wherever the application appears, and not moved
 * forward - no Screening, Interview or Offer, no manager review, no
 * interview, no portal invitation. Rejecting stays possible; lifting the
 * block is done on the record that carries it, with its reason.
 */

declare(strict_types=1);

/**
 * The SQL that finds the register entry holding a candidate, for a query on
 * candidates aliased $c: the status, or NULL.
 */
function rehire_block_sql(string $c = 'c'): string
{
    return "(SELECT rb_ep.rehire_status FROM employee_profiles rb_ep JOIN candidates rb_o ON rb_o.id = rb_ep.candidate_id
             WHERE rb_ep.rehire_status IN ('ineligible','do_not_use')
               AND (rb_o.id = $c.id
                    OR ($c.normalized_email IS NOT NULL AND $c.normalized_email <> '' AND rb_o.normalized_email = $c.normalized_email)
                    OR ($c.normalized_phone IS NOT NULL AND LENGTH($c.normalized_phone) >= 10 AND RIGHT(rb_o.normalized_phone, 10) = RIGHT($c.normalized_phone, 10))
                    OR EXISTS (SELECT 1 FROM candidate_identity rb_mi JOIN candidate_identity rb_oi ON rb_oi.candidate_id = rb_o.id
                               WHERE rb_mi.candidate_id = $c.id AND (rb_oi.ssn_hash = rb_mi.ssn_hash OR (rb_oi.birth_date = rb_mi.birth_date AND rb_oi.name_key = rb_mi.name_key))))
             ORDER BY rb_o.id = $c.id DESC, rb_ep.rehire_status = 'do_not_use' DESC LIMIT 1)";
}

/**
 * The register entry holding this candidate, or null.
 *
 * @return array{status:string,label:string,reason:?string,by:?string,at:?string,record:int,how:string}|null
 */
function rehire_block_for(int $candidateId): ?array
{
    $c = row('SELECT id, normalized_email, normalized_phone FROM candidates WHERE id = ?', [$candidateId]);
    if (! $c) {
        return null;
    }
    $hit = row("SELECT ep.candidate_id, ep.rehire_status, ep.exclusion_reason, ep.excluded_at, u.name AS by_name, o.normalized_email, o.normalized_phone
                FROM employee_profiles ep JOIN candidates o ON o.id = ep.candidate_id LEFT JOIN users u ON u.id = ep.excluded_by
                WHERE ep.rehire_status IN ('ineligible','do_not_use')
                  AND (o.id = ? OR (? <> '' AND o.normalized_email = ?) OR (LENGTH(?) >= 10 AND RIGHT(o.normalized_phone, 10) = RIGHT(?, 10)))
                ORDER BY o.id = ? DESC, ep.rehire_status = 'do_not_use' DESC LIMIT 1",
               [$candidateId, (string) $c['normalized_email'], (string) $c['normalized_email'], (string) $c['normalized_phone'], (string) $c['normalized_phone'], $candidateId]);
    if (! $hit) {
        return rehire_block_by_identity($candidateId);
    }
    $how = (int) $hit['candidate_id'] === $candidateId ? 'record'
         : ((string) $c['normalized_email'] !== '' && $hit['normalized_email'] === $c['normalized_email'] ? 'email' : 'phone');

    return ['status' => (string) $hit['rehire_status'], 'label' => rehire_states()[(string) $hit['rehire_status']] ?? '',
            'reason' => $hit['exclusion_reason'], 'by' => $hit['by_name'], 'at' => $hit['excluded_at'],
            'record' => (int) $hit['candidate_id'], 'how' => $how];
}

/** Why an application may not move forward, or null. */
function rehire_refusal(int $candidateId): ?string
{
    $b = rehire_block_for($candidateId);
    if (! $b) {
        return null;
    }

    $how = ['record' => '', 'email' => t(', same email as an earlier record'), 'phone' => t(', same phone as an earlier record'),
            'ssn' => t(', same Social Security number as an earlier record'), 'name_dob' => t(', same name and date of birth as an earlier record')][$b['how']] ?? '';

    return t('On the do-not-rehire register (:label:how). Lift it on the record that carries it, with its reason, before going further.',
             ['label' => t($b['label']), 'how' => $how]) . ($b['reason'] ? ' ' . t('Why: :reason', ['reason' => $b['reason']]) : '');
}

/*
 * P5-M78: the register also follows the person when the email and the phone change (the owner, 17 Oct 2026:
 * "if it is do not hire, even if he changes his phone or email we should know, and also why").
 *
 * Once a W-4 or an I-9 is filled in, the person's Social Security number is kept as a keyed fingerprint (never
 * the number), with the date of birth and the name as written on the form. A record with the same fingerprint,
 * or the same name and date of birth, as a blocked one is held like a same email. The same name alone is not
 * enough to hold anybody - two people share a name - so it is shown to the recruiter to check.
 */

/** A name as compared: lower case, without accents or punctuation, single spaces. */
function identity_name_key(string $name): string
{
    if (class_exists('Normalizer')) {
        $name = preg_replace('/\p{Mn}+/u', '', (string) Normalizer::normalize($name, Normalizer::FORM_D));
    }
    $n = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $name);
    // Transliteration marks (Jord'an, Jord^an) belong to the letter before them, not a word break.
    $n = preg_replace('/[\'`"^~]/', '', (string) ($n === false ? $name : $n));
    $n = strtolower(preg_replace('/[^A-Za-z ]+/', ' ', (string) $n));

    return trim(preg_replace('/\s+/', ' ', $n));
}

/** The keyed fingerprint of a Social Security number: comparable between records, not reversible without the key. */
function identity_ssn_hash(string $ssn): ?string
{
    global $config;
    $digits = preg_replace('/\D/', '', $ssn);
    if (strlen($digits) !== 9) {
        return null;
    }

    return hash_hmac('sha256', 'ssn|' . $digits, hash('sha256', 'crewvia-identity|' . (string) ($config['encryption_key'] ?? ''), true));
}

/** Notes the identity given on a tax form (its payload): the SSN fingerprint, the date of birth, the name. */
function identity_note(int $candidateId, array $p): void
{
    $hash = identity_ssn_hash((string) ($p['ssn'] ?? ''));
    $dob = preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string) ($p['birth_date'] ?? '')) ? (string) $p['birth_date'] : null;
    $name = identity_name_key(trim((string) ($p['first_name'] ?? '') . ' ' . (string) ($p['last_name'] ?? '')));
    if ($hash === null && $dob === null && $name === '') {
        return;
    }
    try {
        q('INSERT INTO candidate_identity (candidate_id, ssn_hash, birth_date, name_key) VALUES (?,?,?,?)
           ON DUPLICATE KEY UPDATE ssn_hash = COALESCE(VALUES(ssn_hash), ssn_hash), birth_date = COALESCE(VALUES(birth_date), birth_date),
                                   name_key = COALESCE(NULLIF(VALUES(name_key), \'\'), name_key)', [$candidateId, $hash, $dob, $name ?: null]);
    } catch (Throwable $e) {
        // Before the upgrade has run, a form is still saved.
    }
}

/** The register entry holding this candidate through their identity (same SSN, or same name and date of birth), or null. */
function rehire_block_by_identity(int $candidateId): ?array
{
    try {
        $me = row('SELECT ssn_hash, birth_date, name_key FROM candidate_identity WHERE candidate_id = ?', [$candidateId]);
    } catch (Throwable $e) {
        return null;
    }
    if (! $me) {
        return null;
    }
    $hit = row("SELECT ep.candidate_id, ep.rehire_status, ep.exclusion_reason, ep.excluded_at, u.name AS by_name, oi.ssn_hash
                FROM candidate_identity oi JOIN employee_profiles ep ON ep.candidate_id = oi.candidate_id LEFT JOIN users u ON u.id = ep.excluded_by
                WHERE oi.candidate_id <> ? AND ep.rehire_status IN ('ineligible','do_not_use')
                  AND ((? IS NOT NULL AND oi.ssn_hash = ?) OR (? IS NOT NULL AND ? IS NOT NULL AND oi.birth_date = ? AND oi.name_key = ?))
                ORDER BY oi.ssn_hash <=> ? DESC, ep.rehire_status = 'do_not_use' DESC LIMIT 1",
               [$candidateId, $me['ssn_hash'], $me['ssn_hash'], $me['birth_date'], $me['name_key'], $me['birth_date'], $me['name_key'], $me['ssn_hash']]);
    if (! $hit) {
        return null;
    }

    return ['status' => (string) $hit['rehire_status'], 'label' => rehire_states()[(string) $hit['rehire_status']] ?? '',
            'reason' => $hit['exclusion_reason'], 'by' => $hit['by_name'], 'at' => $hit['excluded_at'],
            'record' => (int) $hit['candidate_id'], 'how' => $me['ssn_hash'] !== null && $hit['ssn_hash'] === $me['ssn_hash'] ? 'ssn' : 'name_dob'];
}

/**
 * Blocked people with the same name as this candidate, not already holding them: shown to the recruiter to check.
 *
 * @return list<array{record:int,name:string,label:string,reason:?string}>
 */
function rehire_name_matches(int $candidateId): array
{
    // First and last name: a middle name or initial is often there on one record and not the other.
    $firstLast = static function (string $full): string { $w = explode(' ', identity_name_key($full)); return count($w) > 1 ? $w[0] . ' ' . end($w) : ''; };
    $name = $firstLast((string) val('SELECT full_name FROM candidates WHERE id = ?', [$candidateId]));
    if ($name === '') {
        return [];
    }
    $out = [];
    foreach (rows("SELECT c.id, c.full_name, ep.rehire_status, ep.exclusion_reason FROM employee_profiles ep JOIN candidates c ON c.id = ep.candidate_id
                   WHERE ep.rehire_status IN ('ineligible','do_not_use') AND c.id <> ? LIMIT 2000", [$candidateId]) as $b) {
        if ($firstLast((string) $b['full_name']) === $name) {
            $out[] = ['record' => (int) $b['id'], 'name' => (string) $b['full_name'], 'label' => rehire_states()[(string) $b['rehire_status']] ?? '', 'reason' => $b['exclusion_reason']];
        }
    }

    return $out;
}

/** Makes every identity fingerprint again from the forms, under the current key (after a key rotation). Returns the forms read. */
function identity_rebuild(): int
{
    require_once __DIR__ . '/tax-forms.php';
    $n = 0;
    try {
        foreach (rows('SELECT * FROM worker_tax_forms ORDER BY id') as $f) {
            if ($p = tf_payload($f)) {
                identity_note((int) $f['candidate_id'], $p);
                $n++;
            }
        }
    } catch (Throwable $e) {
        return $n;
    }

    return $n;
}
