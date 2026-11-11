<?php

namespace BTB\SiteBackup\Support;

defined('ABSPATH') || exit;

/**
 * Verrou exclusif : une seule étape de travail à la fois.
 * Verrou MySQL (fiable même sur un stockage réseau, libéré automatiquement si le processus s'arrête),
 * avec repli sur un verrou de fichier.
 */
final class Lock
{
    /** @var Paths */
    private $paths;

    public function __construct(Paths $paths)
    {
        $this->paths = $paths;
    }

    /** @return array|null poignée du verrou, ou null s'il est déjà pris */
    public function acquire(): ?array
    {
        global $wpdb;
        $name = 'btb_backup_' . md5(DB_NAME . $wpdb->prefix);
        $got  = method_exists($wpdb, 'get_var') ? $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name)) : null;
        if ('1' === (string) $got) {
            return ['db', $name];
        }
        if ('0' === (string) $got) {
            return null;
        }
        $fh = @fopen($this->paths->lockFile(), 'c');
        if ($fh && flock($fh, LOCK_EX | LOCK_NB)) {
            return ['file', $fh];
        }
        return null;
    }

    public function release(array $lock): void
    {
        global $wpdb;
        if ('db' === $lock[0]) {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock[1]));
        } else {
            flock($lock[1], LOCK_UN);
            fclose($lock[1]);
        }
    }
}
