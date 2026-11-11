<?php

namespace BTB\SiteBackup\Admin\Ajax;

use BTB\SiteBackup\Container;

defined('ABSPATH') || exit;

/**
 * Requête interne (le site s'appelle lui-même) qui fait avancer la sauvegarde ou la restauration.
 * Accessible sans connexion, mais protégée par un jeton secret propre au site.
 */
final class StepAjax
{
    /** @var Container */
    private $c;

    public function __construct(Container $c)
    {
        $this->c = $c;
    }

    public function handle(): void
    {
        $token = isset($_POST['token']) ? (string) wp_unslash($_POST['token']) : '';
        if (!hash_equals($this->c->state()->token(), $token)) {
            wp_die('', '', ['response' => 403]);
        }
        ignore_user_abort(true);
        if ($this->c->runner()->step()) {
            $this->c->scheduler()->spawn();
        }
        wp_die('', '', ['response' => 200]);
    }
}
