#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Produit le catalogue de traduction du module au format attendu par PrestaShop.

Les chaînes SOURCES sont en français : c'est ce que `$this->trans()` reçoit en
premier argument, et ce que PrestaShop affiche faute de catalogue. Ce script les
extrait du fichier principal et les apparie avec leur traduction, plutôt que de
tenir une liste à la main qui divergerait au premier ajout.

Un écart entre le code et la table de traduction est signalé, jamais avalé : une
chaîne ajoutée au module et oubliée ici s'afficherait en français dans une
boutique étrangère, sans que rien ne le dise.
"""
import pathlib
import re
import sys
from xml.sax.saxutils import escape

RACINE = pathlib.Path(__file__).resolve().parent.parent
SOURCE = RACINE / "ekosyncimprimerie.php"
DOMAINE = "ModulesEkosyncimprimerieAdmin"

# fr-FR -> en-US. La source française est la clé : PrestaShop apparie là-dessus.
EN = {
    "EKO Sync — Imprimerie": "EKO Sync — Print shop",
    "Relie la boutique à l'ERP E-KO : catalogue, tarifs, documents et fichiers clients. Les tarifs affichés sont ceux calculés par E-KO, sans recalcul local.":
        "Connects the shop to the E-KO ERP: catalogue, prices, documents and customer files. Displayed prices are the ones E-KO computes; nothing is recalculated locally.",
    "Désinstaller le module coupera la liaison avec E-KO. Les tarifs ne seront plus disponibles sur les fiches configurables.":
        "Uninstalling the module will sever the link with E-KO. Prices will no longer be available on configurable products.",
    "non posé": "not set up",
    "Groupes de clients": "Customer groups",
    "Ces groupes ne portent AUCUNE remise. Le prix remisé vient d'E-KO : une remise posée ici serait un second calcul, avec ses propres arrondis, et le site cesserait de coïncider au centime près avec un devis.":
        "These groups carry NO discount. The discounted price comes from E-KO: a discount set here would be a second calculation, with its own rounding, and the shop would stop matching a quote to the cent.",
    "Groupe": "Group",
    "Correspond à": "Matches",
    "Affichage": "Display",
    "Identifiant boutique": "Shop id",
    "Poser / vérifier les groupes": "Set up / check the groups",
    "L'adresse de l'API n'est pas une URL valide.": "The API address is not a valid URL.",
    "L'API doit être appelée en HTTPS : le jeton transiterait sinon en clair.":
        "The API must be called over HTTPS, otherwise the token would travel in clear text.",
    "Cette adresse pointe vers le réseau interne du serveur : refusée.":
        "This address points to the server's internal network: rejected.",
    "Adresse de l'API modifiée : le jeton a été effacé et doit être ressaisi. Un jeton délivré par l'ancienne instance n'a pas à être envoyé à la nouvelle.":
        "API address changed: the token has been cleared and must be entered again. A token issued by the previous instance has no business being sent to the new one.",
    "Réglages enregistrés.": "Settings saved.",
    "Renseignez une adresse e-mail à rechercher.": "Enter an email address to search for.",
    "Créer le compte boutique": "Create the shop account",
    "Aucun tiers désigné.": "No customer selected.",
    "Posez d'abord les groupes de clients : sans eux, le compte serait créé sans tarif applicable.":
        "Set up the customer groups first: without them, the account would be created with no applicable pricing.",
    "Liaison avec E-KO": "Connection to E-KO",
    "Adresse de l'API": "API address",
    "Racine du service, sans /api/v1. HTTPS obligatoire.": "Service root, without /api/v1. HTTPS required.",
    "Jeton": "Token",
    "Jeton dédié à ce module, aux portées minimales. Laisser vide pour conserver le jeton actuel.":
        "Token dedicated to this module, with the narrowest scopes. Leave empty to keep the current token.",
    "Durée du cache (secondes)": "Cache lifetime (seconds)",
    "Le cache ne conserve que des réponses d'E-KO, jamais un calcul local. 0 désactive le cache.":
        "The cache only keeps E-KO responses, never a local calculation. 0 disables it.",
    "Rechercher un client": "Search for a customer",
    "Adresse e-mail d'un client : affiche le tiers E-KO correspondant et le nombre de documents à son nom. Aucune écriture.":
        "A customer's email address: shows the matching E-KO customer and how many documents are filed under their name. Nothing is written.",
    "Enregistrer": "Save",
    "Tester la liaison": "Test the connection",
    "Rechercher": "Search",
    "Produit d'atelier E-KO": "E-KO workshop product",
    "— aucun, prix géré par PrestaShop —": "— none, price handled by PrestaShop —",
    "Lier cette fiche à un produit d'atelier fait venir son prix de l'ERP. Sans liaison, PrestaShop garde la main.":
        "Linking this product to a workshop product makes its price come from the ERP. Without a link, PrestaShop keeps control.",
    "Liste des produits d'atelier indisponible : ": "Workshop product list unavailable: ",
}

CATALOGUES = {"en-US": EN}


def sources():
    """Les premiers arguments littéraux de `$this->trans()`, dans l'ordre du fichier."""
    texte = SOURCE.read_text(encoding="utf-8")
    motif = re.compile(r"\$this->trans\(\s*'((?:[^'\\]|\\.)*)'", re.S)

    vues = []
    for m in motif.finditer(texte):
        s = m.group(1).replace("\\'", "'").replace("\\\\", "\\")
        if s not in vues:
            vues.append(s)

    return vues


def ecrire(locale, table, chaines):
    dossier = RACINE / "translations" / locale
    dossier.mkdir(parents=True, exist_ok=True)

    lignes = [
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">',
        '  <file source-language="fr-FR" target-language="%s" datatype="plaintext"'
        ' original="module.ekosyncimprimerie">' % locale,
        "    <body>",
    ]

    for i, s in enumerate(chaines, 1):
        lignes += [
            '      <trans-unit id="%d">' % i,
            "        <source>%s</source>" % escape(s),
            "        <target>%s</target>" % escape(table[s]),
            "      </trans-unit>",
        ]

    lignes += ["    </body>", "  </file>", "</xliff>", ""]

    chemin = dossier / ("%s.%s.xlf" % (DOMAINE, locale))
    chemin.write_text("\n".join(lignes), encoding="utf-8")

    return chemin


if __name__ == "__main__":
    chaines = sources()
    print("%d chaîne(s) trouvée(s) dans %s" % (len(chaines), SOURCE.name))

    rate = 0
    for locale, table in CATALOGUES.items():
        manquantes = [s for s in chaines if s not in table]
        surplus = [s for s in table if s not in chaines]

        for s in manquantes:
            print("  MANQUE en %s : %s" % (locale, s[:70]))
            rate += 1
        for s in surplus:
            print("  ORPHELIN en %s (plus dans le code) : %s" % (locale, s[:70]))
            rate += 1

        if manquantes:
            continue

        print("  %s -> %s" % (locale, ecrire(locale, table, chaines).relative_to(RACINE)))

    sys.exit(1 if rate else 0)
