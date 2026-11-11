<?php

namespace BTB\SiteBackup\Admin\Actions;

use BTB\SiteBackup\Config;

defined('ABSPATH') || exit;

/** Enregistre les réglages (délai, nombre conservé, éléments sauvegardés). */
final class SaveSettingsAction extends AbstractAction
{
    public function handle(): void
    {
        $this->guard('btb_backup_save');
        $s = $this->c->settings()->save([
            'interval'   => (int) ($_POST['interval'] ?? Config::DEFAULT_INTERVAL),
            'keep'       => (int) ($_POST['keep'] ?? Config::DEFAULT_KEEP),
            'components' => $this->postedComponents(),
        ]);
        $this->c->logger()->log('settings', sprintf(
            'Réglages : toutes les %d h, %d sauvegardes conservées, éléments : %s.',
            $s['interval'],
            $s['keep'],
            implode(', ', array_map(function ($c) {
                return Config::SHORT[$c];
            }, $s['components']))
        ));
        $this->back('Réglages enregistrés.');
    }
}
