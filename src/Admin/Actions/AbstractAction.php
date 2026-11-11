<?php

namespace BTB\SiteBackup\Admin\Actions;

use BTB\SiteBackup\Config;
use BTB\SiteBackup\Container;

defined('ABSPATH') || exit;

/**
 * Base des actions de formulaire (admin-post.php) : contrôle des droits et du jeton, retour à la page.
 */
abstract class AbstractAction
{
    /** @var Container */
    protected $c;

    public function __construct(Container $c)
    {
        $this->c = $c;
    }

    abstract public function handle(): void;

    /** Vérifie les droits de l'utilisateur et le jeton anti-falsification (nonce). */
    protected function guard(string $nonce): void
    {
        if (!current_user_can(Config::CAP)) {
            wp_die('Accès refusé.', '', ['response' => 403]);
        }
        check_admin_referer($nonce);
    }

    /** Retour à la page avec un message. */
    protected function back(string $msg): void
    {
        wp_safe_redirect(add_query_arg(['page' => Config::SLUG, 'btb_msg' => rawurlencode($msg)], admin_url('admin.php')));
        exit;
    }

    /** Identifiant de sauvegarde envoyé, s'il est valide et existe. */
    protected function postedId(): ?string
    {
        $id = isset($_REQUEST['id']) ? (string) wp_unslash($_REQUEST['id']) : '';
        return $this->c->repository()->exists($id) ? $id : null;
    }

    /** Liste de composants envoyée par un formulaire. */
    protected function postedComponents(): array
    {
        return isset($_POST['components']) ? array_map('sanitize_key', (array) wp_unslash($_POST['components'])) : [];
    }
}
