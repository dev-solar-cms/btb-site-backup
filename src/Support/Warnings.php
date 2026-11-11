<?php

namespace BTB\SiteBackup\Support;

use BTB\SiteBackup\Config;

defined('ABSPATH') || exit;

/**
 * Avertissements classés par gravité, accumulés dans l'état d'une opération (sauvegarde ou restauration).
 */
final class Warnings
{
    /** Ajoute un avertissement à $holder['warnings'] et compte par niveau dans $holder['wcount']. */
    public static function add(array &$holder, string $code, string $path, ?string $level = null): void
    {
        $level = $level ?: Config::CODES[$code][0];
        if (!isset($holder['wcount'])) {
            $holder['wcount'] = [];
        }
        $holder['wcount'][$level] = ($holder['wcount'][$level] ?? 0) + 1;
        if (!isset($holder['warnings'])) {
            $holder['warnings'] = [];
        }
        if (count($holder['warnings']) < Config::MAX_WARN) {
            $holder['warnings'][] = [$level, $code, $path];
        }
    }

    public static function counts(array $warnings): array
    {
        $c = [];
        foreach ($warnings as $w) {
            $c[$w[0]] = ($c[$w[0]] ?? 0) + 1;
        }
        return $c;
    }

    /** Niveau le plus grave présent : [libellé, couleur], ou null. */
    public static function worst(array $counts): ?array
    {
        foreach (Config::LEVELS as $lvl => $info) {
            if (!empty($counts[$lvl])) {
                return $info;
            }
        }
        return null;
    }

    /** Statut global : « warning » si au moins un avertissement moyen ou élevé. */
    public static function status(array $counts): string
    {
        return (!empty($counts['eleve']) || !empty($counts['moyen'])) ? 'warning' : 'ok';
    }

    /** Regroupe par niveau puis par type : [niveau => [code => [chemins]]]. */
    public static function group(array $warnings): array
    {
        $groups = [];
        foreach ($warnings as $w) {
            $groups[$w[0]][$w[1]][] = $w[2];
        }
        return $groups;
    }

    /** Résumé lisible : « 2 moyen, 35 faible ». */
    public static function summary(array $counts): string
    {
        $parts = [];
        foreach (Config::LEVELS as $lvl => $info) {
            if (!empty($counts[$lvl])) {
                $parts[] = $counts[$lvl] . ' ' . mb_strtolower($info[0]);
            }
        }
        return implode(', ', $parts);
    }
}
