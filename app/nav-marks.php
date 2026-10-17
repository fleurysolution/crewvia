<?php
/**
 * P5-M73: bookmarks and recent screens at the top of the left menu (RSS, 17 Oct 2026: "like QuickBooks -
 * your recently used, and your bookmarks").
 *
 * Each person bookmarks the screens they use most; the last screens they opened are listed under Recent.
 * Only screens of the menu the person may open are kept, so a link never leads to a refusal.
 */

declare(strict_types=1);

const NM_RECENT = 5;

/** The person's bookmarks or recent screens, newest first: [path => used_at]. */
function nm_list(int $userId, string $kind): array
{
    try {
        return array_column(rows('SELECT path, used_at FROM user_nav_marks WHERE user_id = ? AND kind = ? ORDER BY used_at DESC LIMIT 12', [$userId, $kind]), 'used_at', 'path');
    } catch (Throwable $e) {
        return [];
    }
}

/** A screen just opened goes to the top of Recent; the oldest beyond five drop off. */
function nm_touch(int $userId, string $path): void
{
    try {
        q("INSERT INTO user_nav_marks (user_id, kind, path, used_at) VALUES (?, 'recent', ?, NOW()) ON DUPLICATE KEY UPDATE used_at = NOW()", [$userId, $path]);
        $keep = array_keys(nm_list($userId, 'recent'));
        if (count($keep) > NM_RECENT) {
            q("DELETE FROM user_nav_marks WHERE user_id = ? AND kind = 'recent' AND path NOT IN (" . implode(',', array_fill(0, NM_RECENT, '?')) . ')',
              [$userId, ...array_slice($keep, 0, NM_RECENT)]);
        }
    } catch (Throwable $e) {
        // The menu never fails a page.
    }
}

/** Bookmarks the screen, or removes the bookmark. Returns true when it is now bookmarked. */
function nm_toggle(int $userId, string $path): bool
{
    if (val("SELECT COUNT(*) FROM user_nav_marks WHERE user_id = ? AND kind = 'bookmark' AND path = ?", [$userId, $path])) {
        q("DELETE FROM user_nav_marks WHERE user_id = ? AND kind = 'bookmark' AND path = ?", [$userId, $path]);
        return false;
    }
    q("INSERT INTO user_nav_marks (user_id, kind, path, used_at) VALUES (?, 'bookmark', ?, NOW())", [$userId, $path]);

    return true;
}
