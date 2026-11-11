<?php

namespace BTB\SiteBackup\Admin\Actions;

defined('ABSPATH') || exit;

/** Lance la restauration d'une sauvegarde (éléments cochés, options de sécurité). */
final class RestoreAction extends AbstractAction
{
    public function handle(): void
    {
        $this->guard('btb_backup_restore');
        $id = $this->postedId();
        if (!$id) {
            $this->back('Sauvegarde introuvable.');
        }
        try {
            $msg = $this->c->restoreJob()->start($id, $this->postedComponents(), !empty($_POST['delete']), !empty($_POST['safety']));
        } catch (\RuntimeException $e) {
            $this->back($e->getMessage());
            return;
        }
        $this->c->scheduler()->spawn();
        $this->back($msg);
    }
}
