<?php

namespace BTB\SiteBackup\Support;

defined('ABSPATH') || exit;

/**
 * Journal d'activité : qui a fait quoi, et quand (500 dernières entrées).
 */
final class Logger
{
    const MAX_BYTES = 300000;
    const KEEP      = 500;

    /** @var Paths */
    private $paths;

    public function __construct(Paths $paths)
    {
        $this->paths = $paths;
    }

    public function log(string $action, string $detail = '', string $id = ''): void
    {
        $user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
        $who  = ($user && !empty($user->user_login)) ? $user->user_login : 'automatique';
        $line = wp_json_encode(['t' => time(), 'who' => $who, 'a' => $action, 'id' => $id, 'd' => $detail], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $file = $this->paths->logFile();
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        if (@filesize($file) > self::MAX_BYTES) {
            $lines = @file($file) ?: [];
            @file_put_contents($file, implode('', array_slice($lines, -self::KEEP)), LOCK_EX);
        }
    }

    /** Dernières entrées, de la plus récente à la plus ancienne. */
    public function entries(int $limit = 100): array
    {
        $file = $this->paths->logFile();
        if (!is_file($file)) {
            return [];
        }
        $out = [];
        foreach (array_reverse(array_slice(file($file) ?: [], -$limit)) as $line) {
            $e = json_decode($line, true);
            if (is_array($e)) {
                $out[] = $e;
            }
        }
        return $out;
    }
}
