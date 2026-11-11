<?php

namespace BTB\SiteBackup\Storage;

use BTB\SiteBackup\Support\Paths;
use BTB\SiteBackup\Support\State;

defined('ABSPATH') || exit;

/**
 * Nettoyage : supprime du stockage les fichiers qui ne sont plus utilisés par aucune sauvegarde,
 * ainsi que les restes d'opérations interrompues.
 */
final class GarbageCollector
{
    /** @var Paths */
    private $paths;
    /** @var BackupRepository */
    private $repository;
    /** @var State */
    private $state;
    /** @var ObjectStore */
    private $store;

    public function __construct(Paths $paths, BackupRepository $repository, State $state, ObjectStore $store)
    {
        $this->paths      = $paths;
        $this->repository = $repository;
        $this->state      = $state;
        $this->store      = $store;
    }

    public function run(): void
    {
        // Jamais pendant une opération : elle a pu copier des fichiers pas encore référencés.
        if ($this->state->busy()) {
            $this->state->set(['gc_pending' => true]);
            return;
        }
        $used = [];
        foreach ($this->repository->all() as $b) {
            foreach (glob($this->paths->backupPath($b['id']) . '/index-*.txt') ?: [] as $file) {
                $h = fopen($file, 'rb');
                while (false !== ($line = fgets($h))) {
                    $used[substr($line, 0, 40)] = true;
                }
                fclose($h);
            }
        }
        $this->store->each(function ($name, $path) use ($used) {
            if (!isset($used[$name])) {
                @unlink($path);
            }
        });
        $this->store->purgeTemporary();
        foreach (glob($this->paths->backupDir() . '/*.tmp', GLOB_ONLYDIR) ?: [] as $tmp) {
            $this->paths->removeDir($tmp);
        }
        $this->state->set(['gc_pending' => false]);
    }
}
