<?php
/**
 * Aide : restaurer à la main (sans l'extension).
 *
 * @var string $prefix
 * @var string $projectRoot
 */

defined('ABSPATH') || exit;
?>
<h2>Restaurer à la main</h2>
<ol>
    <li>
        <strong>Base de données</strong> : importez le fichier <code>.sql.gz</code> dans phpMyAdmin, ou en SSH :
        <code>zcat fichier.sql.gz | mysql -h HOTE -u UTILISATEUR -p BASE</code>.
        Seules les tables de ce site (préfixe <code><?php echo esc_html($prefix); ?></code>) sont remplacées.
    </li>
    <li>
        <strong>Fichiers</strong> : chaque archive <code>.zip</code> contient les chemins complets depuis la racine du projet
        (<code><?php echo esc_html($projectRoot); ?></code>). Décompressez-la (clic droit › Extraire tout, sous Windows)
        et renvoyez les dossiers voulus par FTP au même endroit.
    </li>
</ol>
