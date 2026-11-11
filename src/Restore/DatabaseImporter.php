<?php

namespace BTB\SiteBackup\Restore;

use BTB\SiteBackup\Backup\DatabaseExporter;
use BTB\SiteBackup\Config;
use BTB\SiteBackup\Support\Paths;
use BTB\SiteBackup\Support\Settings;
use BTB\SiteBackup\Support\Warnings;

defined('ABSPATH') || exit;

/**
 * Restauration de la base en deux temps :
 *  1. import du dump dans des tables temporaires « r_… » (le site continue de fonctionner) ;
 *  2. échange atomique (RENAME TABLE) puis remise en place des clés étrangères.
 * Une erreur pendant l'import laisse le site intact.
 */
final class DatabaseImporter
{
    /** @var Paths */
    private $paths;
    /** @var Settings */
    private $settings;

    public function __construct(Paths $paths, Settings $settings)
    {
        $this->paths    = $paths;
        $this->settings = $settings;
    }

    /** Importe jusqu'à l'échéance ; reprise possible à tout moment. Renvoie true quand le dump est entièrement lu. */
    public function import(array &$r, float $end, callable $checkpoint): bool
    {
        $file = $this->paths->backupPath($r['id']) . '/database.sql.gz';
        $gz   = gzopen($file, 'rb');
        if (!$gz) {
            throw new \RuntimeException('dump de la base introuvable');
        }
        if ($r['db_offset'] > 0) {
            gzseek($gz, $r['db_offset']);
        }
        $this->session();
        $buf  = '';
        $done = 0;
        while (microtime(true) < $end) {
            $line = gzgets($gz);
            if (false === $line) {
                gzclose($gz);
                return true;
            }
            if ('' === $buf && ('' === trim($line) || 0 === strpos($line, '--'))) {
                $r['db_offset'] = gztell($gz);
                continue;
            }
            $buf .= $line;
            if (';' !== substr(rtrim($line, "\r\n"), -1)) {
                continue;
            }
            $this->statement($r, rtrim($buf, "\r\n"));
            $buf            = '';
            $r['db_offset'] = gztell($gz);
            if (++$done % 20 === 0) {
                $checkpoint($r);
            }
        }
        gzclose($gz);
        return false;
    }

    /** Exécute une instruction du dump en la redirigeant vers la table temporaire. */
    private function statement(array &$r, string $sql): void
    {
        global $wpdb;
        if (0 === stripos($sql, 'SET ')) {
            return;   // réglages de session déjà appliqués
        }
        if (!preg_match('/^(DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO) `((?:[^`]|``)+)`/', $sql, $m)) {
            Warnings::add($r, 'sql_skip', substr($sql, 0, 80));
            return;
        }
        $table = str_replace('``', '`', $m[2]);
        if (0 !== strpos($table, $wpdb->prefix) || strlen(Config::TMP_PFX . $table) > 64) {
            Warnings::add($r, 'sql_skip', $table);
            return;
        }
        $tmp  = DatabaseExporter::quote(Config::TMP_PFX . $table);
        $rest = substr($sql, strlen($m[0]));
        switch ($m[1]) {
            case 'DROP TABLE IF EXISTS':
                $this->query("DROP TABLE IF EXISTS $tmp");
                return;
            case 'CREATE TABLE':
                $this->query("CREATE TABLE IF NOT EXISTS $tmp" . $this->withoutForeignKeys($r, $table, $rest));
                if (!in_array($table, $r['tables'], true)) {
                    $r['tables'][] = $table;
                }
                return;
            default:
                $this->insert($tmp, $rest);
        }
    }

    /**
     * Retire les clés étrangères de la définition (elles sont rajoutées après l'échange :
     * sinon MySQL les redirigerait vers les anciennes tables au moment du RENAME).
     */
    private function withoutForeignKeys(array &$r, string $table, string $definition): string
    {
        $fks   = [];
        $lines = explode("\n", $definition);
        foreach ($lines as $i => $l) {
            if (preg_match('/^\s*(CONSTRAINT `(?:[^`]|``)+` )?FOREIGN KEY .+?,?$/', $l)) {
                $fks[] = rtrim(trim($l), ',');
                unset($lines[$i]);
            }
        }
        $lines = array_values($lines);
        foreach ($lines as $i => $l) {   // retire la virgule laissée sur la dernière définition
            if (isset($lines[$i + 1]) && preg_match('/^\s*\)/', $lines[$i + 1])) {
                $lines[$i] = rtrim($l, ',');
            }
        }
        if ($fks) {
            $r['fks'][$table] = $fks;
        }
        return implode("\n", $lines);
    }

    /** INSERT IGNORE par paquets de 1 Mio : rejouer après une interruption ne crée pas de doublon. */
    private function insert(string $tmp, string $rest): void
    {
        $rows = explode(",\n", ltrim(substr($rest, strlen(' VALUES')), "\n"));
        $rows[count($rows) - 1] = rtrim(end($rows), ';');
        $chunk = [];
        $len   = 0;
        foreach ($rows as $row) {
            if ($chunk && $len + strlen($row) > Config::SQL_MAX) {
                $this->query("INSERT IGNORE INTO $tmp VALUES\n" . implode(",\n", $chunk));
                $chunk = [];
                $len   = 0;
            }
            $chunk[] = $row;
            $len    += strlen($row) + 2;
        }
        if ($chunk) {
            $this->query("INSERT IGNORE INTO $tmp VALUES\n" . implode(",\n", $chunk));
        }
    }

    /** Remplace d'un coup les tables du site par les tables restaurées, puis remet les clés étrangères. */
    public function swap(array &$r): void
    {
        global $wpdb;
        $existing = array_flip($this->like($wpdb->prefix));
        $tmpLeft  = array_flip($this->like(Config::TMP_PFX . $wpdb->prefix));
        $renames  = [];
        $drops    = [];
        foreach ($r['tables'] as $t) {
            if (!isset($tmpLeft[Config::TMP_PFX . $t])) {
                continue;   // déjà échangée (reprise après interruption)
            }
            if (isset($existing[$t])) {
                $drops[]   = DatabaseExporter::quote(Config::OLD_PFX . $t);
                $renames[] = DatabaseExporter::quote($t) . ' TO ' . DatabaseExporter::quote(Config::OLD_PFX . $t);
            }
            $renames[] = DatabaseExporter::quote(Config::TMP_PFX . $t) . ' TO ' . DatabaseExporter::quote($t);
        }
        if ($renames) {
            $this->session();
            if ($drops) {
                $this->query('DROP TABLE IF EXISTS ' . implode(', ', $drops));
            }
            $this->query('RENAME TABLE ' . implode(', ', $renames));   // atomique
        }
        $olds = $this->like(Config::OLD_PFX . $wpdb->prefix);
        if ($olds) {
            $this->session();
            $this->query('DROP TABLE IF EXISTS ' . implode(', ', array_map([DatabaseExporter::class, 'quote'], $olds)));
        }
        $this->restoreForeignKeys($r);
        $r['fks'] = [];
        // Tables présentes sur le site mais absentes de la sauvegarde : laissées en place, mais signalées.
        foreach (array_diff($this->like($wpdb->prefix), $r['tables']) as $extra) {
            if (0 === strpos($extra, $wpdb->prefix)) {
                Warnings::add($r, 'extra_table', $extra);
            }
        }
        // Les options de l'extension viennent d'être remplacées par celles de la sauvegarde : on remet les actuelles.
        wp_cache_flush();
        delete_option(Config::OPT_JOB);
        $this->settings->restore($r['settings']);
        $r['db_swapped'] = true;
    }

    private function restoreForeignKeys(array &$r): void
    {
        global $wpdb;
        $this->session();
        foreach ((array) ($r['fks'] ?? []) as $t => $fks) {
            foreach ($fks as $fk) {
                $name = preg_match('/^CONSTRAINT `((?:[^`]|``)+)`/', $fk, $m) ? str_replace('``', '`', $m[1]) : null;
                if ($name && $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = %s AND CONSTRAINT_NAME = %s', $t, $name))) {
                    continue;   // déjà présente (reprise après interruption)
                }
                if (false === $wpdb->query('ALTER TABLE ' . DatabaseExporter::quote($t) . ' ADD ' . $fk)) {
                    Warnings::add($r, 'fk', $t . ' : ' . $fk);
                }
            }
        }
    }

    /** Supprime les tables temporaires (annulation ou échec avant l'échange). */
    public function dropTemporary(): void
    {
        global $wpdb;
        $tmps = $this->like(Config::TMP_PFX . $wpdb->prefix);
        if ($tmps) {
            $wpdb->query('DROP TABLE IF EXISTS ' . implode(', ', array_map([DatabaseExporter::class, 'quote'], $tmps)));
        }
    }

    private function like(string $prefix): array
    {
        global $wpdb;
        return (array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($prefix) . '%'));
    }

    private function session(): void
    {
        global $wpdb;
        $wpdb->query('SET NAMES utf8mb4');
        $wpdb->query('SET FOREIGN_KEY_CHECKS=0');
        $wpdb->query("SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'");
    }

    private function query(string $sql): void
    {
        global $wpdb;
        if (false === $wpdb->query($sql)) {
            throw new \RuntimeException('base de données : ' . ($wpdb->last_error ?: 'requête refusée'));
        }
    }
}
