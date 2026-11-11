<?php

namespace BTB\SiteBackup\Admin\Actions;

use BTB\SiteBackup\Config;
use BTB\SiteBackup\Storage\IndexFile;

defined('ABSPATH') || exit;

/** Télécharge un élément d'une sauvegarde : dump de la base (.sql.gz) ou partie d'archive ZIP. */
final class DownloadAction extends AbstractAction
{
    public function handle(): void
    {
        $this->guard('btb_backup_download');
        $id   = $this->postedId();
        $comp = isset($_GET['comp']) ? sanitize_key(wp_unslash($_GET['comp'])) : '';
        $part = isset($_GET['part']) ? max(1, (int) $_GET['part']) : 1;
        if (!$id || !isset(Config::COMPONENTS[$comp])) {
            wp_die('Sauvegarde introuvable.', '', ['response' => 404]);
        }
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $repo = $this->c->repository();
        if ('db' === $comp) {
            $file = $repo->dumpFile($id);
            if (!is_file($file)) {
                wp_die('Fichier introuvable.', '', ['response' => 404]);
            }
            $this->c->zip()->file($file, $host . '_' . $id . '_base-de-donnees.sql.gz', 'application/gzip');
            return;
        }
        $index = $repo->indexFile($id, $comp);
        if (!is_file($index)) {
            wp_die('Fichier introuvable.', '', ['response' => 404]);
        }
        $this->c->zip()->stream(IndexFile::partEntries($index, $part), $host . '_' . $id . '_' . $comp . '_partie-' . $part . '.zip');
    }
}
