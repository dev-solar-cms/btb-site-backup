<?php

namespace BTB\SiteBackup\Runner;

use BTB\SiteBackup\Backup\BackupJob;
use BTB\SiteBackup\Storage\RestoreStateStore;
use BTB\SiteBackup\Support\Settings;
use BTB\SiteBackup\Support\State;

defined('ABSPATH') || exit;

/**
 * Planification sans cron : à la fin de chaque requête, démarre la sauvegarde quand elle est due
 * et relance le travail en cours par une requête interne non bloquante.
 */
final class Scheduler
{
    const SPAWN_EVERY   = 30;    // secondes entre deux relances
    const FALLBACK_IDLE = 600;   // sans avancement depuis 10 min : avancer dans la requête courante

    private $state;
    private $settings;
    private $runner;
    private $backupJob;
    private $restore;

    public function __construct(State $state, Settings $settings, Runner $runner, BackupJob $backupJob, RestoreStateStore $restore)
    {
        $this->state     = $state;
        $this->settings  = $settings;
        $this->runner    = $runner;
        $this->backupJob = $backupJob;
        $this->restore   = $restore;
    }

    /** Délai avant nouvel essai : 1 h après un échec, sinon le délai réglé. */
    private function wait(array $st): int
    {
        return ('error' === $st['last_status']) ? HOUR_IN_SECONDS : $this->settings->get()['interval'] * HOUR_IN_SECONDS;
    }

    public function isDue(): bool
    {
        $st = $this->state->get();
        if ($st['running']) {
            return false;
        }
        return !$st['last_start'] || time() >= $st['last_start'] + $this->wait($st);
    }

    /** Date de la prochaine sauvegarde automatique (null : à la prochaine visite). */
    public function nextRun(): ?int
    {
        $st = $this->state->get(true);
        return $st['last_start'] ? $st['last_start'] + $this->wait($st) : null;
    }

    public function onShutdown(): void
    {
        if ($this->runner->isStepping() || wp_installing() || (defined('WP_CLI') && WP_CLI)) {
            return;
        }
        $restore = $this->restore->exists() ? $this->restore->get() : null;
        $st      = $this->state->get();
        if (!$restore && !$st['running'] && !$this->isDue()) {
            return;   // cas courant : une lecture d'option autoloadée et un test de fichier
        }
        if (!$restore && !$st['running']) {
            $this->backupJob->start(false, false, '');
        }
        $now       = time();
        $ticks     = $this->state->ticks();
        $lastSpawn = $restore ? (int) ($restore['last_spawn'] ?? 0) : (int) $ticks['last_spawn'];
        $lastWork  = $restore ? (int) ($restore['last_work'] ?? 0) : (int) $ticks['last_work'];
        if ($now - $lastSpawn >= self::SPAWN_EVERY) {
            $this->spawn();
        }
        // Secours : si les requêtes internes n'aboutissent pas, avancer ici, après avoir rendu la page au visiteur.
        if ($now - $lastWork > self::FALLBACK_IDLE && function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            $this->runner->step();
        }
    }

    /** Requête non bloquante vers admin-ajax pour faire avancer le travail. */
    public function spawn(): void
    {
        $r = $this->restore->get();
        if ($r) {
            $r['last_spawn'] = time();
            $this->restore->save($r);
        } else {
            $this->state->tick('last_spawn');
        }
        wp_remote_post(admin_url('admin-ajax.php'), [
            'timeout'   => 0.01,
            'blocking'  => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'body'      => ['action' => 'btb_backup_step', 'token' => $this->state->token()],
        ]);
    }
}
