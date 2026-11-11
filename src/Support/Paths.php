<?php

namespace BTB\SiteBackup\Support;

defined('ABSPATH') || exit;

/**
 * Chemins du projet : racine (Bedrock ou WordPress classique), dossier de stockage,
 * classement des fichiers par composant, exclusions et fichiers protégés.
 */
final class Paths
{
    /** @var string|null */
    private $root;

    /** Racine du projet : dossier parent de web/ pour Bedrock, sinon racine WordPress. */
    public function projectRoot(): string
    {
        if (null === $this->root) {
            $web  = dirname(untrailingslashit(ABSPATH));
            $root = dirname($web);
            $this->root = (is_file($root . '/composer.json') && is_dir($root . '/config') && is_file($web . '/wp-config.php'))
                ? $root
                : untrailingslashit(ABSPATH);
        }
        return $this->root;
    }

    public function isBedrock(): bool
    {
        return $this->projectRoot() !== untrailingslashit(ABSPATH);
    }

    /** Dossier de stockage : hors de la racine web pour Bedrock. Créé et protégé si besoin. */
    public function backupDir(): string
    {
        $dir = $this->isBedrock() ? $this->projectRoot() . '/backups' : WP_CONTENT_DIR . '/btb-backups';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $this->protect($dir);
        return $dir;
    }

    /** Défense en profondeur, même si le dossier n'est pas censé être accessible par le web. */
    public function protect(string $dir): void
    {
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        if (!is_file($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php // Silence.\n");
        }
    }

    public function backupPath(string $id): string
    {
        return $this->backupDir() . '/' . $id;
    }

    public function storeDir(): string
    {
        return $this->backupDir() . '/store';
    }

    public function objPath(string $sha): string
    {
        return $this->storeDir() . '/' . substr($sha, 0, 2) . '/' . $sha;
    }

    public function restoreFile(): string
    {
        return $this->backupDir() . '/restore.json';
    }

    public function lastRestoreFile(): string
    {
        return $this->backupDir() . '/last-restore.json';
    }

    public function logFile(): string
    {
        return $this->backupDir() . '/activity.log';
    }

    public function lockFile(): string
    {
        return $this->backupDir() . '/.lock';
    }

    /** Chemin relatif à la racine du projet (null si hors du projet). */
    public function relPath(string $abs): ?string
    {
        $root = wp_normalize_path($this->projectRoot());
        $abs  = wp_normalize_path((string) realpath($abs));
        return (0 === strpos($abs, $root . '/')) ? substr($abs, strlen($root) + 1) : null;
    }

    /** Préfixe relatif de chaque composant de fichiers (extensions, thèmes, médias). */
    public function componentPrefixes(): array
    {
        $up  = wp_upload_dir(null, false);
        $out = [];
        foreach (['plugins' => WP_PLUGIN_DIR, 'themes' => get_theme_root(), 'uploads' => $up['basedir']] as $c => $abs) {
            $rel = $this->relPath($abs);
            if ($rel) {
                $out[$c] = $rel . '/';
            }
        }
        return $out;
    }

    public function componentOf(string $rel, array $prefixes): string
    {
        foreach ($prefixes as $c => $p) {
            if (0 === strpos($rel, $p)) {
                return $c;
            }
        }
        return 'others';
    }

    /** Fichiers jamais écrasés ni supprimés par une restauration : .env et l'extension elle-même. */
    public function isProtected(string $rel): bool
    {
        static $self = null;
        if (null === $self) {
            $self = (string) $this->relPath(BTB_BACKUP_DIR);
        }
        return '.env' === $rel || ('' !== $self && 0 === strpos($rel, $self . '/'));
    }

    /** Fichiers critiques (configuration) : un problème sur eux est plus grave. */
    public function isCritical(string $rel): bool
    {
        return (bool) preg_match('#^(\.env$|config/|web/wp-config\.php$|wp-config\.php$|web/app/mu-plugins/|wp-content/mu-plugins/)#', $rel);
    }

    /** Dossiers et fichiers jamais sauvegardés. */
    public function excludes(): array
    {
        $root   = wp_normalize_path($this->projectRoot());
        $backup = ltrim(substr(wp_normalize_path($this->backupDir()), strlen($root)), '/');
        return apply_filters('btb_backup_excludes', [
            '#^' . preg_quote($backup, '#') . '(/|$)#',
            '#(^|/)\.git(/|$)#',
            '#(^|/)node_modules(/|$)#',
            '#(^|/)(app|wp-content)/(updraft[^/]*|wflogs|cache|upgrade|upgrade-temp-backup|ai1wm-backups|backups?|btb-backups)(/|$)#',
            '#(^|/)temp-write-test-[^/]*(/|$)#',
            '#(^|/)(debug\.log|error_log)$#',
            '#\.btbtmp$#',
        ]);
    }

    /** Parcourt les fichiers du projet (hors exclusions) : appelle $fn(chemin relatif, SplFileInfo). */
    public function walk(callable $fn): void
    {
        $root    = wp_normalize_path($this->projectRoot());
        $exclude = $this->excludes();
        $it      = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                function ($f) use ($root, $exclude) {
                    if ($f->isLink()) {
                        return false;
                    }
                    $rel = ltrim(substr(wp_normalize_path($f->getPathname()), strlen($root)), '/');
                    foreach ($exclude as $rx) {
                        if (preg_match($rx, $rel)) {
                            return false;
                        }
                    }
                    return true;
                }
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        foreach ($it as $f) {
            if ($f->isFile()) {
                $fn(ltrim(substr(wp_normalize_path($f->getPathname()), strlen($root)), '/'), $f);
            }
        }
    }

    /** Supprime un dossier de sauvegarde (uniquement à l'intérieur du dossier de stockage). */
    public function removeDir(string $dir): void
    {
        $base = realpath($this->backupDir());
        $real = realpath($dir);
        if (!$real || !is_dir($real) || 0 !== strpos($real, $base . DIRECTORY_SEPARATOR)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($real);
    }
}
