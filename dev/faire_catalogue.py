#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Produit les catalogues de traduction du module au format attendu par PrestaShop.

Les chaînes SOURCES sont en français : c'est ce que reçoivent `$this->trans()`
et `{l s='…'}` en premier argument, et ce que PrestaShop affiche faute de
catalogue. Ce script les extrait des fichiers du module et les apparie avec leur
traduction, plutôt que de tenir une liste à la main qui divergerait au premier
ajout.

Un écart entre le code et la table est signalé, jamais avalé : une chaîne
ajoutée au module et oubliée ici s'afficherait en français dans une boutique
étrangère, sans que rien ne le dise.

─── DEUX DOMAINES, ET POURQUOI ────────────────────────────────────────────────

PrestaShop range les traductions par domaine, et le domaine décide du fichier de
catalogue. Le module en emploie deux :

  Admin — ce que voit le marchand, dans `ekosyncimprimerie.php` ;
  Shop  — ce que voit le CLIENT, dans les gabarits `.tpl`.

Ce script n'a longtemps balayé que le premier. Le garde était donc partiel, et
un garde partiel donne une confiance imméritée : onze chaînes du configurateur —
celles que lit le visiteur, prix et messages d'erreur compris — n'étaient dans
aucun catalogue, et rien ne le signalait. Balayer les deux est la seule façon
que le vert veuille dire quelque chose.
"""
import pathlib
import re
import sys
from xml.sax.saxutils import escape

RACINE = pathlib.Path(__file__).resolve().parent.parent

# fr-FR -> en-US. La source française est la clé : PrestaShop apparie là-dessus.
ADMIN_EN = {
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
    "— aucun, prix géré par PrestaShop —": "— none, price handled by PrestaShop —",
    "Produit E-KO": "E-KO product",
    "Atelier — prix calculé": "Workshop — computed price",
    "Sous-traitance — prix en grille": "Subcontracting — grid price",
    "Catalogue d'atelier indisponible : ": "Workshop catalogue unavailable: ",
    "Catalogue de sous-traitance indisponible : ": "Subcontracting catalogue unavailable: ",
    "Lier cette fiche fait venir son prix de l'ERP. Sans liaison, PrestaShop garde la main.":
        "Linking this product makes its price come from the ERP. Without a link, PrestaShop keeps control.",
    "Fiche technique": "Technical sheet",
    "Prestations": "Services",
    "BAT numérique": "Digital proof",
    "Ma création graphique": "My artwork",
    "Une ligne par option : « Libellé|supplément en euros ». La première ligne est le choix par défaut — elle doit être gratuite.":
        "One line per option: \u00ab Label|extra in euros \u00bb. The first line is the default choice — it must be free.",
    "Résolution": "Resolution",
    "Couleurs": "Colours",
    "Fonds perdus": "Bleed",
    "Marge de sécurité": "Safety margin",
}

SHOP_EN = {
    "Configurez votre produit": "Configure your product",
    "— au choix —": "— your choice —",
    "Choisissez vos options pour obtenir un prix.": "Choose your options to get a price.",
    "Calcul du prix…": "Calculating the price…",
    "l’unité": "each",
    "Délai indicatif": "Estimated lead time",
    "jour(s) ouvré(s)": "working day(s)",
    "Configuration impossible à chiffrer.": "This configuration cannot be priced.",
    "Le prix n’a pas pu être obtenu.": "The price could not be retrieved.",
    "Ce thème n’expose pas de champ quantité : le prix ne peut pas être garanti.":
        "This theme exposes no quantity field: the price cannot be guaranteed.",
    "Le prix s’applique à la quantité choisie ci-dessous.":
        "The price applies to the quantity selected below.",
    "Le prix de cette configuration est en cours de calcul.":
        "The price for this configuration is being calculated.",

    # Configurateur de sous-traitance.
    "Je configure mon produit": "Configure my product",
    "Chargement des options…": "Loading options…",
    "Calcul des tarifs…": "Calculating prices…",
    "Quantité": "Quantity",
    "Je choisis mon délai": "Choose your lead time",
    "Inclus": "Included",
    "Supplément": "Extra",
    "Meilleure offre": "Best value",
    "Bon plan": "Good deal",
    "ex.": "units",
    "Détail de ma commande": "Order summary",
    "HT": "excl. VAT",
    "TTC": "incl. VAT",
    "Ces tarifs n’ont pas été rafraîchis récemment. Confirmez avant de commander.":
        "These prices have not been refreshed recently. Please confirm before ordering.",
    "Options indisponibles pour le moment.": "Options unavailable right now.",
    "Livraison estimée": "Estimated delivery",
    "Ajouter au panier": "Add to cart",

    # Fiche technique.
    "Gabarits & instructions": "Templates & instructions",
    "Fiche technique": "Technical sheet",
    "Gabarits": "Templates",
    "Télécharger le gabarit": "Download the template",
    "dont prestations": "incl. services",
}

# Un domaine = un fichier de catalogue, une source d'extraction, une table.
DOMAINES = [
    {
        "nom": "ModulesEkosyncimprimerieAdmin",
        "fichiers": [RACINE / "ekosyncimprimerie.php"],
        # Le premier argument littéral de `$this->trans()`.
        "motif": re.compile(r"\$this->trans\(\s*'((?:[^'\\]|\\.)*)'", re.S),
        "tables": {"en-US": ADMIN_EN},
    },
    {
        "nom": "ModulesEkosyncimprimerieShop",
        "fichiers": sorted((RACINE / "views" / "templates").rglob("*.tpl")),
        # Le premier argument de `{l s='…' d='…'}` de Smarty.
        "motif": re.compile(r"\{l\s+s='((?:[^'\\]|\\.)*)'", re.S),
        "tables": {"en-US": SHOP_EN},
    },
]


def sources(domaine):
    """Les chaînes sources du domaine, dans l'ordre des fichiers."""
    vues = []

    for fichier in domaine["fichiers"]:
        texte = fichier.read_text(encoding="utf-8")

        for m in domaine["motif"].finditer(texte):
            s = m.group(1).replace("\\'", "'").replace("\\\\", "\\")
            if s not in vues:
                vues.append(s)

    return vues


def ecrire(nom, locale, table, chaines):
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

    chemin = dossier / ("%s.%s.xlf" % (nom, locale))
    chemin.write_text("\n".join(lignes), encoding="utf-8")

    return chemin


if __name__ == "__main__":
    rate = 0

    for domaine in DOMAINES:
        chaines = sources(domaine)
        print("%s : %d chaîne(s) dans %d fichier(s)"
              % (domaine["nom"], len(chaines), len(domaine["fichiers"])))

        if not chaines:
            # Un domaine vide n'est pas normal : ou le motif ne mord plus, ou
            # les fichiers ont bougé. Le taire donnerait un vert sans objet.
            print("  AUCUNE chaîne trouvée — le motif d'extraction ne mord plus ?")
            rate += 1
            continue

        for locale, table in domaine["tables"].items():
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

            chemin = ecrire(domaine["nom"], locale, table, chaines)
            print("  %s -> %s" % (locale, chemin.relative_to(RACINE)))

    sys.exit(1 if rate else 0)
