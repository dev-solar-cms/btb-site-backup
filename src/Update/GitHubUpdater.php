<?php

namespace BTB\SiteBackup\Update;

use BTB\SiteBackup\Config;

defined('ABSPATH') || exit;

/**
 * Mises à jour depuis les releases GitHub du dépôt de l'extension.
 *
 * WordPress (5.8+) appelle le filtre « update_plugins_github.com » pour les extensions dont l'en-tête
 * « Update URI » pointe vers github.com : il suffit de lui renvoyer la dernière version et l'adresse
 * de son archive. La mise à jour apparaît alors dans Extensions, et s'installe toute seule si les
 * mises à jour automatiques sont activées pour l'extension.
 */
final class GitHubUpdater
{
    const REPO      = 'dev-solar-cms/btb-site-backup';
    const SLUG      = 'btb-site-backup';
    const CACHE_KEY = 'btb_backup_github_release';
    const CACHE_OK  = 6 * HOUR_IN_SECONDS;   // résultat valide
    const CACHE_KO  = HOUR_IN_SECONDS;       // échec : on réessaie plus tard (limite de l'API GitHub)

    public function register(): void
    {
        add_filter('update_plugins_github.com', [$this, 'checkUpdate'], 10, 3);
        add_filter('plugins_api', [$this, 'details'], 20, 3);
        add_filter('upgrader_source_selection', [$this, 'fixFolder'], 10, 4);
        // « Rechercher les mises à jour » (Tableau de bord › Mises à jour) force une nouvelle vérification.
        add_action('load-update-core.php', [$this, 'clearCacheOnForceCheck']);
    }

    /**
     * @param array|false $update
     * @param array       $pluginData en-têtes de l'extension
     * @param string      $pluginFile chemin relatif (btb-site-backup/btb-site-backup.php)
     * @return array|false
     */
    public function checkUpdate($update, $pluginData, $pluginFile)
    {
        if ($pluginFile !== plugin_basename(BTB_BACKUP_FILE)) {
            return $update;
        }
        $release = $this->latestRelease();
        if (!$release) {
            return $update;
        }
        return [
            'id'           => 'https://github.com/' . self::REPO,
            'slug'         => self::SLUG,
            'version'      => $release['version'],
            'url'          => $release['url'],
            'package'      => $release['package'],
            'requires'     => '6.0',
            'requires_php' => '7.4',
            'icons'        => [],
            'banners'      => [],
        ];
    }

    /** Fenêtre « Voir les détails » de la liste des extensions. */
    public function details($result, $action, $args)
    {
        if ('plugin_information' !== $action || !isset($args->slug) || self::SLUG !== $args->slug) {
            return $result;
        }
        $release = $this->latestRelease();
        if (!$release) {
            return $result;
        }
        return (object) [
            'name'          => 'BTB Sauvegarde',
            'slug'          => self::SLUG,
            'version'       => $release['version'],
            'author'        => 'David ROMERA',
            'homepage'      => 'https://github.com/' . self::REPO,
            'download_link' => $release['package'],
            'requires'      => '6.0',
            'requires_php'  => '7.4',
            'last_updated'  => $release['date'],
            'sections'      => [
                'description' => '<p>Sauvegardes automatiques et incrémentales (base de données, extensions, thèmes, médias, autres fichiers), stockées uniquement sur le serveur, hors du dossier web, avec restauration automatique. Aucune tâche cron, aucun service distant.</p>'
                    . '<p><a href="https://github.com/' . self::REPO . '" target="_blank" rel="noopener">Documentation complète sur GitHub</a></p>',
                'changelog'   => $this->toHtml($release['notes']),
            ],
        ];
    }

    /**
     * Garantit que l'archive se décompresse dans « btb-site-backup/ » (le dossier de l'extension),
     * quel que soit le nom du dossier qu'elle contient.
     */
    public function fixFolder($source, $remoteSource, $upgrader, $hookExtra = [])
    {
        global $wp_filesystem;
        if (empty($hookExtra['plugin']) || $hookExtra['plugin'] !== plugin_basename(BTB_BACKUP_FILE)) {
            return $source;
        }
        $wanted = trailingslashit($remoteSource) . self::SLUG . '/';
        if (untrailingslashit($source) === untrailingslashit($wanted)) {
            return $source;
        }
        if ($wp_filesystem && $wp_filesystem->move($source, $wanted, true)) {
            return $wanted;
        }
        return new \WP_Error('btb_backup_folder', 'BTB Sauvegarde : impossible de renommer le dossier de la mise à jour.');
    }

    public function clearCacheOnForceCheck(): void
    {
        if (!empty($_GET['force-check'])) {
            delete_site_transient(self::CACHE_KEY);
        }
    }

    /**
     * Dernière release publiée : ['version', 'package', 'url', 'notes', 'date'], ou null.
     */
    public function latestRelease(): ?array
    {
        $cached = get_site_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            return !empty($cached['version']) ? $cached : null;
        }
        $release = $this->fromApi() ?: $this->fromRedirect();
        set_site_transient(self::CACHE_KEY, $release ?: ['version' => null], $release ? self::CACHE_OK : self::CACHE_KO);
        return $release;
    }

    /** API GitHub (avec notes de version et date). */
    private function fromApi(): ?array
    {
        $response = wp_remote_get('https://api.github.com/repos/' . self::REPO . '/releases/latest', [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'BTB-Sauvegarde/' . Config::VERSION],
        ]);
        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            return null;
        }
        $data    = json_decode((string) wp_remote_retrieve_body($response), true);
        $version = $this->version($data['tag_name'] ?? '');
        if (!$version) {
            return null;
        }
        $package = null;
        foreach ((array) ($data['assets'] ?? []) as $asset) {
            if (self::SLUG . '-' . $version . '.zip' === ($asset['name'] ?? '')) {
                $package = $asset['browser_download_url'];
            }
        }
        if (!$package) {
            return null;   // release sans archive installable : on l'ignore
        }
        return [
            'version' => $version,
            'package' => $package,
            'url'     => (string) ($data['html_url'] ?? 'https://github.com/' . self::REPO . '/releases'),
            'notes'   => (string) ($data['body'] ?? ''),
            'date'    => (string) ($data['published_at'] ?? ''),
        ];
    }

    /** Secours sans API : la page « latest » redirige vers la page de la release (…/tag/v1.2.3). */
    private function fromRedirect(): ?array
    {
        $response = wp_remote_head('https://github.com/' . self::REPO . '/releases/latest', [
            'timeout'     => 10,
            'redirection' => 0,
            'headers'     => ['User-Agent' => 'BTB-Sauvegarde/' . Config::VERSION],
        ]);
        if (is_wp_error($response)) {
            return null;
        }
        $location = (string) wp_remote_retrieve_header($response, 'location');
        if (!preg_match('~/releases/tag/([^/?#]+)~', $location, $m)) {
            return null;
        }
        $tag     = rawurldecode($m[1]);
        $version = $this->version($tag);
        if (!$version) {
            return null;
        }
        return [
            'version' => $version,
            'package' => 'https://github.com/' . self::REPO . '/releases/download/' . rawurlencode($tag) . '/' . self::SLUG . '-' . $version . '.zip',
            'url'     => $location,
            'notes'   => '',
            'date'    => '',
        ];
    }

    /** « v1.0.5 » → « 1.0.5 » (null si ce n'est pas un numéro de version). */
    private function version(string $tag): ?string
    {
        $v = ltrim(trim($tag), 'vV');
        return preg_match('/^\d+(\.\d+){1,3}$/', $v) ? $v : null;
    }

    /** Notes de version (Markdown simple) → HTML sûr. */
    private function toHtml(string $markdown): string
    {
        if ('' === trim($markdown)) {
            return '<p>Voir les <a href="https://github.com/' . self::REPO . '/releases" target="_blank" rel="noopener">notes de version sur GitHub</a>.</p>';
        }
        $html = '';
        $list = false;
        foreach (preg_split('/\R/', $markdown) as $line) {
            $line = trim($line);
            $text = esc_html(preg_replace('/^(#+|[-*]|\d+\.)\s*/', '', $line));
            $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
            $text = preg_replace('/`(.+?)`/', '<code>$1</code>', $text);
            $isItem = (bool) preg_match('/^([-*]|\d+\.)\s+/', $line);
            if ($list && !$isItem) {
                $html .= '</ul>';
                $list  = false;
            }
            if ('' === $line) {
                continue;
            }
            if ($isItem) {
                $html .= ($list ? '' : '<ul>') . '<li>' . $text . '</li>';
                $list  = true;
            } elseif (preg_match('/^#+\s/', $line)) {
                $html .= '<h4>' . $text . '</h4>';
            } else {
                $html .= '<p>' . $text . '</p>';
            }
        }
        return $html . ($list ? '</ul>' : '');
    }
}
