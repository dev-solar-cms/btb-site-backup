<?php

namespace BTB\SiteBackup\Restore;

use BTB\SiteBackup\Config;
use BTB\SiteBackup\Storage\IndexFile;
use BTB\SiteBackup\Support\Paths;
use BTB\SiteBackup\Support\Warnings;

defined('ABSPATH') || exit;

/**
 * Restauration des fichiers : remet chaque fichier à l'identique (seuls ceux qui diffèrent sont réécrits,
 * de façon atomique), puis supprime éventuellement les fichiers apparus depuis la sauvegarde.
 * Le fichier .env et l'extension elle-même ne sont jamais touchés.
 */
final class FileRestorer
{
    /** @var Paths */
    private $paths;

    public function __construct(Paths $paths)
    {
        $this->paths = $paths;
    }

    /** Traite un lot du composant en cours. Renvoie true quand tous les composants sont faits. */
    public function restore(array &$r, float $end): bool
    {
        if ($r['ci'] >= count($r['comps'])) {
            return true;
        }
        $c    = $r['comps'][$r['ci']];
        $idx  = $this->paths->backupPath($r['id']) . '/index-' . $c . '.txt';
        $root = $this->paths->projectRoot();
        $pos  = 0;
        $done = !is_file($idx) || IndexFile::read($idx, function ($sha, $crc, $size, $mtime, $rel) use (&$r, $root) {
            $this->restoreOne($r, $root, $sha, (int) $size, (int) $mtime, $rel);
        }, (int) $r['idx_pos'], $end, $pos);
        $r['idx_pos'] = $pos;
        if ($done) {
            $r['ci']++;
            $r['idx_pos'] = 0;
        }
        return $r['ci'] >= count($r['comps']);
    }

    private function restoreOne(array &$r, string $root, string $sha, int $size, int $mtime, string $rel): void
    {
        if ($this->paths->isProtected($rel)) {
            return;
        }
        $target = $root . '/' . $rel;
        if (is_file($target) && filesize($target) === $size && filemtime($target) === $mtime) {
            $r['stats']['same']++;
            return;
        }
        $obj = $this->paths->objPath($sha);
        if (!is_file($obj)) {
            Warnings::add($r, 'missing_obj', $rel);
            return;
        }
        $dir = dirname($target);
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            Warnings::add($r, 'restore_write', $rel);
            return;
        }
        $tmp = $target . '.btbtmp';
        if (!@copy($obj, $tmp) || !@rename($tmp, $target)) {
            @unlink($tmp);
            Warnings::add($r, 'restore_write', $rel);
            return;
        }
        @touch($target, $mtime);
        $r['stats']['written']++;
    }

    /** Supprime les fichiers apparus depuis la sauvegarde (dans les éléments restaurés uniquement). */
    public function deleteAdded(array &$r): void
    {
        $keep = [];
        foreach ($r['comps'] as $c) {
            $idx = $this->paths->backupPath($r['id']) . '/index-' . $c . '.txt';
            if (is_file($idx)) {
                IndexFile::read($idx, function ($sha, $crc, $size, $mtime, $rel) use (&$keep) {
                    $keep[$rel] = true;
                });
            }
        }
        $prefixes = $this->paths->componentPrefixes();
        $wanted   = array_flip($r['comps']);
        $root     = $this->paths->projectRoot();
        $this->paths->walk(function ($rel) use (&$r, $keep, $prefixes, $wanted, $root) {
            if (isset($keep[$rel]) || $this->paths->isProtected($rel) || !isset($wanted[$this->paths->componentOf($rel, $prefixes)])) {
                return;
            }
            if (@unlink($root . '/' . $rel)) {
                $r['stats']['deleted']++;
                if (count($r['deleted']) < Config::MAX_WARN) {
                    $r['deleted'][] = $rel;
                }
            } else {
                Warnings::add($r, 'restore_delete', $rel);
            }
        });
    }
}
