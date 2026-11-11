<?php

namespace BTB\SiteBackup\Lifecycle;

use BTB\SiteBackup\Config;
use BTB\SiteBackup\Plugin;
use BTB\SiteBackup\Restore\RestoreJob;

defined('ABSPATH') || exit;

/**
 * Désactivation de l'extension : arrête proprement l'opération en cours.
 * Les sauvegardes et les réglages sont conservés (rien n'est supprimé).
 */
final class Deactivator
{
    public static function deactivate(): void
    {
        $c = Plugin::container();

        // Restauration en cours : annulée si elle n'a encore rien modifié, sinon suspendue
        // (elle reprendra à la réactivation, pour ne pas laisser le site dans un état mixte).
        $restore = $c->restoreStore()->get();
        if ($restore) {
            if (in_array($restore['phase'], RestoreJob::CANCELLABLE, true)) {
                $c->restoreJob()->cancel();
            } else {
                $c->logger()->log('restore_suspend', 'Extension désactivée pendant une restauration : elle reprendra à la réactivation.', $restore['id']);
            }
        }

        // Sauvegarde en cours : annulée (le dossier temporaire est supprimé).
        $job = $c->backupJob()->current();
        if ($job) {
            $c->backupJob()->fail($job, 'Sauvegarde annulée : extension désactivée.');
        }
        if (!$c->restoreStore()->exists()) {
            $c->state()->set(['running' => false]);
        }

        delete_option(Config::OPT_TICK);
        $c->logger()->log('deactivate', 'Extension désactivée. Les sauvegardes et les réglages sont conservés.');
    }
}
