#!/usr/bin/env bash
# Construit l'archive installable par WordPress (Extensions › Ajouter › Téléverser) :
# dist/btb-site-backup-<version>.zip, contenant un seul dossier « btb-site-backup/ ».
set -euo pipefail
cd "$(dirname "$0")/.."
version=$(sed -n 's/^ \* Version: *//p' btb-site-backup.php | tr -d '[:space:]')
mkdir -p dist
git archive --format=zip --prefix=btb-site-backup/ -o "dist/btb-site-backup-${version}.zip" HEAD
echo "dist/btb-site-backup-${version}.zip"
