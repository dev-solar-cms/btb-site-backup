<?php
/**
 * Journal d'activité : qui a fait quoi, et quand.
 *
 * @var array $log
 */

use BTB\SiteBackup\Support\Format;

defined('ABSPATH') || exit;
?>
<h2>Journal d'activité</h2>
<details>
    <summary>Afficher les <?php echo count($log); ?> dernières opérations (qui, quoi, quand)</summary>
    <table class="widefat striped btb-log">
        <thead><tr><th>Date</th><th>Par</th><th>Sauvegarde</th><th>Opération</th></tr></thead>
        <tbody>
        <?php if (!$log) : ?>
            <tr><td colspan="4">Aucune opération enregistrée pour le moment.</td></tr>
        <?php endif; ?>
        <?php foreach ($log as $e) : ?>
            <tr>
                <td><?php echo esc_html(wp_date('d/m/Y H:i', (int) $e['t'])); ?></td>
                <td><?php echo esc_html($e['who']); ?></td>
                <td><?php echo esc_html($e['id'] ? Format::idLabel($e['id']) : '—'); ?></td>
                <td><?php echo esc_html($e['d']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</details>
