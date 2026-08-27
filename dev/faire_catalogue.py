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

─── LE DOMAINE EST CELUI QUE LE CODE DÉCLARE ──────────────────────────────────

PrestaShop range les traductions par domaine, et le domaine décide du fichier de
catalogue. Le module en emploie deux :

  Admin — ce que voit le marchand ;
  Shop  — ce que voit le CLIENT.

Ce script a longtemps déduit le domaine du FICHIER : tout ce qui sortait de
`ekosyncimprimerie.php` partait en Admin. C'était faux, et coûteux. Le fichier
principal écrit dans les DEUX domaines — il pose le bloc technique, la zone de
dépôt et les dates d'expédition, que lit le client. Quarante-trois chaînes
destinées à la boutique étaient donc rangées dans le catalogue du marchand :
PrestaShop les cherchait sous `Shop`, ne les trouvait pas, et les servait en
français à tout visiteur étranger. Le garde, lui, était vert.

Le domaine se lit maintenant dans l'APPEL — troisième argument de `trans()`,
attribut `d=` de `{l}`. Une chaîne dont le domaine reste introuvable est
signalée : la deviner reproduirait exactement la panne qu'on vient de corriger.

─── TROIS LOCALES, DEUX TABLES ANGLAISES QUI N'EN FONT QU'UNE ─────────────────

`en-GB` et `en-US` portent le même anglais — le module ne distingue pas les deux
orthographes. Une seule table les sert donc toutes les deux : la dupliquer
inviterait à ne corriger qu'une des deux copies. `es-ES` ne couvre que la
boutique, parce que le back-office ne s'adresse qu'au marchand.
"""
import pathlib
import re
import sys
from xml.sax.saxutils import escape

RACINE = pathlib.Path(__file__).resolve().parent.parent

# fr-FR -> anglais, pour le marchand. PrestaShop apparie sur la source française.
ADMIN_EN = {
    "EKO Sync — Imprimerie": "EKO Sync — Print shop",
    "Relie la boutique à l'ERP E-KO : catalogue, tarifs, documents et fichiers clients. Les tarifs affichés sont ceux calculés par E-KO, sans recalcul local.":
        "Connects the shop to the E-KO ERP: catalogue, prices, documents and customer files. Displayed prices are the ones E-KO computes; nothing is recalculated locally.",
    "Désinstaller le module coupera la liaison avec E-KO. Les tarifs ne seront plus disponibles sur les fiches configurables.":
        "Uninstalling the module will sever the link with E-KO. Prices will no longer be available on configurable products.",
    "— aucun, prix géré par PrestaShop —": "— none, price handled by PrestaShop —",
    "Atelier — prix calculé": "Workshop — computed price",
    "Catalogue d'atelier indisponible : ": "Workshop catalogue unavailable: ",
    "Sous-traitance — prix en grille": "Subcontracting — grid price",
    "Catalogue de sous-traitance indisponible : ": "Subcontracting catalogue unavailable: ",
    "Sous-traitance — prix en grille (formulaire)": "Subcontracting — grid price (form)",
    "Second catalogue de sous-traitance indisponible : ":
        "Second subcontracting catalogue unavailable: ",
    "Liaison enregistrée": "Saved link",
    "La liaison enregistrée est conservée et reste sélectionnée. Ne la remplacez pas tant que le catalogue n’est pas revenu.":
        "The saved link is kept and stays selected. Do not replace it until the catalogue is back.",
    "Produit E-KO": "E-KO product",
    "Lier cette fiche fait venir son prix de l'ERP. Sans liaison, PrestaShop garde la main.":
        "Linking this product makes its price come from the ERP. Without a link, PrestaShop keeps control.",
    "Matière de cette fiche": "Material for this listing",
    "Un même produit du sous-traitant alimente plusieurs fiches : Akylux, Dibond et PVC expansé sont trois pages pour une seule référence chez lui. La matière choisie ici est celle que cette fiche vend, et le configurateur la verrouillera.":
        "One subcontractor product feeds several listings: Akylux, Dibond and expanded PVC are three pages for a single reference on their side. The material chosen here is the one this listing sells, and the configurator will lock it.",
    "Ajouter une ligne": "Add a row",
    "Retirer cette ligne": "Remove this row",
    "Déposer un SVG": "Upload an SVG",
    "Icône…": "Icon…",
    "Dépôt refusé.": "Upload rejected.",
    "Déposé": "Uploaded",
    "Calculé": "Computed",
    "À fournir": "To be supplied",
    "Remplacer": "Replace",
    "Déposer": "Upload",
    "Retirer": "Remove",
    "Gabarits": "Templates",
    "Un gabarit « calculé » est produit automatiquement depuis le format fini. ":
        "A “computed” template is produced automatically from the finished size. ",
    "Résolution": "Resolution",
    "Couleurs": "Colours",
    "Fonds perdus": "Bleed",
    "Marge de sécurité": "Safety margin",
    "Fiche technique": "Technical sheet",
    "BAT numérique": "Digital proof",
    "Ma création graphique": "My artwork",
    "Description (facultative)": "Description (optional)",
    "Prestations": "Services",
    "Une ligne par option : « Libellé|supplément en euros|description ». La description est facultative ; elle s'affiche sous le titre de la tuile. La première ligne est le choix par défaut — elle doit être gratuite.":
        "One line per option: « Label|extra in euros|description ». The description is optional; it shows under the tile title. The first line is the default choice — it must be free.",
    "Laisser vide pour reprendre le réglage de la boutique, montré en filigrane. Une création graphique ne demande pas le même travail sur un flyer et sur un dépliant : c'est ici qu'on l'ajuste, fiche par fiche.":
        "Leave empty to use the shop-wide setting, shown as a hint. Artwork does not take the same work on a flyer and on a folded leaflet: this is where you adjust it, product by product.",
    "Ventes phares": "Best sellers",
    "Une ligne par onglet : « Libellé|format ». Le format s'écrit avec son nom tel qu'il apparaît sur le site, ou avec le code du fournisseur. Laisser vide pour n'afficher aucun onglet.":
        "One line per tab: « Label|format ». Write the format with the name shown on the shop, or with the supplier code. Leave empty to show no tab.",
    "Heure limite — offre incluse": "Cut-off time — included offer",
    "Si commandé aujourd'hui avant 18h": "If ordered today before 6pm",
    "Affichée sous le délai inclus. Laisser vide pour ne rien annoncer : c'est un engagement pris devant le client.":
        "Shown under the included lead time. Leave empty to announce nothing: this is a promise made to the customer.",
    "Heure limite — livraison accélérée": "Cut-off time — express delivery",
    "Si commandé avant demain 11h": "If ordered before 11am tomorrow",
    "Affichée sous les délais payants, dont l'heure limite est souvent différente.":
        "Shown under the paid lead times, whose cut-off time is often different.",
    "Mention sous le prix": "Line under the price",
    "Tout inclus — Livraison offerte": "All included — Free delivery",
    "Affichée dans le récapitulatif, sous le montant. Laisser vide si la livraison n'est pas offerte : la mention serait alors fausse.":
        "Shown in the summary, under the amount. Leave empty if delivery is not free: the line would then be untrue.",
    "Réassurances": "Reassurance points",
    "Une ligne par argument : « Libellé|icône ». L'icône est origine, livraison, fichier ou paiement — ou le chemin d'une image déposée sur la boutique, pour un logo qui vous appartient.":
        "One line per point: « Label|icon ». The icon is origine, livraison, fichier or paiement — or the path of an image uploaded to the shop, for a logo you own.",
    "Guide du produit": "Product guide",
    "Remplace le « Size Guide » du thème, qui est le même pour toute la boutique. Laisser le contenu vide pour n'afficher aucun guide sur cette fiche — un guide des tailles n'a pas de sens sur un flyer, il en a un sur un textile.":
        "Replaces the theme's Size Guide, which is the same shop-wide. Leave the content empty to show no guide on this product — a size guide makes no sense on a flyer, it does on a garment.",
    "Intitulé": "Label",
    "Guide des tailles": "Size guide",
    "Contenu": "Content",
    "Texte ou tableau HTML. Vide = pas de guide sur cette fiche.":
        "Text or HTML table. Empty = no guide on this product.",
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
    "Racine du service, sans /api/v1. HTTPS obligatoire.":
        "Service root, without /api/v1. HTTPS required.",
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
    "Aucun fichier reçu.": "No file received.",
    "Produit ou format manquant.": "Missing product or size.",
    "Gabarit retiré.": "Template removed.",
    "Retrait impossible.": "Could not remove it.",
    "Ces réglages valent pour toutes les fiches liées à l'ERP. Une fiche qui porte sa propre valeur garde la sienne.":
        "These settings apply to every product linked to the ERP. A product carrying its own value keeps it.",
    "Réglages de l'imprimerie": "Print shop settings",
}

# fr-FR -> anglais, pour le client.
SHOP_EN = {
    "Voir plus": "See more",
    "Voir moins": "See less",
    "Une consigne pour cette ligne ?": "Any instructions for this line?",
    "Ex. : livrer avant le 20, ou couper en deux paquets égaux.":
        "E.g. deliver before the 20th, or split into two equal packs.",
    "Commentaire enregistré.": "Comment saved.",
    "Spécifications techniques": "Technical specifications",
    "Télécharger le gabarit": "Download the template",
    "Hauteur à fournir (cm)": "Height to supply (cm)",
    "Largeur à fournir (cm)": "Width to supply (cm)",
    "Bords perdus (cm)": "Bleed (cm)",
    "Bords de sécurité (cm)": "Safety margin (cm)",
    "Forme": "Shape",
    "Rectangle": "Rectangle",
    "Échelle autorisée": "Permitted scale",
    "Résolution demandée (dpi)": "Required resolution (dpi)",
    "Types de fichiers autorisés": "Permitted file types",
    "Pages attendues": "Expected pages",
    "Pliage": "Folding",
    "Nous assurons le pliage. Le gabarit est au format ouvert : calez votre contenu sur les plis.":
        "We take care of the folding. The template is in flat format: line your content up with the folds.",
    "(%n% volets)": "(%n% panels)",
    "Masse/m² (g)": "Weight/m² (g)",
    "Poids (kg)": "Weight (kg)",
    "Données techniques": "Technical data",
    "Fermer": "Close",
    "hors articles dont le poids n’est pas connu": "excluding items whose weight is not known",
    "Poids total de ma commande": "Total weight of my order",
    "Ajouter mon fichier": "Add my file",
    "Si vous avez un fichier recto-verso, ne le séparez pas : chargez le PDF directement. ":
        "If your file is double-sided, do not split it: upload the PDF as it is. ",
    "Je chargerai mes fichiers plus tard": "I’ll upload my files later",
    "Fichier conforme": "Compliant file",
    "Conforme, avec réserves": "Compliant, with reservations",
    "À revoir — voir le détail": "Needs review — see details",
    "Nous n’avons pas pu tout vérifier": "We couldn’t check everything",
    "L’envoi a échoué": "Upload failed",
    "Vérification en cours…": "Checking…",
    "En attente": "Pending",
    "Retirer": "Remove",
    "Expédition prévue le": "Dispatch expected on",
    "Livraison prévue le": "Delivery expected on",
    "Jours ouvrés, hors jours fériés.": "Working days, excluding public holidays.",
    "HT": "excl. VAT",
    "TTC": "incl. VAT",
    "(TVA %taux% %)": "(VAT %taux% %)",
    "À partir de": "From",
    "Quantité": "Quantity",
    "Délai": "Lead time",
    "Hauteur (cm)": "Height (cm)",
    "Largeur (cm)": "Width (cm)",
    "Expédition estimée": "Estimated dispatch",
    "Urgence": "Rush",
    "Express": "Express",
    "Standard": "Standard",
    "Mes fichiers": "My files",
    "Déposez ici les fichiers d’impression de vos commandes. Chaque fichier est contrôlé automatiquement dès son arrivée — format, fonds perdus, résolution, polices — et vous en voyez le verdict aussitôt.":
        "Upload the print files for your orders here. Every file is checked automatically as soon as it arrives — size, bleed, resolution, fonts — and you see the verdict straight away.",
    "Vous n’avez pas encore de commande à laquelle rattacher un fichier.":
        "You do not have an order to attach a file to yet.",
    "Voir le catalogue": "Browse the catalogue",
    "Commande": "Order",
    "1 ligne attend son fichier": "1 line is waiting for its file",
    "%d lignes attendent leur fichier": "%d lines are waiting for their files",
    "Envoi en cours…": "Uploading…",
    "Envoi impossible — réessayez.": "Upload failed — please try again.",
    "Gabarits & instructions": "Templates & instructions",
    "Fiche technique": "Technical sheet",
    "Gabarits": "Templates",
    "Voir les %n% autres gabarits": "See the %n% other templates",
    "Télécharger mon document de commande (PDF)": "Download my order summary (PDF)",
    "Il détaille votre configuration ligne par ligne. C’est le document que notre atelier a sous les yeux. Ce n’est pas une facture.":
        "It sets out your configuration line by line. It is the document our workshop works from. It is not an invoice.",
    "Chargement des options…": "Loading options…",
    "Calcul des tarifs…": "Calculating prices…",
    "Je choisis mon délai": "Choose your lead time",
    "Inclus": "Included",
    "Supplément": "Extra",
    "Meilleure offre": "Best value",
    "Bon plan": "Good deal",
    "ex.": "units",
    "Détail de ma commande": "Order summary",
    "l’unité": "each",
    "Ces tarifs n’ont pas été rafraîchis récemment. Confirmez avant de commander.":
        "These prices have not been refreshed recently. Please confirm before ordering.",
    "Options indisponibles pour le moment.": "Options unavailable right now.",
    "Choisissez vos options pour obtenir un prix.": "Choose your options to get a price.",
    "Livraison estimée": "Estimated delivery",
    "dont prestations": "incl. services",
    "Ajouter au panier": "Add to cart",
    "L’ajout au panier n’a pas abouti. Vérifiez votre configuration et réessayez.":
        "Adding to the cart did not go through. Check your configuration and try again.",
    "Tout voir": "See all",
    "Formats précédents": "Previous formats",
    "Formats suivants": "Next formats",
    "Quantités supérieures": "Larger quantities",
    "Je configure mon produit": "Configure my product",
    "Configuration personnalisée": "Custom configuration",
    "Soyez livré plus rapidement": "Get it faster",
    "Prix public TTC": "Retail price incl. VAT",
    "Gratuit": "Free",
    "Oui": "Yes",
    "Nous calculons votre tarif sur mesure…": "We are working out your custom price…",
    "Le calcul est plus long que prévu. Rechargez la page ou demandez-nous un devis.":
        "This is taking longer than expected. Reload the page or ask us for a quote.",
    "Dimensions sur mesure": "Custom dimensions",
    "Non": "No",
    "Plan de travail aux cotes que vous avez saisies, fond perdu et zone de sécurité compris.":
        "Artboard at the dimensions you entered, bleed and safety area included.",
    "Au-delà de %s mm, le gabarit est fourni à l’échelle — elle est écrite sur le document.":
        "Beyond %s mm, the template is supplied at a reduced scale — the scale is written on the document.",
    "Vos cotes dépassent %1$s mm : ce gabarit sera fourni à l’échelle 1:%2$s.":
        "Your dimensions exceed %1$s mm: this template will be supplied at 1:%2$s scale.",
    "Dimensions": "Dimensions",
    "Votre commande": "Your order summary",
    "Calcul du prix…": "Calculating the price…",
    "Configuration impossible à chiffrer.": "This configuration cannot be priced.",
    "Le prix n’a pas pu être obtenu.": "The price could not be retrieved.",
    "Ce thème n’expose pas de champ quantité : le prix ne peut pas être garanti.":
        "This theme exposes no quantity field: the price cannot be guaranteed.",
    "Le prix de cette configuration est en cours de calcul.":
        "The price for this configuration is being calculated.",
    "Ce produit se chiffre sur mesure.": "This product is quoted to order.",
    "Son configurateur est momentanément indisponible : nous ne pouvons pas afficher de prix fiable pour l’instant. Contactez-nous pour un devis, ou revenez dans quelques minutes.":
        "Its configurator is temporarily unavailable: we cannot display a reliable price at the moment. Contact us for a quote, or come back in a few minutes.",
    "Déposer les fichiers d’impression de vos commandes":
        "Upload the print files for your orders",
}

# fr-FR -> espagnol, pour le client. Le back-office reste en français.
SHOP_ES = {
    "Voir plus": "Ver más",
    "Voir moins": "Ver menos",
    "Une consigne pour cette ligne ?": "¿Alguna indicación para esta línea?",
    "Ex. : livrer avant le 20, ou couper en deux paquets égaux.":
        "Ej.: entregar antes del día 20, o dividir en dos paquetes iguales.",
    "Commentaire enregistré.": "Comentario guardado.",
    "Spécifications techniques": "Especificaciones técnicas",
    "Télécharger le gabarit": "Descargar la plantilla",
    "Hauteur à fournir (cm)": "Altura a entregar (cm)",
    "Largeur à fournir (cm)": "Anchura a entregar (cm)",
    "Bords perdus (cm)": "Sangrado (cm)",
    "Bords de sécurité (cm)": "Margen de seguridad (cm)",
    "Forme": "Forma",
    "Rectangle": "Rectángulo",
    "Échelle autorisée": "Escala permitida",
    "Résolution demandée (dpi)": "Resolución requerida (dpi)",
    "Types de fichiers autorisés": "Tipos de archivo permitidos",
    "Pages attendues": "Páginas previstas",
    "Pliage": "Plegado",
    "Nous assurons le pliage. Le gabarit est au format ouvert : calez votre contenu sur les plis.":
        "Nosotros nos encargamos del plegado. La plantilla está en formato abierto: ajuste su contenido a los pliegues.",
    "(%n% volets)": "(%n% paneles)",
    "Masse/m² (g)": "Masa/m² (g)",
    "Poids (kg)": "Peso (kg)",
    "Données techniques": "Datos técnicos",
    "Fermer": "Cerrar",
    "hors articles dont le poids n’est pas connu":
        "sin incluir los artículos cuyo peso se desconoce",
    "Poids total de ma commande": "Peso total de mi pedido",
    "Ajouter mon fichier": "Añadir mi archivo",
    "Si vous avez un fichier recto-verso, ne le séparez pas : chargez le PDF directement. ":
        "Si su archivo es a doble cara, no lo separe: cargue el PDF tal cual. ",
    "Je chargerai mes fichiers plus tard": "Subiré mis archivos más tarde",
    "Fichier conforme": "Archivo conforme",
    "Conforme, avec réserves": "Conforme, con reservas",
    "À revoir — voir le détail": "Revisar — ver el detalle",
    "Nous n’avons pas pu tout vérifier": "No hemos podido comprobarlo todo",
    "L’envoi a échoué": "El envío ha fallado",
    "Vérification en cours…": "Comprobando…",
    "En attente": "Pendiente",
    "Retirer": "Quitar",
    "Expédition prévue le": "Envío previsto el",
    "Livraison prévue le": "Entrega prevista el",
    "Jours ouvrés, hors jours fériés.": "Días laborables, sin contar festivos.",
    "HT": "sin IVA",
    "TTC": "con IVA",
    "(TVA %taux% %)": "(IVA %taux% %)",
    "À partir de": "Desde",
    "Quantité": "Cantidad",
    "Délai": "Plazo",
    "Hauteur (cm)": "Alto (cm)",
    "Largeur (cm)": "Ancho (cm)",
    "Expédition estimée": "Envío estimado",
    "Urgence": "Urgente",
    "Express": "Exprés",
    "Standard": "Estándar",
    "Mes fichiers": "Mis archivos",
    "Déposez ici les fichiers d’impression de vos commandes. Chaque fichier est contrôlé automatiquement dès son arrivée — format, fonds perdus, résolution, polices — et vous en voyez le verdict aussitôt.":
        "Suba aquí los archivos de impresión de sus pedidos. Cada archivo se comprueba automáticamente en cuanto llega — formato, sangrado, resolución, tipografías — y verá el resultado al instante.",
    "Vous n’avez pas encore de commande à laquelle rattacher un fichier.":
        "Todavía no tiene ningún pedido al que adjuntar un archivo.",
    "Voir le catalogue": "Ver el catálogo",
    "Commande": "Pedido",
    "1 ligne attend son fichier": "1 línea espera su archivo",
    "%d lignes attendent leur fichier": "%d líneas esperan su archivo",
    "Envoi en cours…": "Enviando…",
    "Envoi impossible — réessayez.": "No se ha podido enviar: inténtelo de nuevo.",
    "Gabarits & instructions": "Plantillas e instrucciones",
    "Fiche technique": "Ficha técnica",
    "Gabarits": "Plantillas",
    "Voir les %n% autres gabarits": "Ver las otras %n% plantillas",
    "Télécharger mon document de commande (PDF)": "Descargar mi resumen de pedido (PDF)",
    "Il détaille votre configuration ligne par ligne. C’est le document que notre atelier a sous les yeux. Ce n’est pas une facture.":
        "Detalla su configuración línea por línea. Es el documento que tiene delante nuestro taller. No es una factura.",
    "Chargement des options…": "Cargando opciones…",
    "Calcul des tarifs…": "Calculando tarifas…",
    "Je choisis mon délai": "Elijo mi plazo",
    "Inclus": "Incluido",
    "Supplément": "Suplemento",
    "Meilleure offre": "Mejor oferta",
    "Bon plan": "Buen precio",
    "ex.": "uds.",
    "Détail de ma commande": "Detalle de mi pedido",
    "l’unité": "la unidad",
    "Ces tarifs n’ont pas été rafraîchis récemment. Confirmez avant de commander.":
        "Estas tarifas no se han actualizado recientemente. Confírmelas antes de pedir.",
    "Options indisponibles pour le moment.": "Opciones no disponibles en este momento.",
    "Choisissez vos options pour obtenir un prix.":
        "Elija sus opciones para obtener un precio.",
    "Livraison estimée": "Entrega estimada",
    "dont prestations": "servicios incl.",
    "Ajouter au panier": "Añadir a la cesta",
    "L’ajout au panier n’a pas abouti. Vérifiez votre configuration et réessayez.":
        "No se ha podido añadir a la cesta. Revise su configuración e inténtelo de nuevo.",
    "Tout voir": "Ver todo",
    "Formats précédents": "Formatos anteriores",
    "Formats suivants": "Formatos siguientes",
    "Quantités supérieures": "Cantidades superiores",
    "Je configure mon produit": "Configuro mi producto",
    "Configuration personnalisée": "Configuración personalizada",
    "Soyez livré plus rapidement": "Recíbalo antes",
    "Prix public TTC": "PVP con IVA",
    "Gratuit": "Gratis",
    "Oui": "Sí",
    "Nous calculons votre tarif sur mesure…": "Estamos calculando su precio a medida…",
    "Le calcul est plus long que prévu. Rechargez la page ou demandez-nous un devis.":
        "El cálculo está tardando más de lo previsto. Recargue la página o pídanos un presupuesto.",
    "Dimensions sur mesure": "Dimensiones a medida",
    "Non": "No",
    "Plan de travail aux cotes que vous avez saisies, fond perdu et zone de sécurité compris.":
        "Mesa de trabajo con las medidas que ha introducido, sangrado y zona de seguridad incluidos.",
    "Au-delà de %s mm, le gabarit est fourni à l’échelle — elle est écrite sur le document.":
        "Por encima de %s mm, la plantilla se entrega a escala reducida: la escala figura en el documento.",
    "Vos cotes dépassent %1$s mm : ce gabarit sera fourni à l’échelle 1:%2$s.":
        "Sus medidas superan los %1$s mm: esta plantilla se entregará a escala 1:%2$s.",
    "Dimensions": "Dimensiones",
    "Votre commande": "Su resumen de pedido",
    "Calcul du prix…": "Calculando el precio…",
    "Configuration impossible à chiffrer.": "Configuración imposible de presupuestar.",
    "Le prix n’a pas pu être obtenu.": "No se ha podido obtener el precio.",
    "Ce thème n’expose pas de champ quantité : le prix ne peut pas être garanti.":
        "Este tema no expone ningún campo de cantidad: no se puede garantizar el precio.",
    "Le prix de cette configuration est en cours de calcul.":
        "Se está calculando el precio de esta configuración.",
    "Ce produit se chiffre sur mesure.": "Este producto se presupuesta a medida.",
    "Son configurateur est momentanément indisponible : nous ne pouvons pas afficher de prix fiable pour l’instant. Contactez-nous pour un devis, ou revenez dans quelques minutes.":
        "Su configurador no está disponible momentáneamente: por ahora no podemos mostrar un precio fiable. Contáctenos para un presupuesto o vuelva dentro de unos minutos.",
    "Déposer les fichiers d’impression de vos commandes":
        "Suba los archivos de impresión de sus pedidos",
}

# Le domaine déclaré dans l'appel lui-même — jamais déduit du fichier.
DOMAINE = re.compile(r"'Modules\.Ekosyncimprimerie\.(Admin|Shop)'")

# Un `{* … *}` de Smarty est de la prose, pas du code : le `{l s='…'}` qu'un
# commentaire cite en exemple n'est jamais affiché, et le traduire salirait le
# catalogue d'une chaîne que PrestaShop ne demandera jamais.
COMMENTAIRE_SMARTY = re.compile(r"\{\*.*?\*\}", re.S)

APPEL_PHP = re.compile(r"\$this->trans\(\s*'((?:[^'\\]|\\.)*)'", re.S)
APPEL_TPL = re.compile(r"\{l\s+s='((?:[^'\\]|\\.)*)'", re.S)

# De quoi couvrir un appel PHP même quand son tableau de paramètres tient sur
# plusieurs lignes, sans mordre sur l'appel suivant.
FENETRE = 400

CATALOGUES = [
    {
        "nom": "ModulesEkosyncimprimerieAdmin",
        "domaine": "Admin",
        "tables": {"en-US": ADMIN_EN, "en-GB": ADMIN_EN},
    },
    {
        "nom": "ModulesEkosyncimprimerieShop",
        "domaine": "Shop",
        "tables": {"en-US": SHOP_EN, "en-GB": SHOP_EN, "es-ES": SHOP_ES},
    },
]


def fichiers():
    """Tout ce qui peut porter une chaîne traduisible, dans un ordre stable."""
    return ([RACINE / "ekosyncimprimerie.php"]
            + sorted((RACINE / "controllers").rglob("*.php"))
            + sorted((RACINE / "views" / "templates").rglob("*.tpl")))


def par_domaine():
    """Les chaînes sources rangées sous le domaine que l'appel déclare.

    Rend aussi celles dont le domaine est resté introuvable : elles ne sont pas
    rangées d'office quelque part, elles sont dites.
    """
    trouve = {"Admin": [], "Shop": []}
    orphelines = []

    for fichier in fichiers():
        texte = fichier.read_text(encoding="utf-8")

        if fichier.suffix == ".tpl":
            texte = COMMENTAIRE_SMARTY.sub("", texte)
            motif, borne = APPEL_TPL, "}"
        else:
            motif, borne = APPEL_PHP, None

        for m in motif.finditer(texte):
            if borne is not None:
                # Le `d=` d'un `{l}` vit dans la même accolade que le `s=`.
                fin = texte.find(borne, m.end())
                suite = texte[m.end():fin if fin != -1 else m.end() + FENETRE]
            else:
                suite = texte[m.end():m.end() + FENETRE]

            source = m.group(1).replace("\\'", "'").replace("\\\\", "\\")
            declare = DOMAINE.search(suite)

            if declare is None:
                orphelines.append((fichier.relative_to(RACINE), source))
                continue

            liste = trouve[declare.group(1)]
            if source not in liste:
                liste.append(source)

    return trouve, orphelines


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
    trouve, orphelines = par_domaine()

    for fichier, source in orphelines:
        print("  SANS DOMAINE dans %s : %s" % (fichier, source[:70]))
        rate += 1

    for catalogue in CATALOGUES:
        chaines = trouve[catalogue["domaine"]]
        print("%s : %d chaîne(s) déclarée(s) en %s"
              % (catalogue["nom"], len(chaines), catalogue["domaine"]))

        if not chaines:
            # Un domaine vide n'est pas normal : ou le motif ne mord plus, ou
            # les fichiers ont bougé. Le taire donnerait un vert sans objet.
            print("  AUCUNE chaîne trouvée — le motif d'extraction ne mord plus ?")
            rate += 1
            continue

        for locale, table in catalogue["tables"].items():
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

            chemin = ecrire(catalogue["nom"], locale, table, chaines)
            print("  %s -> %s" % (locale, chemin.relative_to(RACINE)))

    sys.exit(1 if rate else 0)
