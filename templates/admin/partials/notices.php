<?php
/**
 * Messages en haut de page : retour d'action, fin d'opération, restauration en cours.
 *
 * @var string     $notice
 * @var array|null $done
 * @var array|null $restore
 */

defined('ABSPATH') || exit;
?>
<?php if ($notice) : ?>
    <div class="notice notice-info is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
<?php endif; ?>

<?php if ($done) :
    $class = 'ok' === $done['status'] ? 'notice-success' : ('error' === $done['status'] ? 'notice-error' : 'notice-warning'); ?>
    <div class="notice <?php echo esc_attr($class); ?> is-dismissible"><p><strong><?php echo esc_html($done['title']); ?></strong> <?php echo esc_html($done['msg']); ?></p></div>
<?php endif; ?>

<?php // Message de fin affiché par le JavaScript, sans rechargement. ?>
<div id="btb-done" class="notice" hidden><p><strong></strong> <span></span></p></div>

<?php if ($restore) : ?>
    <div class="notice notice-warning">
        <p><strong>Restauration en cours.</strong> Ne modifiez pas le site pendant l'opération. Vous pouvez quitter cette page : la restauration continue sur le serveur. Vous serez peut-être déconnecté à la fin (la base de données revient à son état d'origine).</p>
    </div>
<?php endif; ?>
