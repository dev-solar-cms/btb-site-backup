<?php

namespace BTB\SiteBackup\Admin\Ajax;

use BTB\SiteBackup\Config;
use BTB\SiteBackup\Container;

defined('ABSPATH') || exit;

/** Lance une sauvegarde manuelle depuis la page, sans rechargement. */
final class StartAjax
{
    /** @var Container */
    private $c;

    public function __construct(Container $c)
    {
        $this->c = $c;
    }

    public function handle(): void
    {
        if (!current_user_can(Config::CAP)) {
            wp_send_json_error(['message' => 'Accès refusé.'], 403);
        }
        check_ajax_referer('btb_backup_run');
        if (!$this->c->settings()->get()['components']) {
            wp_send_json_error(['message' => 'Aucun élément à sauvegarder : cochez au moins un élément dans les réglages.']);
        }
        $label = isset($_POST['label']) ? sanitize_text_field(wp_unslash($_POST['label'])) : '';
        if (!$this->c->backupJob()->start(true, !empty($_POST['locked']), mb_substr($label, 0, 100))) {
            wp_send_json_error(['message' => 'Une sauvegarde ou une restauration est déjà en cours.']);
        }
        $this->c->scheduler()->spawn();
        wp_send_json_success(['running' => true, 'progress' => $this->c->progress()->data()]);
    }
}
