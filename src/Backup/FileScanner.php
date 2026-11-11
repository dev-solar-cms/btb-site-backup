<?php

namespace BTB\SiteBackup\Backup;

use BTB\SiteBackup\Support\Paths;
use BTB\SiteBackup\Support\Warnings;

defined('ABSPATH') || exit;

/**
 * Inventaire des fichiers : une liste « taille \t date \t chemin » par composant.
 */
final class FileScanner
{
    /** @var Paths */
    private $paths;

    public function __construct(Paths $paths)
    {
        $this->paths = $paths;
    }

    public function scan(array &$job, string $dir): void
    {
        $prefixes = $this->paths->componentPrefixes();
        $wanted   = array_flip($job['comps']);
        $out      = [];
        foreach ($job['comps'] as $c) {
            $out[$c]           = fopen($dir . '/list-' . $c . '.txt', 'wb');
            $job['totals'][$c] = ['count' => 0, 'bytes' => 0];
        }
        $this->paths->walk(function ($rel, $f) use (&$job, &$out, $prefixes, $wanted) {
            if (strpbrk($rel, "\t\n\r") !== false) {
                Warnings::add($job, 'badname', $rel);
                return;
            }
            $comp = $this->paths->componentOf($rel, $prefixes);
            if (!isset($wanted[$comp])) {
                return;
            }
            $size = $f->getSize();
            fwrite($out[$comp], $size . "\t" . $f->getMTime() . "\t" . $rel . "\n");
            $job['totals'][$comp]['count']++;
            $job['totals'][$comp]['bytes'] += $size;
        });
        foreach ($out as $h) {
            fclose($h);
        }
    }
}
