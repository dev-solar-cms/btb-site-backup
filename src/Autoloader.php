<?php

namespace BTB\SiteBackup;

defined('ABSPATH') || exit;

/**
 * Chargement automatique des classes (PSR-4) : BTB\SiteBackup\Backup\BackupJob -> src/Backup/BackupJob.php
 */
final class Autoloader
{
    public static function register(string $prefix, string $baseDir): void
    {
        spl_autoload_register(static function ($class) use ($prefix, $baseDir) {
            if (0 !== strpos($class, $prefix)) {
                return;
            }
            $file = $baseDir . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }
}
