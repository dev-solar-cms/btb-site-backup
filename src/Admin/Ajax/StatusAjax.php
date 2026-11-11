<?php

namespace BTB\SiteBackup\Admin\Ajax;

use BTB\SiteBackup\Config;
use BTB\SiteBackup\Container;

defined('ABSPATH') || exit;

/**
 * Suivi de la progression (appelé toutes les 1,5 s par la page). La page ouverte fait aussi avancer
 * le travail, par petites tranches, ce qui aide si les requêtes internes sont bloquées.
 */
final class StatusAjax
{
    const STEP_BUDGET = 4;   // secondes

    /** @var Container */
    private $c;

    public function __construct(Container $c)
    {
        $this->c = $c;
    }

    public function handle(): void
    {
        if (!current_user_can(Config::CAP)) {
            wp_send_json_error(null, 403);
        }
        check_ajax_referer('btb_backup_status');
        $state = $this->c->state();
        if ($state->busy()) {
            $this->c->runner()->step(self::STEP_BUDGET);
        }
        $this->c->healer()->heal();
        if ($state->busy()) {
            wp_send_json_success(['running' => true, 'progress' => $this->c->progress()->data()]);
        }
        wp_send_json_success(['running' => false] + $this->c->progress()->done());
    }
}
