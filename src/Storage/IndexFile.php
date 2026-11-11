<?php

namespace BTB\SiteBackup\Storage;

use BTB\SiteBackup\Config;

defined('ABSPATH') || exit;

/**
 * Index d'un composant de sauvegarde : une ligne par fichier,
 * « sha1 \t crc32 \t taille \t date \t chemin relatif ».
 */
final class IndexFile
{
    /**
     * Lit l'index ligne par ligne et appelle $fn(sha, crc, taille, date, chemin).
     * Avec $end, s'arrête après un lot (ou à l'échéance) et renvoie false : reprendre à $pos.
     */
    public static function read(string $file, callable $fn, int $from = 0, ?float $end = null, &$pos = null): bool
    {
        $h = fopen($file, 'rb');
        fseek($h, $from);
        $n = 0;
        while (false !== ($line = fgets($h))) {
            $p = explode("\t", rtrim($line, "\n"), 5);
            if (5 === count($p)) {
                $fn($p[0], $p[1], $p[2], $p[3], $p[4]);
            }
            $n++;
            if (null !== $end && ($n >= Config::BATCH || microtime(true) >= $end)) {
                $pos = ftell($h);
                fclose($h);
                return false;
            }
        }
        $pos = ftell($h);
        fclose($h);
        return true;
    }

    /** Découpe en parties de 1 Gio au plus (pour le téléchargement) : [['files' => n, 'bytes' => n], …]. */
    public static function parts(string $file): array
    {
        $parts = [];
        $cur   = ['files' => 0, 'bytes' => 0];
        self::read($file, function ($sha, $crc, $size) use (&$parts, &$cur) {
            $size = (int) $size;
            if ($cur['files'] > 0 && $cur['bytes'] + $size > Config::PART_MAX) {
                $parts[] = $cur;
                $cur     = ['files' => 0, 'bytes' => 0];
            }
            $cur['files']++;
            $cur['bytes'] += $size;
        });
        if ($cur['files'] > 0) {
            $parts[] = $cur;
        }
        return $parts;
    }

    /** Fichiers de la partie n° $part : [[sha, crc, taille, date, chemin], …]. */
    public static function partEntries(string $file, int $part): array
    {
        $entries = [];
        $n       = 1;
        $cur     = ['files' => 0, 'bytes' => 0];
        self::read($file, function ($sha, $crc, $size, $mtime, $rel) use (&$entries, &$n, &$cur, $part) {
            $s = (int) $size;
            if ($cur['files'] > 0 && $cur['bytes'] + $s > Config::PART_MAX) {
                $n++;
                $cur = ['files' => 0, 'bytes' => 0];
            }
            $cur['files']++;
            $cur['bytes'] += $s;
            if ($n === $part) {
                $entries[] = [$sha, $crc, $size, $mtime, $rel];
            }
        });
        return $entries;
    }
}
