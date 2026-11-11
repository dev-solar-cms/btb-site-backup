<?php
/**
 * Tableau des sauvegardes disponibles : contenu téléchargeable, avertissements, restauration, protection, suppression.
 *
 * @var \BTB\SiteBackup\Admin\AdminPage $page
 * @var array $backups
 * @var bool  $busy
 */

use BTB\SiteBackup\Config;
use BTB\SiteBackup\Support\Format;
use BTB\SiteBackup\Support\Warnings;

defined('ABSPATH') || exit;
?>
<h2>Sauvegardes disponibles (<?php echo count($backups); ?>)</h2>
<p class="description">Chaque sauvegarde est complète. Les fichiers identiques entre deux sauvegardes ne sont stockés qu'une seule fois. Seuls les fichiers nouveaux ou modifiés sont copiés.</p>

<table class="widefat striped btb-table">
    <thead>
        <tr><th>Date</th><th>Contenu (cliquer pour télécharger)</th><th>Nouvelles données</th><th>Actions</th></tr>
    </thead>
    <tbody>
    <?php if (!$backups) : ?>
        <tr><td colspan="4">Aucune sauvegarde pour le moment.</td></tr>
    <?php endif; ?>

    <?php foreach ($backups as $b) :
        $date = Format::idLabel($b['id']);
        $new  = 0; ?>
        <tr>
            <td>
                <strong><?php echo esc_html($date); ?></strong>
                <?php if (!empty($b['label'])) : ?><br><?php echo esc_html($b['label']); ?><?php endif; ?><br>
                <span class="btb-badge"><?php echo !empty($b['manual']) ? 'Manuelle' : 'Automatique'; ?></span>
                <?php if (!empty($b['locked'])) : ?><span class="btb-badge btb-lock">Protégée</span><?php endif; ?>
            </td>

            <td>
                <?php foreach ((array) $b['components'] as $comp => $info) :
                    if (!isset(Config::SHORT[$comp])) {
                        continue;
                    }
                    if ('db' === $comp) :
                        $new += (int) $info['size']; ?>
                        <div class="btb-comp">
                            <a href="<?php echo esc_url($page->downloadUrl($b['id'], 'db')); ?>">Base de données</a>
                            <span class="description">(<?php echo esc_html(size_format($info['size'], 1)); ?>)</span>
                        </div>
                    <?php else :
                        $new  += (int) $info['new_bytes'];
                        $parts = (array) $info['parts']; ?>
                        <div class="btb-comp">
                            <?php echo esc_html(Config::SHORT[$comp]); ?>
                            <span class="description">(<?php echo esc_html(number_format_i18n($info['count']) . ' fichiers, ' . size_format($info['bytes'], 1)); ?>)</span> :
                            <?php if (!$parts) : ?><span class="description">vide</span><?php endif; ?>
                            <?php foreach ($parts as $i => $part) : ?>
                                <a href="<?php echo esc_url($page->downloadUrl($b['id'], $comp, $i + 1)); ?>"><?php echo esc_html(count($parts) > 1 ? 'partie ' . ($i + 1) : 'télécharger'); ?></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif;
                endforeach; ?>

                <?php if (Warnings::worst($b['wcount'])) : ?>
                    <details class="btb-warn-toggle">
                        <summary class="button button-small">
                            Voir les avertissements (<?php echo (int) array_sum($b['wcount']); ?>)
                            <?php foreach (Config::LEVELS as $lvl => $info) :
                                if (!empty($b['wcount'][$lvl])) : ?>
                                    <span class="btb-level" style="background:<?php echo esc_attr($info[1]); ?>"><?php echo esc_html($info[0] . ' : ' . $b['wcount'][$lvl]); ?></span>
                                <?php endif;
                            endforeach; ?>
                        </summary>
                        <?php $page->view('partials/warnings', ['warnings' => $b['warnings'], 'counts' => $b['wcount']]); ?>
                    </details>
                <?php endif; ?>
            </td>

            <td><?php echo esc_html(size_format($new, 1)); ?></td>

            <td>
                <details>
                    <summary class="button button-small">Restaurer…</summary>
                    <form method="post" action="<?php echo esc_url($page->postUrl()); ?>" class="btb-restore"
                          data-confirm="<?php echo esc_attr('Restaurer cette sauvegarde ? Les éléments cochés du site seront remplacés par leur état du ' . $date . '.'); ?>">
                        <?php wp_nonce_field('btb_backup_restore'); ?>
                        <input type="hidden" name="action" value="btb_backup_restore">
                        <input type="hidden" name="id" value="<?php echo esc_attr($b['id']); ?>">
                        <p><strong>Éléments à restaurer :</strong><br>
                            <?php foreach (array_keys((array) $b['components']) as $comp) :
                                if (!isset(Config::SHORT[$comp])) {
                                    continue;
                                } ?>
                                <label><input type="checkbox" name="components[]" value="<?php echo esc_attr($comp); ?>" checked> <?php echo esc_html(Config::SHORT[$comp]); ?></label><br>
                            <?php endforeach; ?>
                        </p>
                        <p><label><input type="checkbox" name="delete" value="1" checked> Supprimer les fichiers ajoutés depuis cette sauvegarde (recommandé après un piratage)</label></p>
                        <p><label><input type="checkbox" name="safety" value="1" checked> Faire d'abord une sauvegarde de sécurité de l'état actuel</label></p>
                        <p class="description">Le fichier <code>.env</code> (mots de passe actuels) n'est jamais remplacé.</p>
                        <p><button class="button button-primary" <?php disabled($busy); ?>>Lancer la restauration</button></p>
                    </form>
                </details>

                <form method="post" action="<?php echo esc_url($page->postUrl()); ?>" class="btb-inline">
                    <?php wp_nonce_field('btb_backup_lock'); ?>
                    <input type="hidden" name="action" value="btb_backup_lock">
                    <input type="hidden" name="id" value="<?php echo esc_attr($b['id']); ?>">
                    <?php if (!empty($b['locked'])) : ?>
                        <button class="button button-small">Ne plus protéger</button>
                    <?php else : ?>
                        <input type="hidden" name="locked" value="1">
                        <button class="button button-small">Protéger</button>
                    <?php endif; ?>
                </form>

                <form method="post" action="<?php echo esc_url($page->postUrl()); ?>" data-confirm="Supprimer définitivement cette sauvegarde ?">
                    <?php wp_nonce_field('btb_backup_delete'); ?>
                    <input type="hidden" name="action" value="btb_backup_delete">
                    <input type="hidden" name="id" value="<?php echo esc_attr($b['id']); ?>">
                    <button class="button button-small button-link-delete">Supprimer</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
