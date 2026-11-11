<?php

namespace BTB\SiteBackup\Admin\Actions;

defined('ABSPATH') || exit;

/** Lance une sauvegarde manuelle (repli sans JavaScript ; la page utilise normalement StartAjax). */
final class RunBackupAction extends AbstractAction
{
    public function handle(): void
    {
        $this->guard('btb_backup_run');
        if (!$this->c->settings()->get()['components']) {
            $this->back('Aucun élément à sauvegarder : cochez au moins un élément dans les réglages.');
        }
        $label = isset($_POST['label']) ? sanitize_text_field(wp_unslash($_POST['label'])) : '';
        if ($this->c->backupJob()->start(true, !empty($_POST['locked']), mb_substr($label, 0, 100))) {
            $this->c->scheduler()->spawn();
            $this->back('Sauvegarde lancée.');
        }
        $this->back('Une sauvegarde ou une restauration est déjà en cours.');
    }
}
