<?php

namespace BTB\SiteBackup\Storage;

use BTB\SiteBackup\Config;
use BTB\SiteBackup\Support\Paths;

defined('ABSPATH') || exit;

/**
 * Sauvegardes terminées : liste, lecture, protection, suppression, avertissements.
 */
final class BackupRepository
{
    /** @var Paths */
    private $paths;

    public function __construct(Paths $paths)
    {
        $this->paths = $paths;
    }

    /** Toutes les sauvegardes terminées, de la plus récente à la plus ancienne. */
    public function all(): array
    {
        $out = [];
        foreach (glob($this->paths->backupDir() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $id = basename($dir);
            if (!preg_match(Config::ID_RE, $id) || !is_file($dir . '/manifest.json')) {
                continue;
            }
            $m = json_decode((string) file_get_contents($dir . '/manifest.json'), true);
            if (is_array($m)) {
                $m['id'] = $id;
                $out[]   = $m;
            }
        }
        usort($out, function ($a, $b) {
            return strcmp($b['id'], $a['id']);
        });
        return $out;
    }

    public function get(string $id): ?array
    {
        foreach ($this->all() as $b) {
            if ($b['id'] === $id) {
                return $b;
            }
        }
        return null;
    }

    /** Identifiant valide et sauvegarde existante (contrôle des données envoyées par un formulaire). */
    public function exists(string $id): bool
    {
        return (bool) preg_match(Config::ID_RE, $id) && is_file($this->paths->backupPath($id) . '/manifest.json');
    }

    public function indexFile(string $id, string $comp): string
    {
        return $this->paths->backupPath($id) . '/index-' . $comp . '.txt';
    }

    public function dumpFile(string $id): string
    {
        return $this->paths->backupPath($id) . '/database.sql.gz';
    }

    public function delete(string $id): void
    {
        $this->paths->removeDir($this->paths->backupPath($id));
    }

    public function setLocked(string $id, bool $locked): void
    {
        $file = $this->paths->backupPath($id) . '/manifest.json';
        $m    = json_decode((string) file_get_contents($file), true);
        $m['locked'] = $locked;
        file_put_contents($file, wp_json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** Avertissements détaillés d'une sauvegarde (avec conversion de l'ancien format). */
    public function warnings(array $b): array
    {
        $file = $this->paths->backupPath($b['id']) . '/warnings.json';
        if (is_file($file)) {
            $w = json_decode((string) file_get_contents($file), true);
            return is_array($w) ? $w : [];
        }
        $out = [];
        foreach ((array) ($b['errors'] ?? []) as $e) {
            if (preg_match('/^Fichier illisible : (.+)$/', $e, $m)) {
                $out[] = ['faible', 'legacy', $m[1]];
            } elseif (preg_match('/^Table ignorée : (.+)$/', $e, $m)) {
                $out[] = ['eleve', 'table', $m[1]];
            } else {
                $out[] = ['moyen', 'unreadable', $e];
            }
        }
        return $out;
    }

    /** Espace occupé par les dumps de base de données. */
    public function dumpsSize(): int
    {
        $bytes = 0;
        foreach (glob($this->paths->backupDir() . '/*/database.sql.gz') ?: [] as $f) {
            $bytes += (int) filesize($f);
        }
        return $bytes;
    }
}
