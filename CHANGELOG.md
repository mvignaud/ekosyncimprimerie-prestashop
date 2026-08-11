# Journal des modifications

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), et le
projet applique le [versionnage sémantique](https://semver.org/lang/fr/).

## [Non publié]

Rien pour l'instant.

## [0.5.1] — 2026-08-11

Aucune commande ne peut plus partir à un prix que l'ERP n'a pas donné.

### Corrigé

- **Le panier acceptait une commande au prix catalogue.** Le prix est mémorisé
  pour le couple *(configuration, quantité)* : changer la quantité invalide
  donc le prix connu jusqu'au retour du nouveau chiffrage. Dans cette fenêtre,
  le hook ne trouvait rien, se retirait, et PrestaShop appliquait son **prix
  catalogue**. Un client qui saisissait sa quantité puis cliquait aussitôt
  commandait au mauvais prix, sans que rien ne le signale — ni à lui, ni au
  marchand.

  Le bouton d'ajout au panier est désormais **verrouillé dès la frappe**, et ne
  se rouvre qu'au retour d'un prix effectivement mémorisé. Le verrou tombe au
  geste et non à l'appel : le placer dans la fonction d'appel le laissait
  arriver après les 400 ms d'attente, et la fenêtre restait grande ouverte.

- **Deux prix se contredisaient à l'écran.** Le bloc prix du thème affiche le
  prix catalogue tant que l'ERP n'a pas répondu, et le thème le re-rend à
  chaque changement de quantité — plus vite que le chiffrage. Il est désormais
  masqué sur les fiches liées : une seule vérité affichée, celle de l'ERP.

### Note d'exploitation

Après toute mise à jour du module, **vider les paquets concaténés** du thème
(`themes/*/assets/cache/*.js`), et pas seulement `var/cache/`. Sans cela
PrestaShop sert l'ancien JavaScript avec le nouveau gabarit — le nom du paquet
ne change pas, car il dépend de la LISTE des fichiers et non de leur contenu.

## [0.5.0] — 2026-08-11

Les informations commerciales du client, et une quantité au lieu de deux.

### Corrigé

- **Le configurateur avait sa propre quantité.** C'était le défaut le plus
  grave du module, et le plus silencieux. Le prix était mémorisé pour la
  quantité saisie dans le bloc, tandis que le hook de prix lisait celle du
  thème : un visiteur qui demandait 500 exemplaires voyait le prix ERP pour
  500 dans le configurateur, et PrestaShop lui facturait le **prix catalogue**,
  faute de prix mémorisé pour la quantité 1 restée dans le champ du thème.
  Deux champs, deux vérités — exactement ce que ce module existe pour éviter.
  Il n'y a plus qu'une quantité : celle de PrestaShop.
- **La TVA intracommunautaire était écrite dans le vide.** Le module la posait
  sur le compte client, où PrestaShop n'a aucun champ `vat_number` — ni dans la
  définition de l'objet, ni en base. La valeur était jetée sans erreur, à la
  création comme à la mise à jour. Elle va désormais sur les **adresses**, où
  PrestaShop la porte réellement, et sur toutes celles reprises du tiers.
- **Un compte existant ne recevait jamais aucune information commerciale.**
  Seuls le groupe et les adresses étaient rafraîchis ; encours et délai de
  paiement restaient à leur valeur d'origine pour toujours. Ils sont maintenant
  remis à jour, et le changement est **rapporté** — modifier le crédit d'un
  compte sans le dire ne laisse aucune trace.

### Ajouté

- **SIRET, encours autorisé et délai de paiement** sont repris depuis l'ERP
  (E-KO 1.95.98 et supérieur). Sur une version antérieure, le module se comporte
  comme avant : les champs restent à zéro, rien ne casse.
- **Les textes du configurateur sont traduisibles.** Onze chaînes que lit le
  client — prix, délai, messages d'erreur — vivaient dans le fichier JavaScript
  et le gabarit, hors de toute traduction : une boutique anglaise les affichait
  en français. Elles passent maintenant par le catalogue, et le contrôle qui
  garde les traductions balaie désormais **les gabarits aussi**, pas seulement
  le fichier principal. Un garde partiel donne une confiance imméritée.

### Limites connues

- `ape` reste vide : E-KO ne porte aucun code APE. Le déduire du SIREN serait
  une invention, et un code faux se recopie ensuite partout.
- Un encours **absent** dans E-KO signifie « aucun plafond fixé ». PrestaShop
  n'a pas de valeur pour dire « illimité » : on retient zéro, le côté prudent.
- « Fin de mois » n'a pas d'équivalent chez PrestaShop, qui ne connaît qu'un
  nombre de jours. « 30 jours fin de mois » vaut 30 ici, quand l'échéance
  réelle peut aller jusqu'à 60 : on sous-estime plutôt qu'on ne surestime.

## [0.4.1] — 2026-08-11

Le prix affiché et le prix facturé, sur toutes les boutiques et pas seulement
sur celles réglées comme la nôtre.

### Corrigé

Trois défauts de la même famille : le module reprenait une décision que
PrestaShop avait déjà prise. Chacun était juste sur une boutique aux réglages
par défaut et faux ailleurs — donc invisible au contrôle.

- **L'adresse fiscale.** Le module lisait toujours l'adresse de facturation ;
  la boutique désigne livraison **ou** facturation via `PS_TAX_ADDRESS_TYPE`,
  et la livraison est le défaut de PrestaShop. Dès que les deux adresses d'un
  client ne sont pas dans la même zone — Corse, outre-mer, un européen livré
  ailleurs qu'à son siège — le prix affiché n'était pas le prix facturé.
- **L'arrondi du total.** Le module appliquait une seule des **trois** formules
  de PrestaShop. Sur une boutique réglée « par article », le configurateur
  annonçait 25 422,00 € là où le panier facturait 25 420,00 €.
- **Le groupe du visiteur.** Un visiteur anonyme appartient à
  `PS_UNIDENTIFIED_GROUP`, pas au groupe zéro. Le module retombait sur
  « afficher TTC » quel que soit le réglage — faux sur toute boutique affichant
  en HT, ce qui est le cas courant en imprimerie B2B.

Deux corrections plus discrètes, de même nature :

- La précision d'arrondi vient désormais de la **devise** et non d'une
  supposition à deux décimales — le yen n'en a aucune, le dinar en a trois.
- Les montants passent par `Tools::ps_round()` et non par le `round()` de PHP,
  qui ignore le mode d'arrondi choisi par le marchand.

### Ajouté

- `dev/verifier-tva.php` — éprouve le chemin TVA sur un produit **réellement
  taxé**, avec un prix à nombre impair de centimes. Les trois défauts ci-dessus
  ont été trouvés par ce script, pas par relecture. Seize contrôles, décor créé
  et supprimé même en cas d'échec.
- `src/Configurateur/ReglesBoutique.php` — les décisions de la boutique, en un
  seul endroit : arrondi, précision, adresse fiscale, affichage HT ou TTC.
  Une règle recopiée à deux endroits diverge le jour où l'on n'en corrige
  qu'un.

## [0.4.0] — 2026-08-11

Le configurateur de prix. Une fiche produit peut désormais être chiffrée par
l'ERP, de la première option jusqu'à la facture.

### Ajouté

- **Liaison fiche ↔ produit d'atelier**, dans le formulaire natif du produit.
  Une fiche ne peut être liée qu'une fois et un produit d'atelier ne peut l'être
  qu'à une fiche — la règle est posée en base, donc vraie même pour une écriture
  qui contournerait le module.
- **Configurateur sur la fiche produit**. Les champs, leurs libellés, leurs
  unités, leurs valeurs par défaut et leurs listes de choix viennent de l'ERP :
  rien n'est écrit en dur. Ajouter une option à un produit dans l'atelier la
  fait apparaître en boutique sans toucher au module ni au thème.
- **Le prix de l'ERP s'impose** sur la fiche, au panier et en commande, par le
  hook `actionProductPriceCalculation`. La facture ne recalcule rien : elle
  recopie ce que la commande a figé.
- **Script de vérification** (`dev/verifier-chaine.php`) : éprouve la chaîne
  complète sur une boutique de recette, crée son décor et le supprime — même en
  cas d'échec. Il refuse de tourner sur une boutique qui a déjà des produits.

### Sécurité

- La réponse du serveur au navigateur est construite par **liste blanche**. Le
  chiffrage de l'ERP contient le prix de revient, le détail des coûts et la
  marge : un filtre des champs interdits laisserait passer le premier champ
  ajouté plus tard côté ERP.
- Le hook de prix **n'appelle jamais l'ERP** : PrestaShop l'exécute jusqu'à huit
  fois par fiche. L'appel se fait une seule fois, au changement d'option.

### Notes de conception

- **Le navigateur ne calcule rien**, pas même la multiplication par la quantité :
  le total vient du serveur. Le chiffrage d'atelier est dégressif — cent
  exemplaires ne coûtent pas cent fois un.
- **Sans prix mémorisé pour le couple (configuration, quantité), le module ne
  touche à rien** et laisse PrestaShop afficher son prix catalogue. Un prix
  inventé serait indétectable.
- L'appel au serveur utilise une **URL directe** et non la forme
  `/module/<module>/<contrôleur>`, qui dépend d'une règle de réécriture absente
  de bien des boutiques et répond alors 404.

### Limites connues

- Le chemin TVA n'est éprouvé que sur un produit **sans règle de taxe** : la
  garantie « au centime » porte sur le HT. PrestaShop connaît le régime du
  client, l'ERP non.
- Le produit doit être **personnalisable** et porter un champ texte : c'est ce
  qui donne son identité à une configuration, de la fiche jusqu'à la facture.

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

[Non publié]: https://github.com/mvignaud/ekosyncimprimerie-prestashop/compare/v0.5.1...HEAD
[0.5.1]: https://github.com/mvignaud/ekosyncimprimerie-prestashop/releases/tag/v0.5.1
[0.5.0]: https://github.com/mvignaud/ekosyncimprimerie-prestashop/releases/tag/v0.5.0
[0.4.1]: https://github.com/mvignaud/ekosyncimprimerie-prestashop/releases/tag/v0.4.1
[0.4.0]: https://github.com/mvignaud/ekosyncimprimerie-prestashop/releases/tag/v0.4.0
[0.3.0]: https://github.com/mvignaud/ekosyncimprimerie-prestashop/releases/tag/v0.3.0
[0.2.0]: https://github.com/mvignaud/ekosyncimprimerie-prestashop/releases/tag/v0.2.0
[0.1.0]: https://github.com/mvignaud/ekosyncimprimerie-prestashop/releases/tag/v0.1.0
