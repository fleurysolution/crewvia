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
                    OR ($c.normalized_phone IS NOT NULL AND LENGTH($c.normalized_phone) >= 10 AND RIGHT(rb_o.normalized_phone, 10) = RIGHT($c.normalized_phone, 10)))
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
        return null;
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

    return t('On the do-not-rehire register (:label:how). Lift it on the record that carries it, with its reason, before going further.',
             ['label' => t($b['label']), 'how' => $b['how'] === 'record' ? '' : ($b['how'] === 'email' ? t(', same email as an earlier record') : t(', same phone as an earlier record'))]);
}
