# Journal des modifications

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), et le
projet applique le [versionnage sémantique](https://semver.org/lang/fr/).

## [Non publié]

Rien pour l'instant.

## [0.3.0] — 2026-08-11

### Sécurité

- **Changer l'adresse de l'API efface le jeton enregistré.** Sans cela, le
  module envoyait le jeton de l'ancienne instance à la nouvelle adresse dès le
  premier appel : quelqu'un n'ayant que le droit de configurer les modules
  pointait le module sur un serveur qu'il contrôlait, cliquait « Tester la
  liaison » et repartait avec un secret que le formulaire masque pourtant. Le
  jeton fourni dans le même enregistrement est conservé.
- **Les adresses visant le réseau interne du serveur sont refusées** :
  `localhost`, plages privées, lien-local et service de métadonnées de
  l'hébergeur. Le contrôle porte sur l'adresse IP **résolue** et pas seulement
  sur le nom — un domaine public peut pointer sur une adresse privée.

### Ajouté

- **Catalogue de traduction `en-US`**, au format attendu par PrestaShop. Le
  catalogue est produit à partir des chaînes du code, jamais d'une liste tenue
  à la main : le script échoue si une chaîne manque ou si une chaîne disparue
  y subsiste, et l'intégration continue le rejoue à chaque poussée.

### Limites connues

- Les messages produits par `src/Client/` — comptes rendus d'import, écarts de
  groupes — restent en français : ils ne passent pas par le traducteur. Ils
  s'affichent à l'opérateur dans le back-office, jamais au client.

## [0.2.0] — 2026-08-11

### Ajouté

- **Reprise du carnet d'adresses.** Les adresses du tiers deviennent des
  adresses du compte boutique. L'opération est idempotente : une petite table
  retient quelle adresse d'ERP a produit quelle adresse boutique, si bien qu'un
  second import ne duplique rien.
- L'intitulé de l'adresse est **lisible** : le libellé de l'ERP s'il existe,
  sinon un intitulé déduit du type — « Adresse principale », « Facturation »,
  « Livraison ». Le client voit cet intitulé dans son carnet ; y laisser le
  code technique reviendrait à lui montrer `main`.

### Corrigé

- Le prénom, le nom et la civilité sont désormais repris du premier contact non
  privé de la fiche. Une société n'a ni prénom ni nom : répéter son enseigne
  dans les deux champs affichait « ATELIER ATELIER » partout où PrestaShop
  écrit « prénom nom ».

### Limites connues

- Une adresse sans voie ou sans ville est **refusée et signalée**, jamais créée
  amputée : PrestaShop exige les deux et un bon de livraison incomplet ne vaut
  rien. Un pays inconnu de la boutique est refusé ; un pays connu mais non
  activé passe, avec un avertissement.
- La reprise va de l'ERP vers la boutique seulement : une adresse créée par le
  client sur le site n'est pas remontée.

## [0.1.0] — 2026-08-11

Première version publique. Le module établit la liaison avec l'ERP et prépare
la structure client ; il ne touche encore ni au catalogue, ni au panier.

### Ajouté

- **Liaison à l'API E-KO** : adresse du service, jeton et durée de cache
  réglables depuis le back-office. L'adresse doit être en HTTPS — le module
  refuse une adresse en clair plutôt que d'avertir, le jeton circulant dans un
  en-tête de requête.
- **Test de liaison** interrogeant `/ping` *et* `/me`, afin de distinguer un
  service injoignable d'un jeton refusé. Un seul des deux appels laisserait
  les deux pannes indiscernables.
- **Groupes de clients** : création des quatre groupes (B2C, B2B, Association,
  Revendeur) et correspondance vers les statuts de l'ERP. L'opération est
  idempotente. Un groupe qui préexiste au module n'est jamais modifié : ses
  écarts sont signalés, et le marchand tranche.
- **Import d'un tiers** : création du compte boutique depuis une fiche client
  de l'ERP, ou rattachement d'un compte déjà présent, avec rangement dans le
  groupe correspondant. Le prénom, le nom et la civilité sont repris du premier
  contact non privé de la fiche ; la raison sociale reste dans le champ
  *Société*.
- **Sonde de support** : pour une adresse e-mail, affiche le tiers E-KO
  correspondant, le tarif qui lui est applicable et le nombre de documents à
  son nom. Aucune écriture.
- **Cache des lectures** : seules les requêtes de lecture réussies sont mises
  en cache, pour la durée réglée. Une écriture mise en cache disparaîtrait, une
  erreur mise en cache durerait. Changer l'adresse de l'API vide le cache — les
  réponses conservées portent l'identité de l'instance précédente.

### Sécurité

- Aucun mot de passe n'est choisi, affiché ni transmis lors d'un import : le
  compte est créé avec un secret aléatoire que personne ne connaît, et le
  client le définit par la procédure de réinitialisation.
- Le jeton d'API n'est jamais renvoyé au navigateur : le formulaire affiche un
  masque de longueur fixe, et l'enregistrer sans y toucher conserve la valeur.
- Le jeton est effacé à la désinstallation du module, avec les identifiants de
  groupes, le cache, et la table de correspondance entre comptes boutique et
  tiers — celle-ci relie une personne identifiable à un identifiant d'un
  système tiers et n'a pas à survivre au module.

### Notes de conception

- **Aucun prix n'est calculé par le module.** Les tarifs affichés sont ceux que
  renvoie l'ERP, mis en cache tels quels. Un calcul local, même de repli,
  créerait une seconde vérité et ferait diverger le site des devis.
- **Les groupes de clients ne portent aucune remise.** Une remise posée sur un
  groupe serait un second calcul, avec ses propres arrondis. Les groupes ne
  servent qu'à l'affichage HT/TTC et à savoir quel tarif demander.
- **Rien n'est écrit à l'installation.** Les réglages n'existent qu'une fois
  saisis et leur absence est gérée à la lecture, de sorte que le module
  fonctionne qu'il ait été posé depuis le back-office, un script ou la ligne de
  commande.
- La table de correspondance entre comptes boutique et tiers est créée à la
  première utilisation, pour la même raison.

### Limites connues

- Le montant d'encours et le délai de paiement du mode B2B restent à zéro tant
  que l'API de l'ERP ne les expose pas — zéro valant « aucun crédit accordé ».
- Le SIRET n'est renseigné que si l'ERP fournit un numéro à 14 chiffres
  valide : y écrire un SIREN à 9 chiffres donnerait un numéro faux dans un
  champ qu'un comptable lit comme un SIRET.
- Les adresses ne sont pas reprises : l'API de l'ERP ne les exposait pas
  encore à cette date (repris en 0.2.0).
- Un compte ne porte qu'un contact, PrestaShop n'attachant qu'un état civil par
  compte client.

[Non publié]: https://github.com/mvignaud/ekosyncimprimerie-prestashop/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/mvignaud/ekosyncimprimerie-prestashop/releases/tag/v0.3.0
[0.2.0]: https://github.com/mvignaud/ekosyncimprimerie-prestashop/releases/tag/v0.2.0
[0.1.0]: https://github.com/mvignaud/ekosyncimprimerie-prestashop/releases/tag/v0.1.0
