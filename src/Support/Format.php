<?php

namespace BTB\SiteBackup\Support;

defined('ABSPATH') || exit;

/**
 * Mise en forme : identifiants de sauvegarde (date UTC « AAAA-MM-JJ_HHMMSS ») et dates affichées.
 */
final class Format
{
    public static function idTimestamp(string $id): ?int
    {
        $ts = \DateTime::createFromFormat('Y-m-d_His', $id, new \DateTimeZone('UTC'));
        return $ts ? $ts->getTimestamp() : null;
    }

    /** « 11/11/2026 09:19 », dans le fuseau horaire du site. */
    public static function idLabel(string $id): string
    {
        $ts = self::idTimestamp($id);
        return $ts ? wp_date('d/m/Y H:i', $ts) : $id;
    }

    /** « 11/11/2026 à 09:19 », ou « — ». */
    public static function date(int $ts): string
    {
        return $ts ? wp_date('d/m/Y à H:i', $ts) : '—';
    }
}
