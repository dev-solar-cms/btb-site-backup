<?php

namespace BTB\SiteBackup;

defined('ABSPATH') || exit;

/**
 * Constantes de l'extension : noms d'options, limites, composants et catalogue des avertissements.
 */
final class Config
{
    const VERSION = '1.0.6';

    // Options WordPress
    const OPT_SETTINGS = 'btb_backup_settings';  // réglages (autoload)
    const OPT_STATE    = 'btb_backup_state';     // statut léger (autoload)
    const OPT_JOB      = 'btb_backup_job';       // sauvegarde en cours (non autoload)
    const OPT_TOKEN    = 'btb_backup_token';     // jeton des requêtes internes (non autoload)
    const OPT_TICK     = 'btb_backup_tick';      // horodatages fréquents (non autoload, séparés du statut)

    const CAP  = 'manage_options';
    const SLUG = 'btb-backup';

    const ID_RE    = '/^\d{4}-\d{2}-\d{2}_\d{6}$/';
    const DB_BATCH = 1000;         // lignes lues par requête SQL
    const SQL_MAX  = 1048576;      // taille maximale d'une instruction INSERT (1 Mio)
    const BATCH    = 400;          // fichiers traités entre deux enregistrements de l'état
    const PART_MAX = 1073741824;   // 1 Gio par archive téléchargée
    const MAX_JOB  = 43200;        // 12 h : au-delà, l'opération est considérée comme bloquée
    const MAX_WARN = 2000;         // avertissements détaillés conservés par opération
    const TMP_PFX  = 'r_';         // préfixe des tables temporaires de restauration
    const OLD_PFX  = 'o_';         // préfixe des anciennes tables pendant l'échange

    const DEFAULT_INTERVAL = 24;
    const DEFAULT_KEEP     = 10;

    /** Composants, dans l'ordre d'exécution. */
    const COMPONENTS = [
        'db'      => 'Base de données',
        'plugins' => 'Extensions',
        'themes'  => 'Thèmes',
        'uploads' => 'Médias (images, fichiers envoyés)',
        'others'  => 'Autres fichiers (cœur WordPress, configuration, mu-plugins, traductions…)',
    ];

    const SHORT = [
        'db'      => 'Base de données',
        'plugins' => 'Extensions',
        'themes'  => 'Thèmes',
        'uploads' => 'Médias',
        'others'  => 'Autres fichiers',
    ];

    /** Niveaux de gravité : [libellé, couleur]. */
    const LEVELS = [
        'eleve'  => ['Élevé', '#d63638'],
        'moyen'  => ['Moyen', '#dba617'],
        'faible' => ['Faible', '#00a32a'],
    ];

    /** Types d'avertissement : [niveau par défaut, titre, explication, action conseillée]. */
    const CODES = [
        'table'          => ['eleve', 'Table de base de données ignorée', "La structure de la table n'a pas pu être lue : ses données ne figurent pas dans cette sauvegarde.", 'Vérifiez la table dans phpMyAdmin. Si le problème persiste, contactez votre développeur.'],
        'unreadable'     => ['moyen', 'Fichier présent mais illisible', "Le fichier existe mais PHP n'a pas le droit de le lire : il ne figure pas dans cette sauvegarde. Un fichier illisible inattendu peut aussi être suspect.", 'Vérifiez ses droits par FTP (644 pour un fichier, 755 pour un dossier) et son contenu.'],
        'badname'        => ['moyen', 'Nom de fichier non pris en charge', "Le nom contient une tabulation ou un retour à la ligne : le fichier n'est pas sauvegardé. Ce type de nom est inhabituel et parfois utilisé par des logiciels malveillants.", "Examinez ce fichier par FTP ; renommez-le ou supprimez-le s'il n'a rien à faire là."],
        'vanished'       => ['faible', 'Fichier supprimé ou recréé pendant la sauvegarde', "Le site a modifié ce fichier entre l'inventaire et la copie (cache Elementor, traductions mises à jour automatiquement…). Rien n'est perdu : le fichier n'existait plus à cet instant.", 'Aucune action nécessaire.'],
        'legacy'         => ['faible', 'Fichier non copié (sauvegarde faite avec une ancienne version)', "Ancien format d'avertissement : il s'agit le plus souvent de fichiers supprimés ou recréés par le site pendant la sauvegarde (cache, traductions mises à jour automatiquement).", 'Aucune action nécessaire.'],
        'missing_obj'    => ['eleve', 'Fichier introuvable dans le stockage des sauvegardes', "Le contenu de ce fichier n'a pas été retrouvé : il n'a pas pu être restauré.", "Restaurez-le depuis une autre sauvegarde ou réinstallez l'extension ou le thème concerné."],
        'restore_write'  => ['eleve', 'Écriture impossible pendant la restauration', "Le fichier n'a pas pu être remplacé (droits d'accès ou espace disque).", "Vérifiez les droits du dossier par FTP et l'espace disponible, puis relancez la restauration."],
        'restore_delete' => ['moyen', 'Suppression impossible pendant la restauration', "Ce fichier, absent de la sauvegarde, aurait dû être supprimé mais ne l'a pas été.", "Supprimez-le par FTP s'il n'a rien à faire là (surtout après un piratage)."],
        'fk'             => ['moyen', 'Clé étrangère non rétablie', "Une contrainte entre deux tables n'a pas pu être recréée après la restauration. Le site fonctionne, mais la base est moins protégée contre les incohérences.", 'Signalez-le à votre développeur (la contrainte est indiquée ci-dessous).'],
        'extra_table'    => ['moyen', 'Table absente de la sauvegarde laissée en place', "Cette table existe sur le site mais pas dans la sauvegarde restaurée : elle a été créée après, par une extension installée depuis… ou par un pirate.", 'Si elle ne correspond à aucune extension que vous utilisez, faites-la supprimer (phpMyAdmin).'],
        'sql_skip'       => ['eleve', 'Instruction SQL ignorée pendant la restauration', "Une instruction visait une table qui n'appartient pas à ce site : elle a été ignorée par sécurité.", 'Contactez votre développeur.'],
    ];

    /** Libellés des statuts affichés. */
    const STATUS_LABELS = [
        'ok'      => 'Réussie',
        'warning' => 'Réussie avec avertissements',
        'error'   => 'Échec',
        'running' => 'En cours',
        'deleted' => 'Sauvegarde supprimée',
        ''        => '—',
    ];
}
