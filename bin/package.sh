#!/usr/bin/env bash
#
# Fabrique l'archive distribuable du module.
#
# PrestaShop exige que le .zip contienne un dossier RACINE portant le nom exact
# du module — sans quoi l'installation par téléversement échoue sans dire
# pourquoi. Le dépôt, lui, garde ses fichiers à plat pour qu'un `git clone`
# dans `modules/` fonctionne directement.

set -euo pipefail

MODULE="ekosyncimprimerie"
RACINE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# La version fait foi dans le code, pas dans un fichier annexe qui divergerait.
VERSION="$(grep -oE "this->version = '[^']+'" "$RACINE/$MODULE.php" | cut -d"'" -f2)"
[ -n "$VERSION" ] || { echo "Version introuvable dans $MODULE.php" >&2; exit 1; }

BUILD="$RACINE/build"
ARCHIVE="$RACINE/$MODULE-v$VERSION.zip"

rm -rf "$BUILD" "$ARCHIVE"
mkdir -p "$BUILD/$MODULE"

# Ce qui part chez le marchand, et rien d'autre.
rsync -a \
  --exclude '.git' \
  --exclude '.github' \
  --exclude '.gitignore' \
  --exclude 'bin' \
  --exclude 'dev' \
  --exclude 'build' \
  --exclude '*.zip' \
  --exclude 'config.xml' \
  --exclude 'config_*.xml' \
  --exclude '.DS_Store' \
  "$RACINE/" "$BUILD/$MODULE/"

# Un index.php dans chaque dossier : PrestaShop l'exige pour empêcher le
# listage d'un repertoire servi tel quel par le serveur web.
find "$BUILD/$MODULE" -type d -exec sh -c '[ -f "$1/index.php" ] || cp "$2" "$1/index.php"' _ {} "$BUILD/$MODULE/index.php" \;

(cd "$BUILD" && zip -qr "$ARCHIVE" "$MODULE")
rm -rf "$BUILD"

echo "Archive : $ARCHIVE"
unzip -l "$ARCHIVE" | tail -n 3
