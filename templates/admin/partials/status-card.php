<?php
/**
 * Cadre « État » : dernière opération, prochaine sauvegarde, barre de progression, espace utilisé.
 *
 * @var \BTB\SiteBackup\Admin\AdminPage $page
 * @var array      $state
 * @var bool       $busy
 * @var array|null $progress
 * @var int|null   $nextRun
 * @var int        $diskUsage
 * @var string     $backupDir
 * @var bool       $cancellable
 * @var array|null $lastRestore
 */

use BTB\SiteBackup\Config;
use BTB\SiteBackup\Support\Format;

defined('ABSPATH') || exit;

$next = $busy ? 'après l’opération en cours' : ($nextRun ? Format::date($nextRun) : 'à la prochaine visite du site');
?>
<div class="card">
    <h2>État</h2>
    <p>
        <strong>Dernière opération :</strong>
        <?php echo esc_html(Format::date((int) $state['last_start'])); ?> —
        <?php echo esc_html(Config::STATUS_LABELS[$state['last_status']] ?? $state['last_status']); ?><br>
        <span class="description"><?php echo esc_html($state['last_msg']); ?></span>
    </p>
    <p><strong>Prochaine sauvegarde automatique :</strong> <?php echo esc_html($next); ?></p>

    <div id="btb-pwrap" class="btb-pwrap" <?php echo $busy ? '' : 'hidden'; ?>>
        <strong id="btb-pkind"><?php echo esc_html($progress ? $progress['kind'] . ' en cours' : ''); ?></strong>
        <div class="btb-pbar">
            <span id="btb-pfill" style="width:<?php echo (int) ($progress['percent'] ?? 0); ?>%"></span>
            <em id="btb-ppct"><?php echo (int) ($progress['percent'] ?? 0); ?> %</em>
        </div>
        <p class="btb-step" id="btb-pstep"><?php
            if ($progress && $progress['steps']) {
                echo esc_html(sprintf('Étape %d/%d — %s', $progress['step'], $progress['steps'], $progress['label']));
            }
        ?></p>
        <p class="btb-detail description" id="btb-pdetail"><?php echo esc_html($progress['detail'] ?? ''); ?></p>
    </div>

    <p>
        <strong>Espace utilisé :</strong> <?php echo esc_html(size_format($diskUsage, 1)); ?><br>
        <span class="description">Emplacement : <code><?php echo esc_html($backupDir); ?></code> (sur le serveur, hors du dossier public)</span>
    </p>

    <?php if ($cancellable) : ?>
        <form method="post" action="<?php echo esc_url($page->postUrl()); ?>" data-confirm="Annuler l’opération en cours ?">
            <?php wp_nonce_field('btb_backup_cancel'); ?>
            <input type="hidden" name="action" value="btb_backup_cancel">
            <button class="button">Annuler</button>
        </form>
    <?php endif; ?>

    <?php if ($lastRestore) : ?>
        <details>
            <summary><strong>Dernière restauration</strong> (<?php echo esc_html(Format::date((int) $lastRestore['date'])); ?>)</summary>
            <p><?php echo esc_html($lastRestore['msg']); ?></p>
            <?php $page->view('partials/warnings', ['warnings' => (array) $lastRestore['warnings'], 'counts' => (array) $lastRestore['wcount']]); ?>
            <?php if (!empty($lastRestore['deleted'])) : ?>
                <details>
                    <summary><?php echo count($lastRestore['deleted']); ?> fichier(s) supprimé(s) car absents de la sauvegarde</summary>
                    <ul class="btb-paths">
                        <?php foreach ($lastRestore['deleted'] as $path) : ?>
                            <li><code><?php echo esc_html($path); ?></code></li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
        </details>
    <?php endif; ?>
</div>
