<?php
/**
 * Three languages, chosen per person.
 *
 * Every Fleury Solutions system must work in English, French and Spanish.
 * This file is the whole mechanism; the words themselves live in lang/.
 *
 * Design decisions worth knowing before changing anything here:
 *
 * The key IS the English sentence. Not a code like 'nav.hotels'. A view that
 * reads t('Needs a bed') still says what it means when somebody greps for it,
 * a missing translation degrades to correct English rather than to a dotted
 * identifier leaking onto a customer's screen, and nobody has to invent a
 * naming scheme to add a string.
 *
 * Resolution order is: an explicit URL choice, the saved account choice,
 * the visitor's explicit session choice, then English. Browser language
 * does not override the product's English default.
 * A person who sets French once gets French on every device.
 *
 * Interpolation is :named, never %s. A translator moving a placeholder around
 * a sentence cannot break it, and the order of arguments never has to match
 * the order of words - which it frequently cannot, across these three
 * languages.
 */

declare(strict_types=1);

const WORKFORCE_LOCALES = ['en' => 'English', 'fr' => 'Français', 'es' => 'Español'];

/** The locale in force for this request. */
function locale(): string
{
    static $resolved = null;

    if ($resolved !== null) {
        return $resolved;
    }

    $candidates = [];

    // 1. What this account chose, if anybody is signed in.
    $u = $_SESSION['user'] ?? null;

    if (! empty($u['locale'])) {
        $candidates[] = (string) $u['locale'];
    }

    // 2. ?lang= on the URL. Also used by the switcher in the header.
    if (! empty($_GET['lang'])) {
        array_unshift($candidates, (string) $_GET['lang']);
    }

    // English is the product default. Other languages require an explicit choice.
    if(!empty($_SESSION['locale'])) $candidates[]=(string)$_SESSION['locale'];

    foreach ($candidates as $c) {
        $c = strtolower(substr(trim($c), 0, 2));

        if (isset(WORKFORCE_LOCALES[$c])) {
            return $resolved = $c;
        }
    }

    return $resolved = 'en';
}

/**
 * Remember a locale against the account, so it survives the next sign-in.
 *
 * Called from the switcher. Silently does nothing for a visitor who is not
 * signed in - they still get the language for this request, it simply has
 * nowhere durable to live.
 */
function set_locale(string $want): bool
{
    $want = strtolower(substr(trim($want), 0, 2));

    if (! isset(WORKFORCE_LOCALES[$want])) {
        return false;
    }

    if (! empty($_SESSION['user']['id'])) {
        try {
            q('UPDATE users SET locale = ? WHERE id = ?', [$want, (int) $_SESSION['user']['id']]);
        } catch (Throwable $e) {
            // An installation whose upgrade has not run yet has no column.
            // The language still applies to this session; it just will not
            // be remembered, which is better than a fatal error.
            error_log('[i18n] could not save locale: ' . $e->getMessage());
        }
    }

    if(!empty($_SESSION['user']['id'])) $_SESSION['user']['locale'] = $want;
    $_SESSION['locale']=$want;

    return true;
}

/** The catalogue for a locale, loaded once. */
function lang_catalogue(string $loc): array
{
    static $loaded = [];

    if (isset($loaded[$loc])) {
        return $loaded[$loc];
    }

    $file = __DIR__ . '/lang/' . $loc . '.php';

    $catalogue=is_file($file)?(array)require $file:[];
    if(in_array($loc,['fr','es'],true)) {
        foreach(array_merge(file(__DIR__.'/lang/messages.tsv',FILE_IGNORE_NEW_LINES),file(__DIR__.'/lang/remaining-views.tsv',FILE_IGNORE_NEW_LINES)) as $line) {
            $columns=explode("\t",$line);
            if(count($columns)!==3)throw new RuntimeException('Invalid message catalogue.');
            $catalogue[$columns[0]]=$columns[$loc==='fr'?1:2];
        }
    }
    foreach($catalogue as $key=>$value) {
        $decoded=html_entity_decode((string)$key,ENT_QUOTES|ENT_HTML5,'UTF-8');
        if(!array_key_exists($decoded,$catalogue))$catalogue[$decoded]=$value;
    }
    return $loaded[$loc]=$catalogue;
}

/**
 * Translate.
 *
 *   t('Hotels')
 *   t('Room :room already has :name in it.', ['room' => 212, 'name' => 'Alan'])
 *
 * An unknown string returns itself. That is the point: an untranslated screen
 * is an English screen, not a broken one.
 */
function t(string $text, array $vars = []): string
{
    $loc = locale();

    if ($loc !== 'en') {
        $catalogue = lang_catalogue($loc);
        $text = $catalogue[$text] ?? $text;
    }

    if ($vars === []) {
        return $text;
    }

    $find = $replace = [];

    foreach ($vars as $k => $v) {
        $find[]    = ':' . $k;
        $replace[] = (string) $v;
    }

    return str_replace($find, $replace, $text);
}

/** Translate and escape - what a view almost always wants. */
/**
 * A count of people, written the way a person would say it.
 *
 * "1 people" on a settings screen reads like a draft. Each form is its own
 * catalogue entry, which is also what languages with other plural rules need.
 */
function people(int $n): string
{
    return $n === 1 ? t('1 person') : t(':n people', ['n' => $n]);
}

function te(string $text, array $vars = []): string
{
    // Decode catalogue entities before escaping exactly once. Never decode variables.
    return e(t(html_entity_decode($text,ENT_QUOTES|ENT_HTML5,'UTF-8'),$vars));
}

/**
 * A date in the reader's language.
 *
 * IntlDateFormatter where the extension is available, and an explicit table
 * where it is not: shared cPanel hosting frequently lacks intl, and a date
 * reading "Lundi 13 October" is worse than one reading "Monday 13 October".
 */
function d(?string $date, string $pattern = 'j M Y'): string
{
    if (! $date) {
        return '—';
    }

    $ts  = is_numeric($date) ? (int) $date : strtotime($date);

    if ($ts === false) {
        return '—';
    }

    $loc = locale();

    if ($loc === 'en') {
        return date($pattern, $ts);
    }

    $months = [
        'fr' => ['Jan','Fév','Mar','Avr','Mai','Juin','Juil','Août','Sep','Oct','Nov','Déc'],
        'es' => ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'],
    ];

    $days = [
        'fr' => ['Dim','Lun','Mar','Mer','Jeu','Ven','Sam'],
        'es' => ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'],
    ];

    $out = date($pattern, $ts);

    // Replace the English names the format produced with local ones. Longest
    // first so "March" is not half-eaten by a match on "Mar".
    $enMonthsLong  = ['January','February','March','April','May','June','July',
                      'August','September','October','November','December'];
    $enMonthsShort = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    $enDaysLong    = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    $enDaysShort   = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

    foreach ($enDaysLong as $i => $name) {
        $out = str_replace($name, $days[$loc][$i], $out);
    }

    foreach ($enMonthsLong as $i => $name) {
        $out = str_replace($name, $months[$loc][$i], $out);
    }

    foreach ($enDaysShort as $i => $name) {
        $out = str_replace($name, $days[$loc][$i], $out);
    }

    foreach ($enMonthsShort as $i => $name) {
        $out = str_replace($name, $months[$loc][$i], $out);
    }

    return $out;
}

/**
 * Money in the reader's convention.
 *
 * The amount does not change - these are US dollars on a US payroll whoever
 * is reading - but 1 234,56 $ is what a French reader expects to see, and a
 * figure punctuated the wrong way gets misread.
 */
function money_local($v): string
{
    $n = (float) $v;

    return match (locale()) {
        'fr' => number_format($n, 2, ',', ' ') . ' $',
        'es' => '$' . number_format($n, 2, ',', '.'),
        default => '$' . number_format($n, 2),
    };
}
