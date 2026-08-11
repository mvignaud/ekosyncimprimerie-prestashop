<p align="center">
  <img src="logo.png" alt="EKO Sync — Imprimerie" width="96" height="96">
</p>

<h1 align="center">EKO Sync — Imprimerie</h1>

<p align="center">
  Relie une boutique PrestaShop à l'ERP <strong>E-KO</strong> : tarifs, tiers,
  documents et groupes de clients.<br>
  <a href="#licence">MIT</a> · PrestaShop 9.0+ · PHP 8.1+
</p>

---

## Ce que fait ce module

Une boutique PrestaShop et un ERP finissent toujours par se contredire sur un
prix. Ce module écarte le problème par construction : **il ne calcule aucun
tarif**. Il demande à E-KO, met la réponse en cache, et affiche.

> **Principe fondateur** — le tarif affiché sur le site doit être
> *rigoureusement* celui d'un devis produit dans E-KO. Toute tentative de
> recalculer localement, même « juste pour un repli », recrée deux vérités et
> brise l'égalité.

Le partage des responsabilités qui en découle :

| | |
|---|---|
| **PrestaShop** | la présentation : produits publiés, options montrées, ordre, libellés, visuels, groupes de clients |
| **E-KO** | les coûts, les barèmes, les remises, les clés de variables et leurs valeurs autorisées |

## Fonctionnalités

- **Liaison à l'API E-KO** — adresse, jeton, durée de cache, avec un test de
  liaison qui distingue « service injoignable » de « jeton refusé ».
- **Groupes de clients** — création et vérification des quatre groupes (B2C,
  B2B, Association, Revendeur), avec la correspondance vers les statuts E-KO.
- **Import d'un tiers** — création du compte boutique à partir d'une fiche
  client de l'ERP, rattachement d'un compte existant, et rangement dans le bon
  groupe. L'état civil et la civilité viennent du **premier contact** de la
  fiche : une société n'a ni prénom ni nom, et répéter son enseigne dans les
  deux champs donne « ATELIER ATELIER » partout où PrestaShop affiche
  « prénom nom ». La raison sociale complète, elle, reste dans le champ
  *Société*.
- **Sonde de support** — pour une adresse e-mail donnée : le tiers E-KO
  correspondant, le tarif qui s'applique et le nombre de documents à son nom.

## Prérequis

- PrestaShop **9.0** ou supérieur
- PHP **8.1** ou supérieur, avec l'extension cURL

> Le module exige PHP 8.1 (propriétés promues en lecture seule). PrestaShop 9
> l'impose déjà ; annoncer PrestaShop 8, qui tourne encore sur PHP 7.2,
> laisserait un marchand déposer le module puis perdre son back-office sur une
> erreur fatale — les fichiers sont chargés avant qu'un garde puisse
> s'exécuter.
- Une instance **E-KO** exposant son API v1, avec le module *Gestion Imprimerie*
- Un **jeton d'API** dédié à ce module, aux portées minimales

## Installation

### Depuis une archive

1. Télécharger le `.zip` de la [dernière version](../../releases/latest).
2. Back-office → **Modules** → *Téléverser un module*.
3. Installer, puis **Configurer**.

### Depuis les sources

```bash
cd /chemin/vers/prestashop/modules
git clone https://github.com/mvignaud/ekosyncimprimerie-prestashop.git ekosyncimprimerie
```

Le dossier **doit** s'appeler `ekosyncimprimerie` : PrestaShop déduit le nom de
la classe du nom du dossier.

## Configuration

| Réglage | Rôle |
|---|---|
| **Adresse de l'API** | Racine du service, sans `/api/v1`. **HTTPS obligatoire** — le module refuse une adresse en clair, le jeton circulant dans un en-tête. |
| **Jeton** | Jeton dédié, aux portées minimales. Laisser le champ vide conserve le jeton enregistré ; il n'est jamais renvoyé au navigateur. |
| **Durée du cache** | En secondes, `900` par défaut. Le cache ne conserve que des réponses d'E-KO, jamais un calcul local. `0` le désactive. |

Deux boutons complètent l'écran :

- **Tester la liaison** — interroge `/ping` *et* `/me` : le premier dit si le
  service répond, le second si le jeton est accepté. Un seul des deux ne
  suffirait pas à distinguer les deux pannes.
- **Rechercher** — sonde par adresse e-mail, sans aucune écriture.

## Groupes de clients

Le module pose quatre groupes et les tient à jour :

| Groupe | Correspond à | Affichage |
|---|---|---|
| **B2C** | `type_tiers = PARTICULIER` | TTC |
| **B2B** | `type_tiers = ENTREPRISE` ou `ADMINISTRATION` | HT |
| **Association** | `type_tiers = ASSOCIATION` | TTC |
| **Revendeur** | `statut_commercial = REVENDEUR` | HT |

Le statut commercial **prime** sur la nature fiscale : une entreprise
revendeuse est un revendeur. C'est la seule règle de priorité, et elle va dans
ce sens parce que le statut commercial ne dit rien d'autre que « à quel titre
on lui vend » — la question posée ici. La nature fiscale, elle, commande la TVA
et n'a pas à trancher un tarif.

> ### ⚠️ Ces groupes ne portent aucune remise, et c'est délibéré
>
> PrestaShop sait appliquer une remise par groupe. Poser 60 % sur « Revendeur »
> serait le geste naturel — et ce serait une **seconde implémentation** du
> barème, avec ses propres arrondis, à côté de celle d'E-KO. Les deux
> tomberaient juste la plupart du temps et divergeraient d'un centime le reste
> du temps.
>
> Le prix remisé vient donc d'E-KO, toujours. Ces groupes ne servent qu'à deux
> choses : décider si l'on affiche HT ou TTC, et savoir quel tarif demander.

Le bouton **Poser / vérifier les groupes** est idempotent : il crée ce qui
manque et **signale** ce qui s'écarte de ce qu'il attend — sans jamais le
corriger d'office.

Un groupe repris préexiste au module : ses réglages appartiennent au marchand.
Forcer l'affichage du groupe client natif basculerait toute la boutique en
TTC ; effacer la remise d'un groupe « Revendeur » déjà en place ferait perdre
son tarif à chaque revendeur. Le module dit ce qui cloche, le marchand
tranche.

## Sécurité

- **Aucun mot de passe n'est choisi, affiché ni envoyé.** Un compte importé est
  créé avec un secret aléatoire que personne ne connaît — pas même l'opérateur
  qui lance l'import — et le client le définit par la procédure normale de
  réinitialisation. Distribuer un mot de passe par un canal détourné est le
  défaut classique de ce genre d'import.
- **Le jeton n'apparaît jamais dans le HTML.** Le champ affiche un masque de
  longueur fixe ; enregistrer sans y toucher conserve le jeton existant.
- **HTTPS imposé** sur l'adresse de l'API — refus, pas avertissement.
- **Le jeton est effacé à la désinstallation.** Un secret qui survit à une
  désinstallation est un secret qui traîne.

## Ce que ce module ne fait pas encore

Annoncé pour éviter toute mauvaise surprise :

- pas de configurateur de prix côté boutique ;
- pas de synchronisation du catalogue ni des documents dans le compte client ;
- pas de téléversement de fichiers, ni de contrôle avant impression ;
- **pas de synchronisation des adresses** : l'API de l'ERP ne les expose pas
  encore. PrestaShop gère pourtant le multi-adresse, comme l'ERP — le jour où
  un point d'entrée existe, la reprise est immédiate ;
- **un seul contact par compte** : PrestaShop n'attache qu'un état civil à un
  compte client. Le multi-contact viendra.

Le montant d'encours et le délai de paiement (mode B2B de PrestaShop) restent à
zéro tant que l'API E-KO ne les expose pas : zéro signifie « aucun crédit
accordé », le défaut prudent.

## Développement

```bash
# Contrôle syntaxique
find . -name '*.php' -not -path './.git/*' -exec php -l {} \;

# Fabriquer l'archive distribuable
./bin/package.sh
```

Le logo est généré, pas dessiné à la main : `dev/faire_logo.py` écrit les PNG
sans aucune dépendance externe.

## Licence

MIT — voir [LICENSE](LICENSE).
