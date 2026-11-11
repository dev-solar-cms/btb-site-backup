<?php

namespace BTB\SiteBackup\Admin;

use BTB\SiteBackup\Config;
use BTB\SiteBackup\Container;

defined('ABSPATH') || exit;

/**
 * Menu « BTB Sauvegarde » (juste sous le Tableau de bord), ressources CSS/JS de la page,
 * et lien « Ouvrir » dans la liste des extensions.
 */
final class Menu
{
    const POSITION = '2.1';   // le Tableau de bord est en position 2

    /** @var Container */
    private $c;
    /** @var string */
    private $hook = '';

    public function __construct(Container $c)
    {
        $this->c = $c;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_filter('plugin_action_links_' . plugin_basename(BTB_BACKUP_FILE), [$this, 'actionLinks']);
    }

    public function addMenu(): void
    {
        $this->hook = (string) add_menu_page('BTB Sauvegarde', 'BTB Sauvegarde', Config::CAP, Config::SLUG, [$this->c->adminPage(), 'render'], 'dashicons-backup', self::POSITION);
    }

    public function enqueue(string $hook): void
    {
        if ($hook !== $this->hook) {
            return;
        }
        $base = plugin_dir_url(BTB_BACKUP_FILE);
        wp_enqueue_style('btb-backup-admin', $base . 'assets/css/admin.css', [], Config::VERSION);
        wp_enqueue_script('btb-backup-admin', $base . 'assets/js/admin.js', [], Config::VERSION, true);
        wp_localize_script('btb-backup-admin', 'BTBBackup', [
            'ajax'  => admin_url('admin-ajax.php'),
            'page'  => admin_url('admin.php?page=' . Config::SLUG),
            'nonce' => wp_create_nonce('btb_backup_status'),
            'busy'  => $this->c->state()->busy() ? '1' : '',
            'i18n'  => [
                'running'  => 'en cours',
                'starting' => 'Démarrage…',
                'prepare'  => 'Préparation…',
                'done'     => 'Terminé',
                'failed'   => 'Opération interrompue',
                'step'     => 'Étape',
                'expired'  => 'Opération en cours sur le serveur (session expirée ? rechargez la page ou reconnectez-vous).',
                'offline'  => 'Opération en cours sur le serveur (vous avez peut-être été déconnecté : rechargez la page).',
                'cantStart' => 'Impossible de lancer la sauvegarde.',
            ],
        ]);
    }

    public function actionLinks(array $links): array
    {
        array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=' . Config::SLUG)) . '">Ouvrir</a>');
        return $links;
    }
}
