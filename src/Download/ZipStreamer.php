<?php

namespace BTB\SiteBackup\Download;

use BTB\SiteBackup\Storage\ObjectStore;

defined('ABSPATH') || exit;

/**
 * Envoi d'archives au navigateur : ZIP « stocké » (sans compression) généré à la volée,
 * compatible avec l'explorateur Windows. Le CRC32 de chaque fichier a été calculé pendant
 * la sauvegarde : aucun recalcul ici.
 */
final class ZipStreamer
{
    /** @var ObjectStore */
    private $store;

    public function __construct(ObjectStore $store)
    {
        $this->store = $store;
    }

    /** @param array $entries [[sha, crc, taille, date, chemin], …] */
    public function stream(array $entries, string $filename): void
    {
        $plan   = [];
        $offset = 0;
        foreach ($entries as $e) {
            list($sha, $crc, $size, $mtime, $rel) = $e;
            $size = (int) $size;
            $obj  = $this->store->path($sha);
            if (!preg_match('/^[0-9a-f]{8}$/', (string) $crc) || !is_file($obj) || filesize($obj) !== $size || $size >= 0xFFFFFFFF) {
                continue;   // fichier manquant dans le stockage, ou trop gros pour un ZIP classique : ignoré
            }
            list($t, $d) = self::dosTime((int) $mtime);
            // version 20, drapeau 0x0800 = noms en UTF-8, méthode 0 = stockage
            $common = pack('vvvvv', 20, 0x0800, 0, $t, $d) . pack('V', hexdec($crc)) . pack('VVvv', $size, $size, strlen($rel), 0);
            $local  = "PK\x03\x04" . $common . $rel;
            $plan[] = [$local, $obj, $common, $offset, $rel];
            $offset += strlen($local) + $size;
            if ($offset >= 0xFFFFFFFF) {
                array_pop($plan);
                break;   // ne devrait pas arriver : une partie fait 1 Gio au plus
            }
        }
        $central = '';
        foreach ($plan as $p) {
            $central .= "PK\x01\x02" . pack('v', 20) . $p[2] . pack('vvvVV', 0, 0, 0, 0, $p[3]) . $p[4];
        }
        $eocd = "PK\x05\x06" . pack('vvvvVVv', 0, 0, count($plan), count($plan), strlen($central), $offset, 0);

        self::headers($filename, $offset + strlen($central) + strlen($eocd), 'application/zip');
        foreach ($plan as $p) {
            echo $p[0];
            $fh = fopen($p[1], 'rb');
            while (!feof($fh)) {
                echo fread($fh, 1048576);
            }
            fclose($fh);
            flush();
        }
        echo $central, $eocd;
        exit;
    }

    /** Envoie un fichier tel quel (dump de la base). */
    public function file(string $path, string $filename, string $type): void
    {
        self::headers($filename, (int) filesize($path), $type);
        readfile($path);
        exit;
    }

    private static function headers(string $filename, int $length, string $type): void
    {
        @set_time_limit(0);
        while (ob_get_level()) {
            ob_end_clean();
        }
        nocache_headers();
        header('Content-Type: ' . $type);
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
        header('Content-Length: ' . $length);
        header('X-Content-Type-Options: nosniff');
    }

    /** Date et heure au format MS-DOS utilisé par le format ZIP. */
    private static function dosTime(int $ts): array
    {
        $d = getdate(max(315532800, $ts));   // pas avant 1980
        return [
            ($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2),
            (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday'],
        ];
    }
}
