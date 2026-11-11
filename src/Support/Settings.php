<?php

namespace BTB\SiteBackup\Support;

use BTB\SiteBackup\Config;

defined('ABSPATH') || exit;

/**
 * Réglages : délai entre deux sauvegardes, nombre conservé, éléments sauvegardés.
 */
final class Settings
{
    public function defaults(): array
    {
        return [
            'interval'   => Config::DEFAULT_INTERVAL,
            'keep'       => Config::DEFAULT_KEEP,
            'components' => array_keys(Config::COMPONENTS),
        ];
    }

    public function get(): array
    {
        $s = get_option(Config::OPT_SETTINGS);
        return $this->normalize(is_array($s) ? $s : []);
    }

    /** Enregistre des réglages saisis (valeurs bornées) et renvoie la version retenue. */
    public function save(array $input): array
    {
        $s = $this->normalize($input + ['components' => []]);
        update_option(Config::OPT_SETTINGS, $s, true);
        return $s;
    }

    /** Remet des réglages tels quels (après une restauration de la base). */
    public function restore(array $settings): void
    {
        update_option(Config::OPT_SETTINGS, $settings, true);
    }

    private function normalize(array $s): array
    {
        $comps = isset($s['components']) && is_array($s['components'])
            ? array_values(array_intersect(array_keys(Config::COMPONENTS), $s['components']))
            : array_keys(Config::COMPONENTS);
        return [
            'interval'   => isset($s['interval']) ? max(1, min(720, (int) $s['interval'])) : Config::DEFAULT_INTERVAL,
            'keep'       => isset($s['keep']) ? max(1, min(60, (int) $s['keep'])) : Config::DEFAULT_KEEP,
            'components' => $comps,
        ];
    }
}
