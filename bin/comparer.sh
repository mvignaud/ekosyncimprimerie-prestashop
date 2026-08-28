#!/bin/zsh
# Compare ce dépôt à ce qui tourne VRAIMENT en production.
#
# ⚠️ POURQUOI CE SCRIPT EXISTE. Ce module se déploie par dépôt de fichier, pas
# par git. Rien ne garantit donc que le dépôt et le serveur disent la même
# chose — et deux fois le 2026-08-28, une copie locale périmée a failli écraser
# du code plus récent posé directement sur le serveur.
#
# ⚠️ Une empreinte identique prouve le TRANSPORT, jamais le contenu voulu : ce
# script dit ce qui diffère, il ne dit pas qui a raison. À lire avant d'écrire.
#
# Usage : ./bin/comparer.sh
set -u
MODULE="ekosyncimprimerie"
RACINE="${0:A:h}/.."
DIST="/tmp/eko-comparer-$$"

export SSHPASS='uHXNKpq#9i'
SSH=(sshpass -e ssh -4 -o StrictHostKeyChecking=accept-new
     -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=25)

mkdir -p "$DIST"
"${SSH[@]}" mathieuvclaude2m@vps-host1-eurbx.e-ko.fr \
  "cd ~/web/modules/$MODULE && tar -cf - --exclude='*.bak*' --exclude='*.avant-*' ." \
  | tar -xf - -C "$DIST" || { echo "impossible de lire la production"; exit 1; }

echo "── $MODULE : dépôt local ←→ production"
# ⚠️ CE QUI N'EST PAS UNE DÉRIVE. Un outil qui signale toujours quelque chose
# ne se lit plus. Le mobilier de dépôt — README, licence, workflows, logo,
# journal — n'a jamais vocation à être déployé ; les fichiers que PrestaShop
# écrit lui-même n'ont jamais vocation à être versionnés. Les taire, c'est
# faire que le reste compte.
IGNORER=(--exclude=.git --exclude=bin --exclude=.gitignore --exclude=.github
         --exclude=LICENSE --exclude=README.md --exclude=CHANGELOG.md
         --exclude=logo.png --exclude='config*.xml' --exclude=index.php
         # `dev/` est de l'outillage de construction : il vit au dépôt, jamais
         # sur une boutique. Ce qui y traîne encore en production est inerte —
         # le `.htaccess` des modules le rend en 403 — mais n'a rien à y faire.
         --exclude=dev)

if diff -rq "${IGNORER[@]}" "$RACINE" "$DIST" 2>/dev/null; then
  echo "   identiques"
else
  echo
  echo "   ⚠️ ÉCART. Regarder AVANT de déposer quoi que ce soit :"
  echo "      diff -ru $RACINE <fichier> $DIST/<fichier>"
  echo "   (les fichiers restent dans $DIST)"
  exit 2
fi
rm -rf "$DIST"
