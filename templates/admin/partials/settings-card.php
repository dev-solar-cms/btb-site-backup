<?php
/**
 * Cadre « Réglages ».
 *
 * @var \BTB\SiteBackup\Admin\AdminPage $page
 * @var array $settings
 */

use BTB\SiteBackup\Config;

defined('ABSPATH') || exit;
?>
<div class="card">
    <h2>Réglages</h2>
    <form method="post" action="<?php echo esc_url($page->postUrl()); ?>">
        <?php wp_nonce_field('btb_backup_save'); ?>
        <input type="hidden" name="action" value="btb_backup_save">
        <p>
            <label>Délai entre deux sauvegardes automatiques<br>
                <input name="interval" type="number" min="1" max="720" value="<?php echo esc_attr($settings['interval']); ?>" class="small-text"> heures
                <span class="description">(24 = une fois par jour)</span>
            </label>
        </p>
        <p>
            <label>Sauvegardes automatiques conservées<br>
                <input name="keep" type="number" min="1" max="60" value="<?php echo esc_attr($settings['keep']); ?>" class="small-text">
                <span class="description">les plus anciennes sont supprimées ; les sauvegardes protégées ne comptent pas</span>
            </label>
        </p>
        <p>Éléments sauvegardés :<br>
            <?php foreach (Config::COMPONENTS as $key => $label) : ?>
                <label><input type="checkbox" name="components[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $settings['components'], true)); ?>> <?php echo esc_html($label); ?></label><br>
            <?php endforeach; ?>
        </p>
        <p><button class="button">Enregistrer</button></p>
    </form>
</div>
