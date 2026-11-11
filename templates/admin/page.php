<?php
/**
 * Page « BTB Sauvegarde ».
 *
 * @var \BTB\SiteBackup\Admin\AdminPage $page
 */

defined('ABSPATH') || exit;
?>
<div class="wrap btb-backup">
    <h1>BTB Sauvegarde</h1>

    <?php $page->view('partials/notices', get_defined_vars()); ?>

    <div class="btb-grid">
        <?php $page->view('partials/status-card', get_defined_vars()); ?>
        <?php $page->view('partials/run-card', get_defined_vars()); ?>
        <?php $page->view('partials/settings-card', get_defined_vars()); ?>
    </div>

    <?php $page->view('partials/backups-table', get_defined_vars()); ?>
    <?php $page->view('partials/activity-log', get_defined_vars()); ?>
    <?php $page->view('partials/manual-restore', get_defined_vars()); ?>
</div>
