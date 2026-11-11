<?php

namespace BTB\SiteBackup\Runner;

use BTB\SiteBackup\Backup\BackupJob;
use BTB\SiteBackup\Restore\RestoreJob;
use BTB\SiteBackup\Storage\RestoreStateStore;
use BTB\SiteBackup\Support\Lock;
use BTB\SiteBackup\Support\State;

defined('ABSPATH') || exit;

/**
 * Exécute une étape de travail bornée dans le temps, sous verrou : la sauvegarde en cours d'abord,
 * puis la restauration en cours.
 */
final class Runner
{
    /** @var bool vrai pendant une étape (évite de relancer depuis le crochet « shutdown » de la même requête) */
    private $stepping = false;

    private $lock;
    private $state;
    private $backupJob;
    private $restoreJob;
    private $restore;
    private $healer;

    public function __construct(Lock $lock, State $state, BackupJob $backupJob, RestoreJob $restoreJob, RestoreStateStore $restore, StateHealer $healer)
    {
        $this->lock       = $lock;
        $this->state      = $state;
        $this->backupJob  = $backupJob;
        $this->restoreJob = $restoreJob;
        $this->restore    = $restore;
        $this->healer     = $healer;
    }

    public function isStepping(): bool
    {
        return $this->stepping;
    }

    /**
     * @param int $maxBudget durée maximale de l'étape, en secondes (courte pour les requêtes de suivi de la page)
     * @return bool vrai s'il reste du travail
     */
    public function step(int $maxBudget = 25): bool
    {
        $this->stepping = true;
        $lock = $this->lock->acquire();
        if (!$lock) {
            return true;   // une autre étape tourne déjà
        }
        try {
            @set_time_limit(300);
            $max = (int) ini_get('max_execution_time');
            $end = microtime(true) + min($maxBudget, $max > 0 ? max(3, (int) ($max * 0.5)) : $maxBudget);

            $job = $this->backupJob->current();
            if ($job) {
                return $this->backupJob->step($job, $end) || $this->restore->exists();
            }
            $r = $this->restore->get();
            if ($r) {
                return $this->restoreJob->step($r, $end);
            }
            if ($this->state->get(true)['running']) {
                $this->state->set(['running' => false]);
            }
            $this->healer->heal();
            return false;
        } finally {
            $this->lock->release($lock);
        }
    }
}
