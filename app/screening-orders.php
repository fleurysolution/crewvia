<?php
/**
 * P5-M96: the background check and the drug test, sent and received from Hiring.
 *
 * RSS orders its background checks from HireRight and its drug tests from
 * Quest Diagnostics. The provider collects the candidate's consent itself
 * (its own email and sign-in), so a check sent from here needs no portal
 * authorization. HR records two moments:
 *
 *   1. Sent - the order is placed with the provider (who, when);
 *   2. Received - the result: Pass, Fail, or Needs review.
 *
 * Both providers have APIs. Once their credentials and documentation are in
 * hand, "Send" places the order through the API and the result comes back by
 * itself; until then both moments are recorded here by hand. Nothing in this
 * file pretends to call an API it has not been given.
 */

declare(strict_types=1);

/** @return array<string,string> kind => provider */
function screening_providers(): array
{
    return ['background' => 'HireRight', 'drug' => 'Quest Diagnostics'];
}

/** @return array<string,string> the result HR records => the case's status */
function screening_results(): array
{
    return ['pass' => 'cleared', 'fail' => 'not_cleared', 'review' => 'needs_review'];
}

/** The open (or last) case of one kind on a placement. */
function screening_case(int $placementId, string $kind): ?array
{
    return row("SELECT * FROM screening_cases WHERE placement_id = ? AND kind = ? AND status <> 'cancelled' ORDER BY id DESC LIMIT 1", [$placementId, $kind]);
}

/** Sent: the order is placed with the provider. @return ?string an error */
function screening_send(int $placementId, string $kind, int $by): ?string
{
    $providers = screening_providers();
    if (! isset($providers[$kind])) {
        return t('Choose the background check or the drug test.');
    }
    $case = screening_case($placementId, $kind);
    if ($case && in_array($case['status'], ['sent', 'scheduled', 'completed', 'needs_review'], true)) {
        return t('Already sent: record the result when it comes back.');
    }
    if ($case && $case['status'] === 'cleared') {
        return t('Already passed.');
    }
    if ($case && $case['status'] === 'pending') {
        q("UPDATE screening_cases SET status = 'sent', provider_name = ?, sent_at = NOW(), sent_by = ? WHERE id = ?", [$providers[$kind], $by, (int) $case['id']]);
        $id = (int) $case['id'];
    } else {
        q("INSERT INTO screening_cases (placement_id, kind, provider_name, delivery_mode, status, payer, sent_at, sent_by) VALUES (?,?,?, 'agency', 'sent', 'agency', NOW(), ?)",
          [$placementId, $kind, $providers[$kind], $by]);
        $id = (int) db()->lastInsertId();
    }
    log_activity('sent a screening to the provider', 'case', $id, $kind . ' · ' . $providers[$kind]);

    return null;
}

/** Received: the result. @return ?string an error */
function screening_receive(int $placementId, string $kind, string $result, int $by): ?string
{
    $status = screening_results()[$result] ?? null;
    if ($status === null) {
        return t('Choose Pass, Fail or Needs review.');
    }
    $case = screening_case($placementId, $kind);
    if (! $case || ! in_array($case['status'], ['sent', 'scheduled', 'completed', 'needs_review'], true)) {
        return t('Send it first: the result is recorded once it has been sent.');
    }
    q('UPDATE screening_cases SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?', [$status, $by, (int) $case['id']]);
    log_activity('recorded a screening result', 'case', (int) $case['id'], $kind . ' · ' . $result);
    if ($status === 'cleared') {
        require_once __DIR__ . '/deployment.php';
        deployment_ready_notice((int) val('SELECT candidate_id FROM placements WHERE id = ?', [$placementId]));
    }

    return null;
}
