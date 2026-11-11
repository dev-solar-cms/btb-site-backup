<?php

namespace BTB\SiteBackup\Lifecycle;

use BTB\SiteBackup\Config;
use BTB\SiteBackup\Plugin;

defined('ABSPATH') || exit;

/**
 * Activation de l'extension : vérifie l'environnement, prépare le dossier de stockage protégé,
 * les réglages par défaut et le jeton des requêtes internes.
 * La première sauvegarde automatique démarre à la visite suivante.
 */
final class Activator
{
    public static function activate(): void
    {
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            deactivate_plugins(plugin_basename(BTB_BACKUP_FILE));
            wp_die('BTB Sauvegarde nécessite PHP 7.4 ou plus récent.', 'BTB Sauvegarde', ['back_link' => true]);
        }
        $c = Plugin::container();

        // Dossier de stockage (hors du dossier public pour Bedrock), protégé par .htaccess et index.php.
        $c->paths()->backupDir();
        wp_mkdir_p($c->paths()->storeDir());
        $c->paths()->protect($c->paths()->storeDir());

        // Réglages par défaut et statut initial (sans écraser ceux d'une installation précédente).
        add_option(Config::OPT_SETTINGS, $c->settings()->defaults(), '', true);
        add_option(Config::OPT_STATE, $c->state()->defaults(), '', true);
        $c->state()->token();

        $warnings = [];
        if (!function_exists('gzopen')) {
            $warnings[] = 'extension PHP zlib absente : la base de données ne pourra pas être sauvegardée';
        }
        $c->logger()->log('activate', 'Extension activée (version ' . Config::VERSION . ')' . ($warnings ? ' — ' . implode(', ', $warnings) : '') . '.');
    }
}
