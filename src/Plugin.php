<?php

namespace BTB\SiteBackup;

use BTB\SiteBackup\Admin\Actions\CancelAction;
use BTB\SiteBackup\Admin\Actions\DeleteBackupAction;
use BTB\SiteBackup\Admin\Actions\DownloadAction;
use BTB\SiteBackup\Admin\Actions\LockBackupAction;
use BTB\SiteBackup\Admin\Actions\RestoreAction;
use BTB\SiteBackup\Admin\Actions\RunBackupAction;
use BTB\SiteBackup\Admin\Actions\SaveSettingsAction;
use BTB\SiteBackup\Admin\Ajax\StartAjax;
use BTB\SiteBackup\Admin\Ajax\StatusAjax;
use BTB\SiteBackup\Admin\Ajax\StepAjax;
use BTB\SiteBackup\Admin\Menu;

defined('ABSPATH') || exit;

/**
 * Point d'entrée : branche les services sur les crochets (hooks) de WordPress.
 */
final class Plugin
{
    /** @var Container|null */
    private static $container;

    public static function container(): Container
    {
        if (null === self::$container) {
            self::$container = new Container();
        }
        return self::$container;
    }

    public static function boot(): void
    {
        $c = self::container();

        // Administration : menu, ressources CSS/JS, lien « Ouvrir » dans la liste des extensions.
        (new Menu($c))->register();

        // Formulaires (admin-post.php) : une classe par action.
        $actions = [
            'save'     => SaveSettingsAction::class,
            'run'      => RunBackupAction::class,
            'cancel'   => CancelAction::class,
            'delete'   => DeleteBackupAction::class,
            'lock'     => LockBackupAction::class,
            'restore'  => RestoreAction::class,
            'download' => DownloadAction::class,
        ];
        foreach ($actions as $name => $class) {
            add_action('admin_post_btb_backup_' . $name, static function () use ($class, $c) {
                (new $class($c))->handle();
            });
        }

        // Requêtes AJAX : suivi de la progression, démarrage, étape interne (protégée par jeton).
        add_action('wp_ajax_btb_backup_status', static function () use ($c) {
            (new StatusAjax($c))->handle();
        });
        add_action('wp_ajax_btb_backup_start', static function () use ($c) {
            (new StartAjax($c))->handle();
        });
        $step = static function () use ($c) {
            (new StepAjax($c))->handle();
        };
        add_action('wp_ajax_nopriv_btb_backup_step', $step);
        add_action('wp_ajax_btb_backup_step', $step);

        // Mises à jour de l'extension depuis les releases GitHub.
        $c->updater()->register();

        // Déclencheur sans cron : vérifié à la fin de chaque requête.
        add_action('shutdown', [$c->scheduler(), 'onShutdown'], PHP_INT_MAX);
    }
}
