<?php
/**
 * Cadre « Sauvegarder maintenant ».
 *
 * @var \BTB\SiteBackup\Admin\AdminPage $page
 * @var bool $busy
 */

defined('ABSPATH') || exit;
?>
<div class="card">
    <h2>Sauvegarder maintenant</h2>
    <form method="post" action="<?php echo esc_url($page->postUrl()); ?>" id="btb-run-form">
        <?php wp_nonce_field('btb_backup_run'); ?>
        <input type="hidden" name="action" value="btb_backup_run">
        <p>
            <label>Nom (facultatif)<br>
                <input type="text" name="label" class="regular-text" maxlength="100" placeholder="ex. Avant mise à jour WooCommerce">
            </label>
        </p>
        <p><label><input type="checkbox" name="locked" value="1" checked> Conserver cette sauvegarde (jamais supprimée automatiquement)</label></p>
        <p><button class="button button-primary" id="btb-run-btn" <?php disabled($busy); ?>>Lancer la sauvegarde</button></p>
    </form>
</div>
