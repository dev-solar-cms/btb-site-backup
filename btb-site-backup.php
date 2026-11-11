<?php
/**
 * Plugin Name: BTB Sauvegarde
 * Description: Sauvegardes automatiques et incrémentales (base de données, extensions, thèmes, médias, autres fichiers), stockées uniquement sur le serveur, hors du dossier web, avec restauration automatique. Aucune tâche cron, aucun service distant.
 * Version: 1.0.4
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: David ROMERA
 * License: GPL-2.0-or-later
 * Text Domain: btb-site-backup
 */

defined('ABSPATH') || exit;

if (defined('BTB_BACKUP_FILE')) {
    return;   // déjà chargé
}

define('BTB_BACKUP_FILE', __FILE__);
define('BTB_BACKUP_DIR', __DIR__);

require_once __DIR__ . '/src/Autoloader.php';
\BTB\SiteBackup\Autoloader::register('BTB\\SiteBackup\\', __DIR__ . '/src');

register_activation_hook(__FILE__, [\BTB\SiteBackup\Lifecycle\Activator::class, 'activate']);
register_deactivation_hook(__FILE__, [\BTB\SiteBackup\Lifecycle\Deactivator::class, 'deactivate']);

\BTB\SiteBackup\Plugin::boot();
