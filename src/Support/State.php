<?php

namespace BTB\SiteBackup\Support;

use BTB\SiteBackup\Config;
use BTB\SiteBackup\Storage\RestoreStateStore;

defined('ABSPATH') || exit;

/**
 * Statut de la dernière opération (option autoloadée, lue à chaque requête),
 * horodatages fréquents (option séparée) et jeton des requêtes internes.
 */
final class State
{
    /** @var RestoreStateStore */
    private $restore;

    public function __construct(RestoreStateStore $restore)
    {
        $this->restore = $restore;
    }

    public function defaults(): array
    {
        return [
            'running'     => false,
            'last_start'  => 0,
            'last_end'    => 0,
            'last_status' => '',
            'last_msg'    => '',
            'gc_pending'  => false,
        ];
    }

    /** @param bool $fresh ignorer le cache de la requête (une autre requête a pu modifier l'état) */
    public function get(bool $fresh = false): array
    {
        if ($fresh) {
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete(Config::OPT_STATE, 'options');
        }
        $s = get_option(Config::OPT_STATE);
        return array_merge($this->defaults(), is_array($s) ? $s : []);
    }

    /** Modifie le statut en relisant toujours la base avant d'écrire. */
    public function set(array $changes): void
    {
        update_option(Config::OPT_STATE, array_merge($this->get(true), $changes), true);
    }

    /** Une sauvegarde ou une restauration est-elle en cours ? */
    public function busy(): bool
    {
        return $this->get(true)['running'] || $this->restore->exists();
    }

    /** Horodatages fréquents (relance, dernier travail) : ne touchent jamais au statut. */
    public function tick(string $key): void
    {
        wp_cache_delete(Config::OPT_TICK, 'options');
        $t = get_option(Config::OPT_TICK);
        $t = is_array($t) ? $t : [];
        $t[$key] = time();
        update_option(Config::OPT_TICK, $t, false);
    }

    public function ticks(): array
    {
        $t = get_option(Config::OPT_TICK);
        return array_merge(['last_spawn' => 0, 'last_work' => 0], is_array($t) ? $t : []);
    }

    public function token(): string
    {
        $t = get_option(Config::OPT_TOKEN);
        if (!is_string($t) || strlen($t) < 32) {
            $t = wp_generate_password(48, false, false);
            update_option(Config::OPT_TOKEN, $t, false);
        }
        return $t;
    }
}
