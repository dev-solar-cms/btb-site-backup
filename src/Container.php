<?php

namespace BTB\SiteBackup;

use BTB\SiteBackup\Admin\AdminPage;
use BTB\SiteBackup\Admin\Progress;
use BTB\SiteBackup\Backup\BackupJob;
use BTB\SiteBackup\Backup\DatabaseExporter;
use BTB\SiteBackup\Backup\FileCollector;
use BTB\SiteBackup\Backup\FileScanner;
use BTB\SiteBackup\Download\ZipStreamer;
use BTB\SiteBackup\Restore\DatabaseImporter;
use BTB\SiteBackup\Restore\FileRestorer;
use BTB\SiteBackup\Restore\RestoreJob;
use BTB\SiteBackup\Runner\Runner;
use BTB\SiteBackup\Runner\Scheduler;
use BTB\SiteBackup\Runner\StateHealer;
use BTB\SiteBackup\Storage\BackupRepository;
use BTB\SiteBackup\Storage\GarbageCollector;
use BTB\SiteBackup\Storage\ObjectStore;
use BTB\SiteBackup\Storage\RestoreStateStore;
use BTB\SiteBackup\Support\Lock;
use BTB\SiteBackup\Support\Logger;
use BTB\SiteBackup\Support\Paths;
use BTB\SiteBackup\Support\Settings;
use BTB\SiteBackup\Support\State;

defined('ABSPATH') || exit;

/**
 * Assemble les services de l'extension (chacun n'est créé qu'une fois, à la demande).
 */
final class Container
{
    /** @var array<string, object> */
    private $services = [];

    private function once(string $key, callable $factory)
    {
        if (!isset($this->services[$key])) {
            $this->services[$key] = $factory();
        }
        return $this->services[$key];
    }

    public function paths(): Paths
    {
        return $this->once('paths', function () {
            return new Paths();
        });
    }

    public function settings(): Settings
    {
        return $this->once('settings', function () {
            return new Settings();
        });
    }

    public function restoreStore(): RestoreStateStore
    {
        return $this->once('restoreStore', function () {
            return new RestoreStateStore($this->paths());
        });
    }

    public function state(): State
    {
        return $this->once('state', function () {
            return new State($this->restoreStore());
        });
    }

    public function lock(): Lock
    {
        return $this->once('lock', function () {
            return new Lock($this->paths());
        });
    }

    public function logger(): Logger
    {
        return $this->once('logger', function () {
            return new Logger($this->paths());
        });
    }

    public function objectStore(): ObjectStore
    {
        return $this->once('objectStore', function () {
            return new ObjectStore($this->paths());
        });
    }

    public function repository(): BackupRepository
    {
        return $this->once('repository', function () {
            return new BackupRepository($this->paths());
        });
    }

    public function gc(): GarbageCollector
    {
        return $this->once('gc', function () {
            return new GarbageCollector($this->paths(), $this->repository(), $this->state(), $this->objectStore());
        });
    }

    public function exporter(): DatabaseExporter
    {
        return $this->once('exporter', function () {
            return new DatabaseExporter();
        });
    }

    public function scanner(): FileScanner
    {
        return $this->once('scanner', function () {
            return new FileScanner($this->paths());
        });
    }

    public function collector(): FileCollector
    {
        return $this->once('collector', function () {
            return new FileCollector($this->paths(), $this->objectStore(), $this->repository());
        });
    }

    public function backupJob(): BackupJob
    {
        return $this->once('backupJob', function () {
            return new BackupJob(
                $this->paths(),
                $this->settings(),
                $this->state(),
                $this->logger(),
                $this->repository(),
                $this->gc(),
                $this->restoreStore(),
                $this->exporter(),
                $this->scanner(),
                $this->collector()
            );
        });
    }

    public function importer(): DatabaseImporter
    {
        return $this->once('importer', function () {
            return new DatabaseImporter($this->paths(), $this->settings());
        });
    }

    public function restorer(): FileRestorer
    {
        return $this->once('restorer', function () {
            return new FileRestorer($this->paths());
        });
    }

    public function restoreJob(): RestoreJob
    {
        return $this->once('restoreJob', function () {
            return new RestoreJob(
                $this->paths(),
                $this->settings(),
                $this->state(),
                $this->logger(),
                $this->repository(),
                $this->restoreStore(),
                $this->backupJob(),
                $this->importer(),
                $this->restorer(),
                $this->gc()
            );
        });
    }

    public function healer(): StateHealer
    {
        return $this->once('healer', function () {
            return new StateHealer($this->state(), $this->repository(), $this->logger());
        });
    }

    public function runner(): Runner
    {
        return $this->once('runner', function () {
            return new Runner($this->lock(), $this->state(), $this->backupJob(), $this->restoreJob(), $this->restoreStore(), $this->healer());
        });
    }

    public function scheduler(): Scheduler
    {
        return $this->once('scheduler', function () {
            return new Scheduler($this->state(), $this->settings(), $this->runner(), $this->backupJob(), $this->restoreStore());
        });
    }

    public function progress(): Progress
    {
        return $this->once('progress', function () {
            return new Progress($this->paths(), $this->state(), $this->repository(), $this->restoreStore(), $this->backupJob());
        });
    }

    public function zip(): ZipStreamer
    {
        return $this->once('zip', function () {
            return new ZipStreamer($this->objectStore());
        });
    }

    public function adminPage(): AdminPage
    {
        return $this->once('adminPage', function () {
            return new AdminPage($this);
        });
    }
}
