<?php

namespace BTB\SiteBackup\Admin\Actions;

defined('ABSPATH') || exit;

/** Supprime une sauvegarde (puis les fichiers du stockage qui ne servent plus). */
final class DeleteBackupAction extends AbstractAction
{
    public function handle(): void
    {
        $this->guard('btb_backup_delete');
        $id = $this->postedId();
        if (!$id) {
            $this->back('Sauvegarde introuvable.');
        }
        $r = $this->c->restoreStore()->get();
        if ($r && $r['id'] === $id) {
            $this->back('Cette sauvegarde est en cours de restauration.');
        }
        $this->c->repository()->delete($id);
        $this->c->logger()->log('delete', 'Sauvegarde supprimée.', $id);
        $this->c->gc()->run();
        $this->back('Sauvegarde supprimée.');
    }
}
