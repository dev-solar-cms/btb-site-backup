<?php

namespace BTB\SiteBackup\Backup;

use BTB\SiteBackup\Config;
use BTB\SiteBackup\Storage\BackupRepository;
use BTB\SiteBackup\Storage\IndexFile;
use BTB\SiteBackup\Storage\ObjectStore;
use BTB\SiteBackup\Support\Paths;
use BTB\SiteBackup\Support\Warnings;

defined('ABSPATH') || exit;

/**
 * Copie incrémentale : seuls les fichiers nouveaux ou modifiés depuis la sauvegarde précédente
 * sont copiés dans le stockage ; les autres sont simplement référencés.
 */
final class FileCollector
{
    /** @var Paths */
    private $paths;
    /** @var ObjectStore */
    private $store;
    /** @var BackupRepository */
    private $repository;
    /** @var array chemin => « taille\tdate\tsha1\tcrc32 », par composant */
    private $prevIndex = [];
    /** @var array sha1 => true, par composant */
    private $prevShas = [];

    public function __construct(Paths $paths, ObjectStore $store, BackupRepository $repository)
    {
        $this->paths      = $paths;
        $this->store      = $store;
        $this->repository = $repository;
    }

    public function reset(): void
    {
        $this->prevIndex = [];
        $this->prevShas  = [];
    }

    /** Index de la sauvegarde complète la plus récente pour un composant. */
    private function previous(string $comp): array
    {
        if (isset($this->prevIndex[$comp])) {
            return $this->prevIndex[$comp];
        }
        $map  = [];
        $shas = [];
        foreach ($this->repository->all() as $b) {
            $file = $this->repository->indexFile($b['id'], $comp);
            if (!is_file($file)) {
                continue;
            }
            IndexFile::read($file, function ($sha, $crc, $size, $mtime, $rel) use (&$map, &$shas) {
                $map[$rel]  = $size . "\t" . $mtime . "\t" . $sha . "\t" . $crc;
                $shas[$sha] = true;
            });
            break;
        }
        $this->prevShas[$comp] = $shas;
        return $this->prevIndex[$comp] = $map;
    }

    /** Traite un lot de fichiers du composant en cours. Renvoie true quand tous les composants sont faits. */
    public function step(array &$job, string $dir, float $end): bool
    {
        if ($job['ci'] >= count($job['comps'])) {
            return true;
        }
        $c    = $job['comps'][$job['ci']];
        $root = $this->paths->projectRoot();
        $prev = $this->previous($c);
        $list = fopen($dir . '/list-' . $c . '.txt', 'rb');
        $idx  = fopen($dir . '/index-' . $c . '.txt', 'c+b');
        fseek($list, $job['list_pos']);
        ftruncate($idx, $job['idx_pos']);   // annule un éventuel lot interrompu
        fseek($idx, $job['idx_pos']);
        $n   = 0;
        $eof = false;
        while ($n < Config::BATCH && microtime(true) < $end) {
            $line = fgets($list);
            if (false === $line) {
                $eof = true;
                break;
            }
            $p = explode("\t", rtrim($line, "\n"), 3);
            if (3 !== count($p)) {
                continue;
            }
            list($size, $mtime, $rel) = $p;
            $sha = null;
            $crc = null;
            if (isset($prev[$rel])) {
                list($ps, $pm, $psha, $pcrc) = explode("\t", $prev[$rel]);
                if ($ps === $size && $pm === $mtime && $this->store->has($psha)) {
                    $sha = $psha;   // inchangé : rien à recopier
                    $crc = $pcrc;
                }
            }
            if (null === $sha) {
                $added = false;
                $sha   = $this->store->store($root . '/' . $rel, $added, $crc);
                if (null === $sha) {
                    if (file_exists($root . '/' . $rel)) {
                        Warnings::add($job, 'unreadable', $rel, $this->paths->isCritical($rel) ? 'eleve' : 'moyen');
                    } else {
                        Warnings::add($job, 'vanished', $rel);
                    }
                    continue;
                }
                // « Nouveau » = contenu absent de la sauvegarde précédente (fiable même après une reprise).
                if ($added || !isset($this->prevShas[$c][$sha])) {
                    $job['stats'][$c]['new_count']++;
                    $job['stats'][$c]['new_bytes'] += (int) $size;
                }
            }
            fwrite($idx, $sha . "\t" . $crc . "\t" . $size . "\t" . $mtime . "\t" . $rel . "\n");
            $job['stats'][$c]['count']++;
            $job['stats'][$c]['bytes'] += (int) $size;
            $n++;
        }
        $job['list_pos'] = ftell($list);
        $job['idx_pos']  = ftell($idx);
        fclose($list);
        fclose($idx);
        if ($eof) {
            @unlink($dir . '/list-' . $c . '.txt');
            $job['ci']++;
            $job['list_pos'] = 0;
            $job['idx_pos']  = 0;
        }
        return $job['ci'] >= count($job['comps']);
    }
}
