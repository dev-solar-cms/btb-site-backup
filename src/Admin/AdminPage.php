<?php

namespace BTB\SiteBackup\Admin;

use BTB\SiteBackup\Config;
use BTB\SiteBackup\Container;
use BTB\SiteBackup\Support\Warnings;

defined('ABSPATH') || exit;

/**
 * Page « BTB Sauvegarde » : rassemble les données et affiche les gabarits de templates/admin/.
 */
final class AdminPage
{
    /** @var Container */
    private $c;

    public function __construct(Container $c)
    {
        $this->c = $c;
    }

    public function render(): void
    {
        if (!current_user_can(Config::CAP)) {
            return;
        }
        $this->c->healer()->heal();
        $paths   = $this->c->paths();
        $state   = $this->c->state();
        $busy    = $state->busy();
        $restore = $this->c->restoreStore()->get();
        $lastR   = is_file($paths->lastRestoreFile()) ? json_decode((string) file_get_contents($paths->lastRestoreFile()), true) : null;

        $backups = [];
        foreach ($this->c->repository()->all() as $b) {
            $warnings      = $this->c->repository()->warnings($b);
            $b['warnings'] = $warnings;
            $b['wcount']   = isset($b['wcount']) ? (array) $b['wcount'] : Warnings::counts($warnings);
            $backups[]     = $b;
        }

        $this->view('page', [
            'page'        => $this,
            'settings'    => $this->c->settings()->get(),
            'state'       => $state->get(true),
            'busy'        => $busy,
            'restore'     => $restore,
            'progress'    => $busy ? $this->c->progress()->data() : null,
            'done'        => (!empty($_GET['btb_done']) && !$busy) ? $this->c->progress()->done() : null,
            'notice'      => isset($_GET['btb_msg']) ? sanitize_text_field(wp_unslash($_GET['btb_msg'])) : '',
            'nextRun'     => $busy ? null : $this->c->scheduler()->nextRun(),
            'diskUsage'   => $this->c->objectStore()->size() + $this->c->repository()->dumpsSize(),
            'backupDir'   => $paths->backupDir(),
            'projectRoot' => $paths->projectRoot(),
            'prefix'      => $GLOBALS['wpdb']->prefix,
            'lastRestore' => is_array($lastR) ? $lastR : null,
            'backups'     => $backups,
            'log'         => $this->c->logger()->entries(100),
            'cancellable' => $busy && (!$restore || in_array($restore['phase'], ['wait', 'db_import'], true)),
        ]);
    }

    /** Affiche un gabarit de templates/admin/ avec ses variables. */
    public function view(string $name, array $vars = []): void
    {
        $file = BTB_BACKUP_DIR . '/templates/admin/' . $name . '.php';
        if (!is_file($file)) {
            return;
        }
        (static function () use ($file, $vars) {
            extract($vars, EXTR_SKIP);
            include $file;
        })();
    }

    public function postUrl(): string
    {
        return admin_url('admin-post.php');
    }

    public function downloadUrl(string $id, string $comp, int $part = 1): string
    {
        $args = ['action' => 'btb_backup_download', 'id' => $id, 'comp' => $comp];
        if ('db' !== $comp) {
            $args['part'] = $part;
        }
        return wp_nonce_url(add_query_arg($args, admin_url('admin-post.php')), 'btb_backup_download');
    }
}
