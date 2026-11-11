<?php

namespace BTB\SiteBackup\Runner;

use BTB\SiteBackup\Storage\BackupRepository;
use BTB\SiteBackup\Support\Format;
use BTB\SiteBackup\Support\Logger;
use BTB\SiteBackup\Support\State;
use BTB\SiteBackup\Support\Warnings;

defined('ABSPATH') || exit;

/**
 * Auto-réparation du statut : s'il indique « en cours » alors qu'aucune opération ne tourne
 * (écriture concurrente), retrouve le vrai résultat dans la dernière sauvegarde ou dans le journal.
 */
final class StateHealer
{
    const LEGACY_MSG = 'L’opération précédente a été interrompue sans se terminer.';

    private $state;
    private $repository;
    private $logger;

    public function __construct(State $state, BackupRepository $repository, Logger $logger)
    {
        $this->state      = $state;
        $this->repository = $repository;
        $this->logger     = $logger;
    }

    public function heal(): void
    {
        $st = $this->state->get(true);
        // Corrige aussi le faux « interrompue » produit par une ancienne version de cette réparation.
        $legacy = 'error' === $st['last_status'] && self::LEGACY_MSG === $st['last_msg'];
        if (('running' !== $st['last_status'] && !$legacy) || $this->state->busy()) {
            return;
        }
        $latest = $this->repository->all()[0] ?? null;
        $ts     = $latest ? Format::idTimestamp($latest['id']) : null;
        if ($ts && $ts >= (int) $st['last_start'] - 5) {
            $w   = (array) ($latest['wcount'] ?? []);
            $nw  = $w ? array_sum($w) : (int) ($latest['nerrors'] ?? 0);
            $msg = 'Sauvegarde terminée.' . ($nw ? sprintf(' %d avertissement(s) : voir le détail dans la liste.', $nw) : '');
            $this->state->set(['running' => false, 'last_status' => Warnings::status($w), 'last_msg' => $msg]);
            return;
        }
        // Pas de sauvegarde correspondante : supprimée par un administrateur ? (voir le journal)
        $deleted = null;
        foreach ($this->logger->entries(500) as $e) {
            if ('delete' === $e['a'] && $e['id'] >= gmdate('Y-m-d_His', (int) $st['last_start'] - 5)) {
                $deleted = $e;
                break;
            }
        }
        $msg = $deleted
            ? sprintf('Sauvegarde du %s terminée, puis supprimée par %s le %s.', Format::idLabel($deleted['id']), $deleted['who'], Format::date((int) $deleted['t']))
            : 'Aucune sauvegarde ne correspond à cette opération : elle a été supprimée, ou s’est interrompue.';
        $this->state->set(['running' => false, 'last_status' => 'deleted', 'last_msg' => $msg]);
    }
}
