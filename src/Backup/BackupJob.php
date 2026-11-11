<?php

namespace BTB\SiteBackup\Backup;

use BTB\SiteBackup\Config;
use BTB\SiteBackup\Storage\BackupRepository;
use BTB\SiteBackup\Storage\GarbageCollector;
use BTB\SiteBackup\Storage\IndexFile;
use BTB\SiteBackup\Storage\RestoreStateStore;
use BTB\SiteBackup\Support\Logger;
use BTB\SiteBackup\Support\Paths;
use BTB\SiteBackup\Support\Settings;
use BTB\SiteBackup\Support\State;
use BTB\SiteBackup\Support\Warnings;

defined('ABSPATH') || exit;

/**
 * Une sauvegarde : démarrage, avancement par étapes courtes (base → inventaire → fichiers),
 * finalisation, échec, et rotation des anciennes sauvegardes.
 */
final class BackupJob
{
    private $paths;
    private $settings;
    private $state;
    private $logger;
    private $repository;
    private $gc;
    private $restore;
    private $exporter;
    private $scanner;
    private $collector;

    public function __construct(
        Paths $paths,
        Settings $settings,
        State $state,
        Logger $logger,
        BackupRepository $repository,
        GarbageCollector $gc,
        RestoreStateStore $restore,
        DatabaseExporter $exporter,
        FileScanner $scanner,
        FileCollector $collector
    ) {
        $this->paths      = $paths;
        $this->settings   = $settings;
        $this->state      = $state;
        $this->logger     = $logger;
        $this->repository = $repository;
        $this->gc         = $gc;
        $this->restore    = $restore;
        $this->exporter   = $exporter;
        $this->scanner    = $scanner;
        $this->collector  = $collector;
    }

    /** Sauvegarde en cours, ou null. */
    public function current(): ?array
    {
        $job = get_option(Config::OPT_JOB);
        return (is_array($job) && !empty($job['id'])) ? $job : null;
    }

    public function start(bool $manual, bool $locked, string $label): bool
    {
        if ($this->state->get(true)['running'] || $this->restore->exists()) {
            return false;
        }
        // Identifiant unique : jamais deux sauvegardes avec le même nom.
        $t = time();
        do {
            $id = gmdate('Y-m-d_His', $t++);
        } while (is_dir($this->paths->backupPath($id)) || is_dir($this->paths->backupPath($id) . '.tmp'));
        $dir = $this->paths->backupPath($id) . '.tmp';
        if (!wp_mkdir_p($dir)) {
            $this->state->set(['last_start' => time(), 'last_status' => 'error', 'last_msg' => 'Impossible de créer le dossier de sauvegarde.']);
            return false;
        }
        $comps     = $this->settings->get()['components'];
        $withDb    = in_array('db', $comps, true);
        $fileComps = array_values(array_diff($comps, ['db']));
        $job = [
            'id'       => $id,
            'manual'   => $manual,
            'locked'   => $locked,
            'label'    => $label,
            'phase'    => $withDb ? 'db' : ($fileComps ? 'scan' : 'finish'),
            'db'       => $withDb,
            'tables'   => $withDb ? $this->exporter->tables() : [],
            'ti'       => 0,
            'last_key' => null,
            'offset'   => 0,
            'rows'     => 0,
            'comps'    => $fileComps,
            'ci'       => 0,
            'list_pos' => 0,
            'idx_pos'  => 0,
            'totals'   => [],
            'stats'    => [],
            'started'  => time(),
            'warnings' => [],
            'wcount'   => [],
        ];
        foreach ($fileComps as $c) {
            $job['stats'][$c] = ['count' => 0, 'bytes' => 0, 'new_count' => 0, 'new_bytes' => 0];
        }
        update_option(Config::OPT_JOB, $job, false);
        $this->state->tick('last_work');
        $this->logger->log('backup_start', ($manual ? 'Sauvegarde manuelle' : 'Sauvegarde automatique') . ($label ? ' « ' . $label . ' »' : '') . ($locked ? ', protégée' : ''), $id);
        $this->state->set(['running' => true, 'last_start' => time(), 'last_status' => 'running', 'last_msg' => 'Sauvegarde en cours…']);
        return true;
    }

    /** Avance jusqu'à l'échéance. Renvoie true s'il reste du travail. */
    public function step(array $job, float $end): bool
    {
        try {
            if (time() - $job['started'] > Config::MAX_JOB) {
                $this->fail($job, 'Sauvegarde interrompue : durée maximale dépassée (12 h).');
                return false;
            }
            $dir = $this->paths->backupPath($job['id']) . '.tmp';
            if (!is_dir($dir)) {
                $this->fail($job, 'Dossier temporaire de la sauvegarde introuvable.');
                return false;
            }
            while (microtime(true) < $end) {
                switch ($job['phase']) {
                    case 'db':
                        if ($this->exporter->step($job, $dir, $end)) {
                            $job['phase'] = $job['comps'] ? 'scan' : 'finish';
                        }
                        break;
                    case 'scan':
                        $this->scanner->scan($job, $dir);
                        $job['phase'] = 'files';
                        break;
                    case 'files':
                        if ($this->collector->step($job, $dir, $end)) {
                            $job['phase'] = 'finish';
                        }
                        break;
                    default:
                        $this->finish($job, $dir);
                        return false;
                }
                update_option(Config::OPT_JOB, $job, false);
                $this->state->tick('last_work');
            }
            return true;
        } catch (\Throwable $e) {
            $this->fail($job, 'Erreur : ' . $e->getMessage());
            return false;
        }
    }

    private function finish(array $job, string $dir): void
    {
        global $wp_version, $wpdb;
        $components = [];
        if ($job['db'] && is_file($dir . '/database.sql.gz')) {
            $components['db'] = ['size' => filesize($dir . '/database.sql.gz'), 'tables' => count($job['tables']), 'rows' => $job['rows']];
        }
        foreach ($job['comps'] as $c) {
            $idx = $dir . '/index-' . $c . '.txt';
            if (!is_file($idx)) {
                touch($idx);
            }
            $components[$c] = $job['stats'][$c] + ['parts' => IndexFile::parts($idx)];
        }
        $manifest = [
            'plugin'     => 'BTB Sauvegarde ' . Config::VERSION,
            'id'         => $job['id'],
            'label'      => $job['label'],
            'manual'     => $job['manual'],
            'locked'     => $job['locked'],
            'site'       => home_url(),
            'prefix'     => $wpdb->prefix,
            'wp'         => $wp_version,
            'root'       => $this->paths->projectRoot(),
            'started'    => gmdate('c', $job['started']),
            'finished'   => gmdate('c'),
            'components' => $components,
            'wcount'     => $job['wcount'],
        ];
        $json = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        file_put_contents($dir . '/warnings.json', wp_json_encode($job['warnings'], $json));
        file_put_contents($dir . '/manifest.json', wp_json_encode($manifest, JSON_PRETTY_PRINT | $json));
        if (!@rename($dir, substr($dir, 0, -4))) {
            throw new \RuntimeException('impossible de finaliser le dossier de la sauvegarde');
        }
        delete_option(Config::OPT_JOB);
        $this->collector->reset();

        $total = 0;
        $new   = isset($components['db']) ? $components['db']['size'] : 0;
        foreach ($job['comps'] as $c) {
            $total += $components[$c]['bytes'];
            $new   += $components[$c]['new_bytes'];
        }
        $msg = sprintf('%s de fichiers sauvegardés, dont %s de nouvelles données copiées.', size_format($total, 1), size_format($new, 1));
        $nw  = array_sum($job['wcount']);
        if ($nw) {
            $msg .= sprintf(' %d avertissement(s) : %s (voir le détail dans la liste).', $nw, Warnings::summary($job['wcount']));
        }
        $this->state->set(['running' => false, 'last_end' => time(), 'last_status' => Warnings::status($job['wcount']), 'last_msg' => $msg]);
        $this->logger->log('backup_ok', $msg, $job['id']);
        $this->prune();
    }

    public function fail(array $job, string $msg): void
    {
        $this->paths->removeDir($this->paths->backupPath($job['id']) . '.tmp');
        delete_option(Config::OPT_JOB);
        $this->state->set(['running' => false, 'last_end' => time(), 'last_status' => 'error', 'last_msg' => $msg]);
        $this->logger->log('backup_fail', $msg, $job['id']);
        $this->gc->run();   // retire les fichiers copiés pour rien
    }

    /** Garde les N dernières sauvegardes non protégées ; les sauvegardes protégées ne sont jamais supprimées ici. */
    private function prune(): void
    {
        $keep = $this->settings->get()['keep'];
        $n    = 0;
        foreach ($this->repository->all() as $b) {
            if (!empty($b['locked'])) {
                continue;
            }
            if (++$n > $keep) {
                $this->repository->delete($b['id']);
                $this->logger->log('prune', sprintf('Supprimée automatiquement (seules les %d dernières sauvegardes automatiques sont conservées).', $keep), $b['id']);
            }
        }
        $this->gc->run();
    }
}
