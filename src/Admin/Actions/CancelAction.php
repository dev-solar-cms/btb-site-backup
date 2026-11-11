<?php

namespace BTB\SiteBackup\Admin\Actions;

defined('ABSPATH') || exit;

/** Annule la sauvegarde en cours, ou la restauration tant qu'elle n'a rien modifié. */
final class CancelAction extends AbstractAction
{
    public function handle(): void
    {
        $this->guard('btb_backup_cancel');
        if ($this->c->restoreStore()->exists()) {
            $this->back($this->c->restoreJob()->cancel());
        }
        $job = $this->c->backupJob()->current();
        if ($job) {
            $this->c->backupJob()->fail($job, 'Sauvegarde annulée par un administrateur.');
        } else {
            $this->c->state()->set(['running' => false]);
        }
        $this->back('Sauvegarde annulée.');
    }
}
