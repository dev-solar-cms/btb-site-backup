<?php

namespace BTB\SiteBackup\Admin;

use BTB\SiteBackup\Backup\BackupJob;
use BTB\SiteBackup\Config;
use BTB\SiteBackup\Storage\BackupRepository;
use BTB\SiteBackup\Storage\RestoreStateStore;
use BTB\SiteBackup\Support\Paths;
use BTB\SiteBackup\Support\State;

defined('ABSPATH') || exit;

/**
 * Avancement de l'opération en cours (barre de progression) et message de fin.
 */
final class Progress
{
    private $paths;
    private $state;
    private $repository;
    private $restore;
    private $backupJob;

    public function __construct(Paths $paths, State $state, BackupRepository $repository, RestoreStateStore $restore, BackupJob $backupJob)
    {
        $this->paths      = $paths;
        $this->state      = $state;
        $this->repository = $repository;
        $this->restore    = $restore;
        $this->backupJob  = $backupJob;
    }

    /** Pourcentage, étape courante / nombre d'étapes, libellé et détail. */
    public function data(): array
    {
        $job = $this->backupJob->current();
        $r   = $this->restore->get();
        if ($r) {
            list($kind, $steps, $i, $frac, $detail) = array_merge(['Restauration'], $this->restoreProgress($r, $job));
        } elseif ($job) {
            list($kind, $steps, $i, $frac, $detail) = array_merge(['Sauvegarde'], $this->backupProgress($job));
        } else {
            return ['kind' => '', 'percent' => 100, 'step' => 0, 'steps' => 0, 'label' => '', 'detail' => ''];
        }
        $n = count($steps);
        return [
            'kind'    => $kind,
            'percent' => (int) min(99, floor(100 * ($i + $frac) / max(1, $n))),
            'step'    => $i + 1,
            'steps'   => $n,
            'label'   => $steps[$i],
            'detail'  => $detail,
        ];
    }

    /** Résumé d'une ligne (affichage sans JavaScript). */
    public function text(): string
    {
        $p = $this->data();
        return $p['steps'] ? sprintf('%s — étape %d/%d : %s (%d %%)', $p['kind'], $p['step'], $p['steps'], $p['label'], $p['percent']) : '';
    }

    /** Message de fin : titre selon le résultat de la dernière opération. */
    public function done(): array
    {
        $st   = $this->state->get(true);
        $what = 0 === strpos((string) $st['last_msg'], 'Restauration') ? 'Restauration' : 'Sauvegarde';
        switch ($st['last_status']) {
            case 'ok':
                $title = $what . ' terminée avec succès.';
                break;
            case 'warning':
                $title = $what . ' terminée, avec des avertissements à consulter.';
                break;
            case 'error':
                $title = 'Échec : ' . mb_strtolower($what) . ' non terminée.';
                break;
            default:
                $title = $what . ' terminée.';
        }
        return ['status' => $st['last_status'], 'title' => $title, 'msg' => (string) $st['last_msg']];
    }

    /** @return array [libellés des étapes, index courant, fraction de l'étape, détail] */
    private function backupProgress(array $job): array
    {
        $steps = [];
        $keys  = [];
        if ($job['db']) {
            $steps[] = 'Base de données';
            $keys[]  = 'db';
        }
        if ($job['comps']) {
            $steps[] = 'Inventaire des fichiers';
            $keys[]  = 'scan';
            foreach ($job['comps'] as $c) {
                $steps[] = Config::SHORT[$c];
                $keys[]  = 'c:' . $c;
            }
        }
        $steps[] = 'Finalisation';
        $keys[]  = 'finish';
        $frac    = 0;
        $detail  = '';
        $key     = 'finish';
        switch ($job['phase']) {
            case 'db':
                $key    = 'db';
                $n      = max(1, count($job['tables']));
                $frac   = min(1, $job['ti'] / $n);
                $detail = sprintf('Table %d / %d — %s lignes exportées', min($job['ti'] + 1, $n), $n, number_format_i18n($job['rows']));
                break;
            case 'scan':
                $key    = 'scan';
                $detail = 'Recherche des fichiers à sauvegarder…';
                break;
            case 'files':
                if ($job['ci'] >= count($job['comps'])) {
                    break;
                }
                $c      = $job['comps'][$job['ci']];
                $key    = 'c:' . $c;
                $tot    = (int) ($job['totals'][$c]['count'] ?? 0);
                $don    = (int) $job['stats'][$c]['count'];
                $frac   = $tot ? min(1, $don / $tot) : 1;
                $detail = sprintf('%s / %s fichiers — %s nouveaux ou modifiés copiés', number_format_i18n($don), number_format_i18n($tot), number_format_i18n($job['stats'][$c]['new_count']));
                break;
        }
        if ('finish' === $key) {
            $detail = 'Écriture du résumé et rotation des anciennes sauvegardes…';
        }
        return [$steps, (int) array_search($key, $keys, true), $frac, $detail];
    }

    private function restoreProgress(array $r, ?array $job): array
    {
        $steps = [];
        $keys  = [];
        if (!empty($r['safety'])) {
            $steps[] = 'Sauvegarde de sécurité';
            $keys[]  = 'safety';
        }
        if ($r['db']) {
            $steps[] = 'Import de la base';
            $keys[]  = 'db_import';
        }
        foreach ($r['comps'] as $c) {
            $steps[] = 'Restauration : ' . Config::SHORT[$c];
            $keys[]  = 'c:' . $c;
        }
        if ($r['delete']) {
            $steps[] = 'Suppression des fichiers ajoutés';
            $keys[]  = 'delete';
        }
        if ($r['db']) {
            $steps[] = 'Remplacement des tables';
            $keys[]  = 'db_swap';
        }
        $steps[] = 'Finalisation';
        $keys[]  = 'finish';
        $frac    = 0;
        $detail  = '';
        $key     = 'finish';
        if ($job) {   // sauvegarde de sécurité en cours
            list($bs, $bi, $bf, $bd) = $this->backupProgress($job);
            $key    = 'safety';
            $frac   = ($bi + $bf) / max(1, count($bs));
            $detail = $bs[$bi] . ' — ' . $bd;
        } else {
            switch ($r['phase']) {
                case 'wait':
                    $key  = 'safety';
                    $frac = 1;
                    break;
                case 'db_import':
                    $key    = 'db_import';
                    $b      = $this->repository->get($r['id']);
                    $n      = max(1, (int) ($b['components']['db']['tables'] ?? 1));
                    $frac   = min(1, count($r['tables']) / $n);
                    $detail = sprintf('%d / %d tables importées dans des tables temporaires — le site n’est pas encore modifié', count($r['tables']), $n);
                    break;
                case 'files':
                    if ($r['ci'] >= count($r['comps'])) {
                        $key = $r['delete'] ? 'delete' : ($r['db'] ? 'db_swap' : 'finish');
                        break;
                    }
                    $c      = $r['comps'][$r['ci']];
                    $key    = 'c:' . $c;
                    $idx    = $this->repository->indexFile($r['id'], $c);
                    $size   = is_file($idx) ? filesize($idx) : 0;
                    $frac   = $size ? min(1, $r['idx_pos'] / $size) : 1;
                    $detail = sprintf('%s fichier(s) rétabli(s), %s déjà identique(s)', number_format_i18n($r['stats']['written']), number_format_i18n($r['stats']['same']));
                    break;
                case 'delete':
                    $key    = 'delete';
                    $detail = 'Suppression des fichiers apparus depuis la sauvegarde…';
                    break;
                case 'db_swap':
                    $key    = 'db_swap';
                    $detail = 'Échange des tables (instantané)…';
                    break;
            }
        }
        return [$steps, (int) array_search($key, $keys, true), $frac, $detail];
    }
}
