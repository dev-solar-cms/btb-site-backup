<?php

namespace BTB\SiteBackup\Admin\Actions;

defined('ABSPATH') || exit;

/** Protège une sauvegarde (jamais supprimée automatiquement) ou retire la protection. */
final class LockBackupAction extends AbstractAction
{
    public function handle(): void
    {
        $this->guard('btb_backup_lock');
        $id = $this->postedId();
        if (!$id) {
            $this->back('Sauvegarde introuvable.');
        }
        $locked = !empty($_POST['locked']);
        $this->c->repository()->setLocked($id, $locked);
        $this->c->logger()->log($locked ? 'lock' : 'unlock', $locked ? 'Sauvegarde protégée.' : 'Protection retirée.', $id);
        $this->back($locked ? 'Sauvegarde protégée : elle ne sera jamais supprimée automatiquement.' : 'Protection retirée.');
    }
}
