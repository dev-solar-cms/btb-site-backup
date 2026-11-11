<?php

namespace BTB\SiteBackup\Storage;

use BTB\SiteBackup\Support\Paths;

defined('ABSPATH') || exit;

/**
 * État d'une restauration en cours, enregistré dans un fichier et non dans la base :
 * la base est justement remplacée pendant la restauration.
 */
final class RestoreStateStore
{
    /** @var Paths */
    private $paths;

    public function __construct(Paths $paths)
    {
        $this->paths = $paths;
    }

    public function exists(): bool
    {
        return is_file($this->paths->restoreFile());
    }

    public function get(): ?array
    {
        $file = $this->paths->restoreFile();
        if (!is_file($file)) {
            return null;
        }
        $r = json_decode((string) file_get_contents($file), true);
        return is_array($r) ? $r : null;
    }

    /** Écriture atomique (fichier temporaire puis renommage). */
    public function save(array $r): void
    {
        $file = $this->paths->restoreFile();
        file_put_contents($file . '.new', wp_json_encode($r));
        rename($file . '.new', $file);
    }

    public function delete(): void
    {
        @unlink($this->paths->restoreFile());
    }
}
