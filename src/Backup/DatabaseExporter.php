<?php

namespace BTB\SiteBackup\Backup;

use BTB\SiteBackup\Config;
use BTB\SiteBackup\Support\Warnings;

defined('ABSPATH') || exit;

/**
 * Export SQL des tables du site (préfixe courant uniquement), par lots, dans un fichier gzip.
 */
final class DatabaseExporter
{
    /** Tables du site (jamais celles d'un autre préfixe ni les tables temporaires de restauration). */
    public function tables(): array
    {
        global $wpdb;
        $like   = $wpdb->esc_like($wpdb->prefix) . '%';
        $tables = $wpdb->get_col($wpdb->prepare("SHOW FULL TABLES WHERE `Tables_in_" . DB_NAME . "` LIKE %s AND Table_type = 'BASE TABLE'", $like));
        if (empty($tables)) {
            $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));
        }
        return array_values(array_filter($tables, function ($t) use ($wpdb) {
            return 0 === strpos($t, $wpdb->prefix);
        }));
    }

    /**
     * Exporte jusqu'à l'échéance. L'avancement (table, dernière clé, décalage) est conservé dans $job.
     * Renvoie true quand toutes les tables sont exportées.
     */
    public function step(array &$job, string $dir, float $end): bool
    {
        global $wpdb;
        $gz = gzopen($dir . '/database.sql.gz', 'ab6');
        if (!$gz) {
            throw new \RuntimeException('impossible d’écrire database.sql.gz');
        }
        if (0 === $job['ti'] && null === $job['last_key'] && 0 === $job['offset']) {
            gzwrite($gz, "-- BTB Sauvegarde " . Config::VERSION . "\n-- Site : " . home_url() . "\n-- Date : " . gmdate('c') . "\n"
                . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");
        }
        while (microtime(true) < $end) {
            if ($job['ti'] >= count($job['tables'])) {
                gzwrite($gz, "\nSET FOREIGN_KEY_CHECKS=1;\n");
                gzclose($gz);
                return true;
            }
            $table = $job['tables'][$job['ti']];
            $qt    = self::quote($table);
            if (null === $job['last_key'] && 0 === $job['offset']) {
                $create = $wpdb->get_row("SHOW CREATE TABLE $qt", ARRAY_N);
                if (!$create) {
                    Warnings::add($job, 'table', $table);
                    $job['ti']++;
                    continue;
                }
                gzwrite($gz, "\nDROP TABLE IF EXISTS $qt;\n" . $create[1] . ";\n");
            }
            // Pagination par clé primaire numérique (rapide), sinon par OFFSET.
            $pk = $this->numericPrimaryKey($table);
            if ($pk) {
                $qpk  = self::quote($pk);
                $from = (null === $job['last_key']) ? '' : $wpdb->prepare(" WHERE $qpk > %d", $job['last_key']);
                $rows = $wpdb->get_results("SELECT * FROM $qt$from ORDER BY $qpk ASC LIMIT " . Config::DB_BATCH, ARRAY_A);
            } else {
                $rows = $wpdb->get_results("SELECT * FROM $qt LIMIT " . (int) $job['offset'] . ', ' . Config::DB_BATCH, ARRAY_A);
            }
            if ($wpdb->last_error) {
                throw new \RuntimeException("lecture de $table : " . $wpdb->last_error);
            }
            if (empty($rows)) {
                $this->nextTable($job);
                continue;
            }
            $this->writeInserts($gz, $qt, $rows);
            $job['rows'] += count($rows);
            if ($pk) {
                $last            = end($rows);
                $job['last_key'] = (int) $last[$pk];
            } else {
                $job['offset'] += count($rows);
            }
            if (count($rows) < Config::DB_BATCH) {
                $this->nextTable($job);
            }
        }
        gzclose($gz);
        return false;
    }

    private function nextTable(array &$job): void
    {
        $job['ti']++;
        $job['last_key'] = null;
        $job['offset']   = 0;
    }

    /** Instructions INSERT d'au plus 1 Mio (compatibles avec max_allowed_packet). */
    private function writeInserts($gz, string $qt, array $rows): void
    {
        $chunk = [];
        $len   = 0;
        foreach ($rows as $row) {
            $vals = [];
            foreach ($row as $v) {
                $vals[] = (null === $v) ? 'NULL' : "'" . self::escape((string) $v) . "'";
            }
            $line = '(' . implode(',', $vals) . ')';
            if ($chunk && $len + strlen($line) > Config::SQL_MAX) {
                gzwrite($gz, "INSERT INTO $qt VALUES\n" . implode(",\n", $chunk) . ";\n");
                $chunk = [];
                $len   = 0;
            }
            $chunk[] = $line;
            $len    += strlen($line) + 2;
        }
        if ($chunk) {
            gzwrite($gz, "INSERT INTO $qt VALUES\n" . implode(",\n", $chunk) . ";\n");
        }
    }

    private function numericPrimaryKey(string $table): ?string
    {
        global $wpdb;
        $qt   = self::quote($table);
        $keys = $wpdb->get_results("SHOW KEYS FROM $qt WHERE Key_name = 'PRIMARY'", ARRAY_A);
        if (!is_array($keys) || 1 !== count($keys)) {
            return null;
        }
        $col  = $keys[0]['Column_name'];
        $info = $wpdb->get_row($wpdb->prepare("SHOW COLUMNS FROM $qt WHERE Field = %s", $col), ARRAY_A);
        return ($info && preg_match('/int/i', $info['Type'])) ? $col : null;
    }

    public static function quote(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /** Échappement MySQL d'une valeur (binaire compris). */
    public static function escape(string $v): string
    {
        return strtr($v, ["\\" => "\\\\", "\0" => "\\0", "\n" => "\\n", "\r" => "\\r", "'" => "\\'", '"' => '\\"', "\x1a" => "\\Z"]);
    }
}
