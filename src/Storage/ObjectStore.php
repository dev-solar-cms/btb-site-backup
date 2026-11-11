<?php

namespace BTB\SiteBackup\Storage;

use BTB\SiteBackup\Support\Paths;

defined('ABSPATH') || exit;

/**
 * Stockage dédupliqué : chaque contenu de fichier n'est enregistré qu'une fois, nommé par son SHA-1.
 */
final class ObjectStore
{
    /** @var Paths */
    private $paths;

    public function __construct(Paths $paths)
    {
        $this->paths = $paths;
    }

    public function path(string $sha): string
    {
        return $this->paths->objPath($sha);
    }

    public function has(string $sha): bool
    {
        return is_file($this->paths->objPath($sha));
    }

    /**
     * Copie un fichier dans le stockage, en calculant son SHA-1 et son CRC32 en une seule lecture.
     *
     * @param bool        $added vrai si le contenu n'existait pas encore dans le stockage
     * @param string|null $crc   CRC32 (hexadécimal), utilisé pour générer les ZIP sans relire les fichiers
     * @return string|null SHA-1, ou null si le fichier n'a pas pu être lu
     */
    public function store(string $abs, &$added, &$crc): ?string
    {
        $added = false;
        $crc   = null;
        $src   = @fopen($abs, 'rb');
        if (!$src) {
            return null;
        }
        $tmpDir = $this->paths->storeDir() . '/tmp';
        if (!is_dir($tmpDir)) {
            wp_mkdir_p($tmpDir);
        }
        $tmp = $tmpDir . '/' . str_replace('.', '', uniqid('', true));
        $dst = fopen($tmp, 'wb');
        if (!$dst) {
            fclose($src);
            throw new \RuntimeException('écriture impossible dans le stockage des sauvegardes');
        }
        $h = hash_init('sha1');
        $k = hash_init('crc32b');
        while (!feof($src)) {
            $buf = fread($src, 1048576);
            if (false === $buf) {
                break;
            }
            hash_update($h, $buf);
            hash_update($k, $buf);
            if ('' !== $buf && false === fwrite($dst, $buf)) {
                fclose($src);
                fclose($dst);
                @unlink($tmp);
                throw new \RuntimeException('espace disque insuffisant ou écriture impossible');
            }
        }
        fclose($src);
        fclose($dst);
        $sha = hash_final($h);
        $crc = hash_final($k);
        $obj = $this->paths->objPath($sha);
        if (is_file($obj)) {
            @unlink($tmp);
        } else {
            if (!is_dir(dirname($obj))) {
                wp_mkdir_p(dirname($obj));
            }
            if (!@rename($tmp, $obj)) {
                @unlink($tmp);
                throw new \RuntimeException('impossible d’enregistrer un fichier dans le stockage');
            }
            $added = true;
        }
        return $sha;
    }

    /** Parcourt les objets du stockage : $fn(nom, chemin complet). */
    public function each(callable $fn): void
    {
        foreach (glob($this->paths->storeDir() . '/[0-9a-f][0-9a-f]', GLOB_ONLYDIR) ?: [] as $dir) {
            foreach (scandir($dir) as $name) {
                if (40 === strlen($name)) {
                    $fn($name, $dir . '/' . $name);
                }
            }
        }
    }

    /** Supprime les copies temporaires laissées par une étape interrompue. */
    public function purgeTemporary(): void
    {
        foreach (glob($this->paths->storeDir() . '/tmp/*') ?: [] as $tmp) {
            @unlink($tmp);
        }
    }

    public function size(): int
    {
        $bytes = 0;
        $this->each(function ($name, $path) use (&$bytes) {
            $bytes += (int) @filesize($path);
        });
        return $bytes;
    }
}
