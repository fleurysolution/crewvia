<?php
/**
 * The public job board.
 *
 * There was a feed for Indeed and a single-vacancy application page reached
 * by scanning a QR code, and nothing in between - so a person told "look at
 * their website" had nowhere to look, and a recruiter had no one link to put
 * in a text message or on a flyer.
 *
 * Open to anybody, no account. It shows only requisitions that are open on
 * projects that are running, and nothing about the client: a strike job is
 * confidential, and the page says what the work is, not who it is for.
 */

declare(strict_types=1);

// What an applicant is told is what was actually agreed for their trade:
// the requisition's own rate where it has one, otherwise the rate on its
// line of the scope of work. The old query fell back to a single rate on the
// project, so a labourer's advert quoted an engineer's hourly rate - a
// promise the agency could not keep and would be held to.
$openings = rows("SELECT v.id, v.title, v.description, v.discipline, v.openings,
                         v.shift, v.degree, v.years_experience, v.starts_on,
                         v.requirements, v.pay_rate, v.per_diem_rate, v.guarantee_hours,
                         j.site_city, j.site_state, j.starts_on AS job_starts,
                         j.description AS job_description, j.strike_live,
                         j.lodging_provided, j.travel_provided, j.transport_provided,
                         l.pay_rate AS line_pay, l.per_diem_rate AS line_diem,
                         l.guarantee_hours AS line_guarantee,
                         l.strike_guarantee_hours AS line_strike_guarantee
                  FROM vacancies v
                  JOIN jobs j ON j.id = v.job_id
                  LEFT JOIN job_order_lines l ON l.id = v.order_line_id
                  LEFT JOIN requisition_publication r ON r.vacancy_id = v.id
                  WHERE v.is_open = 1 AND j.status <> 'closed'
                    AND (r.expires_on IS NULL OR r.expires_on >= CURDATE())
                  GROUP BY v.id
                  ORDER BY v.id DESC
                  LIMIT 100");

$pageTitle = t('Open positions') . ' · ' . $config['app_name'];

render('careers', compact('openings'));
