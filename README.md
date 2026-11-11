# BTB Sauvegarde

[![Version](https://img.shields.io/github/v/release/dev-solar-cms/btb-site-backup?label=version)](https://github.com/dev-solar-cms/btb-site-backup/releases/latest)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b?logo=wordpress)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4?logo=php)
[![Licence](https://img.shields.io/badge/licence-GPL--2.0--or--later-blue)](LICENSE)

Extension WordPress de **sauvegarde automatique, incrémentale et 100 % locale**, avec **restauration en un clic**.

Les sauvegardes restent sur votre serveur, hors du dossier public. L'extension n'utilise aucune tâche cron et aucun service distant, et n'expose aucune télécommande à Internet.

> Pourquoi cette extension ? Les extensions de sauvegarde pilotables à distance sont une cible de choix. En 2026, des milliers de sites WordPress ont été piratés via la télécommande d'une extension de sauvegarde très répandue. BTB Sauvegarde fait le choix inverse : tout se passe sur le serveur, et rien n'est pilotable de l'extérieur.

---

## Fonctionnalités

| | |
|---|---|
| **Sauvegarde automatique** | Le délai entre deux sauvegardes se règle en heures (24 h par défaut), **sans tâche cron**. |
| **Incrémentale** | Seuls les fichiers nouveaux ou modifiés sont copiés. Chaque contenu n'est stocké qu'une fois, même s'il apparaît dans plusieurs sauvegardes. |
| **Éléments séparés** | Base de données, extensions, thèmes, médias et autres fichiers (cœur WordPress, configuration, mu-plugins, traductions). |
| **Rotation** | Les **10 dernières** sauvegardes automatiques sont conservées (nombre réglable). |
| **Sauvegardes protégées** | Une sauvegarde manuelle peut être nommée et protégée : elle ne sera **jamais supprimée automatiquement**. |
| **Téléchargement** | Chaque élément se télécharge en **ZIP** (parties de 1 Go au maximum), ouvrable par l'explorateur Windows. La base se télécharge en `.sql.gz`. |
| **Restauration automatique** | Vous cochez les éléments à restaurer. Une sauvegarde de sécurité est faite d'abord, et la suppression des fichiers ajoutés depuis la sauvegarde est proposée (utile après un piratage). |
| **Barre de progression** | Pourcentage, étape en cours sur le nombre total d'étapes, détail (tables, fichiers), message de fin. |
| **Avertissements classés** | Gravité élevée, moyenne ou faible, avec explication et action conseillée pour chacun. |
| **Journal d'activité** | Qui a lancé, supprimé, protégé ou restauré quoi, et quand. |

## Installation

1. Téléchargez **`btb-site-backup-x.y.z.zip`** depuis la [dernière release](https://github.com/dev-solar-cms/btb-site-backup/releases/latest). Prenez bien ce fichier, et pas le « Source code ».
2. Dans WordPress, allez dans **Extensions › Ajouter › Téléverser une extension**, choisissez le fichier ZIP puis cliquez sur **Installer maintenant**.
3. Cliquez sur **Activer**. Le menu **BTB Sauvegarde** apparaît juste sous le Tableau de bord.

La première sauvegarde démarre automatiquement à la visite suivante du site, ou tout de suite avec le bouton **Lancer la sauvegarde**.

Pour mettre à jour, téléversez le ZIP de la nouvelle version : WordPress propose de **remplacer la version installée**. Les sauvegardes et les réglages sont conservés.

## Où sont stockées les sauvegardes ?

| Installation | Dossier |
|---|---|
| [Bedrock](https://roots.io/bedrock/) | `<projet>/backups/`, **en dehors** du dossier public `web/` |
| WordPress classique | `wp-content/btb-backups/`, protégé par un `.htaccess` (`Require all denied`) |

```
backups/
├── store/                  contenus des fichiers, chacun stocké une seule fois (nommés par SHA-1)
├── 2026-11-11_131923/      une sauvegarde
│   ├── manifest.json       résumé : date, éléments, tailles, protection…
│   ├── database.sql.gz     export complet des tables du site
│   ├── index-plugins.txt   liste des fichiers : sha1, crc32, taille, date, chemin
│   ├── index-uploads.txt
│   └── warnings.json
├── activity.log            journal d'activité
└── .htaccess, index.php    protection
```

## Fonctionnement

### Sans tâche cron
À la fin de chaque requête, l'extension vérifie si une sauvegarde est due. Si c'est le cas, le site s'envoie une **requête interne non bloquante**, protégée par un jeton secret propre au site. Le travail est découpé en **étapes courtes** (25 s au plus), donc il reprend où il s'était arrêté, même après une coupure. Pour les étapes plus longues, un mécanisme de secours avance le travail après l'envoi de la page aux visiteurs.

### Restauration sûre
1. **Sauvegarde de sécurité** de l'état actuel (option cochée par défaut).
2. **Import de la base dans des tables temporaires** : le site continue de fonctionner. En cas d'erreur, rien n'est modifié.
3. **Restauration des fichiers** : seuls les fichiers différents sont réécrits, chacun de façon atomique.
4. **Suppression des fichiers ajoutés** depuis la sauvegarde (option).
5. **Échange instantané des tables** (`RENAME TABLE` en une seule instruction), puis remise en place des clés étrangères.

Le fichier `.env`, qui contient les mots de passe actuels, et l'extension elle-même ne sont jamais écrasés. Les tables présentes sur le site mais absentes de la sauvegarde sont laissées en place et signalées.

### Sécurité
- Aucun accès distant : pas d'API, pas de clé, pas de service tiers.
- Toutes les actions sont réservées aux administrateurs (`manage_options`) et protégées par un jeton (nonce) WordPress.
- Les identifiants et les noms de fichiers sont validés, et les téléchargements sont limités au dossier de stockage.
- Un **verrou MySQL** empêche deux étapes de tourner en même temps, même sur un stockage réseau.
- En multisite ou avec plusieurs sites dans la même base, seules les tables **du préfixe du site** sont sauvegardées et restaurées.

## Restaurer sans l'extension

- **Base de données** : importez le `.sql.gz` dans phpMyAdmin, ou en SSH :
  ```bash
  zcat fichier.sql.gz | mysql -h HOTE -u UTILISATEUR -p BASE
  ```
- **Fichiers** : les archives ZIP contiennent les chemins complets depuis la racine du projet. Décompressez-les, puis renvoyez les dossiers par FTP au même endroit.

## Configuration requise

- WordPress 6.0 ou plus récent
- PHP 7.4 ou plus récent, avec l'extension `zlib` (présente par défaut sur la plupart des hébergements)
- MySQL 5.7 ou plus récent, ou MariaDB 10.3 ou plus récent
- Le serveur doit pouvoir s'envoyer une requête HTTP (« loopback »). À défaut, la sauvegarde avance pendant les visites et quand la page de l'extension est ouverte.

## Architecture

Code orienté objet, une classe par responsabilité, chargée automatiquement (PSR-4, espace de noms `BTB\SiteBackup`).

```
btb-site-backup/
├── btb-site-backup.php          amorçage : en-tête, chargeur, activation/désactivation
├── src/
│   ├── Plugin.php               branchement sur les crochets WordPress
│   ├── Container.php            assemblage des services
│   ├── Config.php               constantes, composants, catalogue des avertissements
│   ├── Lifecycle/               Activator, Deactivator
│   ├── Support/                 Paths, Settings, State, Lock, Logger, Warnings, Format
│   ├── Storage/                 ObjectStore, BackupRepository, IndexFile, GarbageCollector, RestoreStateStore
│   ├── Backup/                  BackupJob, DatabaseExporter, FileScanner, FileCollector
│   ├── Restore/                 RestoreJob, DatabaseImporter, FileRestorer
│   ├── Runner/                  Runner, Scheduler, StateHealer
│   ├── Download/                ZipStreamer
│   └── Admin/                   Menu, AdminPage, Progress
│       ├── Actions/             une classe par action de formulaire (sauvegarder, restaurer, supprimer…)
│       └── Ajax/                StartAjax, StatusAjax, StepAjax
├── templates/admin/             gabarits de la page d'administration
└── assets/                      css/admin.css, js/admin.js
```

### Filtre disponible

```php
// Exclure des dossiers supplémentaires de la sauvegarde (expressions régulières sur le chemin relatif)
add_filter('btb_backup_excludes', function (array $patterns) {
    $patterns[] = '#^web/app/uploads/cache-images(/|$)#';
    return $patterns;
});
```

## Désactivation et désinstallation

- **Désactiver** l'extension arrête proprement la sauvegarde en cours. Une restauration est annulée si elle n'a encore rien modifié ; sinon, elle reprend à la réactivation. Les sauvegardes et les réglages sont **conservés**.
- Pour libérer l'espace disque, supprimez les sauvegardes depuis la page de l'extension avant de la désinstaller, ou supprimez le dossier `backups/` par FTP.

## Licence

[GPL-2.0-or-later](LICENSE). Auteur : **David ROMERA**.
