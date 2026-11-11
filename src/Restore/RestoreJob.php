<?php

namespace BTB\SiteBackup\Restore;

use BTB\SiteBackup\Backup\BackupJob;
use BTB\SiteBackup\Config;
use BTB\SiteBackup\Storage\BackupRepository;
use BTB\SiteBackup\Storage\GarbageCollector;
use BTB\SiteBackup\Storage\RestoreStateStore;
use BTB\SiteBackup\Support\Format;
use BTB\SiteBackup\Support\Logger;
use BTB\SiteBackup\Support\Paths;
use BTB\SiteBackup\Support\Settings;
use BTB\SiteBackup\Support\State;
use BTB\SiteBackup\Support\Warnings;

defined('ABSPATH') || exit;

/**
 * Une restauration : (sauvegarde de sécurité) → import de la base dans des tables temporaires →
 * fichiers → suppression des fichiers ajoutés → échange atomique des tables → finalisation.
 */
final class RestoreJob
{
    /** Phases pendant lesquelles l'annulation ne modifie pas le site. */
    const CANCELLABLE = ['wait', 'db_import'];

    private $paths;
    private $settings;
    private $state;
    private $logger;
    private $repository;
    private $store;
    private $backupJob;
    private $importer;
    private $restorer;
    private $gc;

    public function __construct(
        Paths $paths,
        Settings $settings,
        State $state,
        Logger $logger,
        BackupRepository $repository,
        RestoreStateStore $store,
        BackupJob $backupJob,
        DatabaseImporter $importer,
        FileRestorer $restorer,
        GarbageCollector $gc
    ) {
        $this->paths      = $paths;
        $this->settings   = $settings;
        $this->state      = $state;
        $this->logger     = $logger;
        $this->repository = $repository;
        $this->store      = $store;
        $this->backupJob  = $backupJob;
        $this->importer   = $importer;
        $this->restorer   = $restorer;
        $this->gc         = $gc;
    }

    /**
     * Lance une restauration. Renvoie le message à afficher ; lève une exception si elle est impossible.
     *
     * @param string[] $comps composants demandés (« db », « plugins »…)
     */
    public function start(string $id, array $comps, bool $deleteAdded, bool $safety): string
    {
        $b = $this->repository->get($id);
        if (!$b) {
            throw new \RuntimeException('Sauvegarde introuvable.');
        }
        if ($this->state->busy()) {
            throw new \RuntimeException('Une sauvegarde ou une restauration est déjà en cours.');
        }
        $comps = array_values(array_intersect(array_keys(Config::COMPONENTS), $comps, array_keys((array) $b['components'])));
        if (!$comps) {
            throw new \RuntimeException('Aucun élément à restaurer n’a été coché.');
        }
        if ($safety && !$this->backupJob->start(true, true, 'Sécurité avant restauration du ' . Format::idLabel($id))) {
            throw new \RuntimeException('Impossible de lancer la sauvegarde de sécurité.');
        }
        $withDb = in_array('db', $comps, true);
        $r = [
            'id'         => $id,
            'db'         => $withDb,
            'comps'      => array_values(array_diff($comps, ['db'])),
            'delete'     => $deleteAdded,
            'safety'     => $safety,
            'phase'      => $safety ? 'wait' : ($withDb ? 'db_import' : 'files'),
            'db_offset'  => 0,
            'tables'     => [],
            'fks'        => [],
            'ci'         => 0,
            'idx_pos'    => 0,
            'stats'      => ['written' => 0, 'deleted' => 0, 'same' => 0],
            'deleted'    => [],
            'warnings'   => [],
            'wcount'     => [],
            'settings'   => $this->settings->get(),
            'prev_state' => $this->state->get(true),
            'started'    => time(),
            'last_work'  => time(),
            'last_spawn' => 0,
            'user'       => get_current_user_id(),
        ];
        $this->store->save($r);
        $this->logger->log('restore_start', 'Restauration lancée : ' . implode(', ', array_map(function ($c) {
            return Config::SHORT[$c];
        }, $comps)) . ($deleteAdded ? ', suppression des fichiers ajoutés' : '') . ($safety ? ', avec sauvegarde de sécurité' : ''), $id);
        if (!$safety) {
            $this->state->set(['last_status' => 'running', 'last_msg' => 'Restauration en cours…']);
        }
        return $safety ? 'Restauration lancée : une sauvegarde de sécurité est faite d’abord.' : 'Restauration lancée.';
    }

    /** Avance jusqu'à l'échéance. Renvoie true s'il reste du travail. */
    public function step(array $r, float $end): bool
    {
        try {
            if (time() - $r['started'] > Config::MAX_JOB) {
                $this->fail($r, 'Restauration interrompue : durée maximale dépassée (12 h).');
                return false;
            }
            if (!$this->repository->get($r['id'])) {
                $this->fail($r, 'La sauvegarde à restaurer est introuvable.');
                return false;
            }
            $save = function (array $r) {
                $this->store->save($r);
            };
            while (microtime(true) < $end) {
                switch ($r['phase']) {
                    case 'wait':   // la sauvegarde de sécurité est terminée (sinon BackupJob tourne encore)
                        if ('error' === $this->state->get(true)['last_status']) {
                            $this->fail($r, 'La sauvegarde de sécurité a échoué : restauration annulée, rien n’a été modifié.');
                            return false;
                        }
                        $r['phase'] = $r['db'] ? 'db_import' : 'files';
                        break;
                    case 'db_import':
                        if ($this->importer->import($r, $end, $save)) {
                            $r['phase'] = $r['comps'] ? 'files' : 'db_swap';
                        }
                        break;
                    case 'files':
                        if ($this->restorer->restore($r, $end)) {
                            $r['phase'] = $r['delete'] ? 'delete' : ($r['db'] ? 'db_swap' : 'finish');
                            $r['ci']    = 0;
                        }
                        break;
                    case 'delete':
                        $this->restorer->deleteAdded($r);
                        $r['phase'] = $r['db'] ? 'db_swap' : 'finish';
                        break;
                    case 'db_swap':
                        $this->importer->swap($r);
                        $r['phase'] = 'finish';
                        break;
                    default:
                        $this->finish($r);
                        return false;
                }
                $r['last_work'] = time();
                $this->store->save($r);
            }
            return true;
        } catch (\Throwable $e) {
            $this->fail($r, 'Erreur : ' . $e->getMessage());
            return false;
        }
    }

    private function finish(array $r): void
    {
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        $what = [];
        if ($r['db']) {
            $what[] = 'base de données';
        }
        foreach ($r['comps'] as $c) {
            $what[] = mb_strtolower(Config::SHORT[$c]);
        }
        $msg = sprintf(
            'Restauration de la sauvegarde du %s terminée (%s) : %d fichier(s) rétabli(s), %d supprimé(s), %d déjà identique(s).',
            Format::idLabel($r['id']),
            implode(', ', $what),
            $r['stats']['written'],
            $r['stats']['deleted'],
            $r['stats']['same']
        );
        $wcount = $r['wcount'] ?? [];
        if (array_sum($wcount)) {
            $msg .= sprintf(' %d avertissement(s) : voir « Dernière restauration ».', array_sum($wcount));
        }
        $report = [
            'id'       => $r['id'],
            'date'     => time(),
            'msg'      => $msg,
            'warnings' => $r['warnings'] ?? [],
            'wcount'   => $wcount,
            'deleted'  => $r['deleted'],
        ];
        file_put_contents($this->paths->lastRestoreFile(), wp_json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->store->delete();
        $this->logger->log('restore_ok', $msg, $r['id']);
        $prev = is_array($r['prev_state']) ? $r['prev_state'] : [];
        $this->state->set(array_merge($prev, [
            'running'     => false,
            'last_status' => Warnings::status($wcount),
            'last_msg'    => $msg,
            'last_end'    => time(),
        ]));
        $this->gc->run();
    }

    public function fail(array $r, string $msg): void
    {
        if (empty($r['db_swapped'])) {
            $this->importer->dropTemporary();   // le site garde ses tables actuelles
        }
        if (in_array($r['phase'], ['files', 'delete', 'db_swap', 'finish'], true)) {
            $msg .= ' ATTENTION : des fichiers ont déjà été restaurés, le site peut être dans un état mixte. Relancez la restauration, ou restaurez la sauvegarde de sécurité si besoin.';
        } else {
            $msg .= ' Le site n’a pas été modifié.';
        }
        $this->store->delete();
        $this->logger->log('restore_fail', $msg, $r['id']);
        $this->state->set(['running' => false, 'last_end' => time(), 'last_status' => 'error', 'last_msg' => 'Restauration : ' . $msg]);
    }

    /**
     * Annule la restauration si elle n'a encore rien modifié. Renvoie le message à afficher.
     */
    public function cancel(): string
    {
        $r = $this->store->get();
        if (!$r) {
            return '';
        }
        if (!in_array($r['phase'], self::CANCELLABLE, true)) {
            return 'La restauration modifie déjà les fichiers : elle ne peut plus être annulée sans risque.';
        }
        $job = $this->backupJob->current();
        if ($job) {
            $this->backupJob->fail($job, 'Sauvegarde annulée par un administrateur.');
        }
        $this->fail($r, 'Restauration annulée par un administrateur.');
        return 'Restauration annulée : le site n’a pas été modifié.';
    }
}
