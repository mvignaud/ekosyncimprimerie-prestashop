# Journal des modifications

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), et le
projet applique le [versionnage sémantique](https://semver.org/lang/fr/).

## [Non publié]

Rien pour l'instant.

## [0.20.0] — 2026-08-21

Le client dépose son fichier d'impression.

### Ajouté

- **Un champ de dépôt sur la fiche produit configurable.** Le fichier part vers
  la boutique, qui le relaie à E-KO — jamais directement vers l'ERP : cela
  imposerait de confier le jeton d'API au navigateur, où il serait lisible par
  n'importe qui.
- **Le fichier porte la même référence que la ligne de commande.** C'est par
  elle, et par elle seule, qu'il rejoindra son dossier de fabrication. La
  calculer à deux endroits différents produirait deux références qui ne se
  rencontrent jamais, et le fichier resterait en transit sans que rien ne le
  signale.

### Détails qui comptent

- **Le fichier est annoncé avant d'être envoyé.** E-KO refuse alors un type
  interdit ou un poids excessif AVANT le transfert : sur une connexion lente,
  le client l'apprend en une seconde au lieu de téléverser quarante mégaoctets
  pour rien.
- **Le type est lu dans les octets, pas dans le nom du fichier.** Celui que le
  navigateur annonce se déduit de l'extension, que n'importe qui choisit.
- **Le message de refus vient d'E-KO et s'affiche tel quel** — « type non
  accepté », « fichier trop lourd ». Le remplacer par une formule générique
  priverait le client de ce qui lui permet de corriger.
- Un fichier trop lourd pour la boutique elle-même le dit clairement, plutôt
  que de laisser chercher l'erreur du côté de l'ERP.
- Le champ n'apparaît pas tant que la fonction est fermée : un champ qui
  refuserait chaque envoi vaut moins que pas de champ du tout.
- Redéposer le même fichier après un refus fonctionne — le champ est vidé après
  chaque tentative, sans quoi le navigateur n'émettrait plus rien et le client
  croirait la page morte.

## [0.19.0] — 2026-08-21

Les commandes de la boutique arrivent dans l'ERP.

### Ajouté

- **Chaque commande validée devient un devis dans E-KO**, en brouillon. Ni
  engagement, ni écriture comptable, ni document envoyé au client : un paiement
  par virement peut arriver trois jours plus tard sans que rien n'ait été promis
  entre-temps, et une commande abandonnée laisse un brouillon qui expire seul.
  Le réglage est **fermé par défaut** — installer cette version ne change rien
  tant qu'on ne l'ouvre pas.
- **Chaque ligne configurée porte une référence** que l'ERP sait relire. C'est
  par elle que les fichiers déposés par le client rejoindront plus tard leur
  ligne de dossier de fabrication : sans elle, un fichier resterait en transit
  indéfiniment sans que rien ne le signale.
- **Un préfixe de référence, réglable.** Il n'est pas décoratif : l'identifiant
  de configuration est un compteur local à la base d'une boutique, et deux
  boutiques poseront un jour la même valeur. La colonne qui l'accueille dans
  l'ERP n'a aucune contrainte d'unicité et le rattachement compare par égalité.
  Deux boutiques reliées au même compte, sans préfixe distinct, échangeraient
  les fichiers de leurs clients.

### Détails qui comptent

- **La remontée ne peut pas faire échouer une commande.** Tout est avalé, y
  compris une erreur de programmation. Le client a payé, sa commande existe : un
  ERP injoignable, un jeton révoqué ou une réponse illisible ne doivent jamais
  remonter jusqu'à lui. Un devis manquant se rattrape à la main ; une commande
  perdue sur la page de paiement, non. Chaque cas laisse sa raison dans le
  journal.
- **Cinq secondes d'attente au maximum**, et non les quinze du reste du module :
  cet appel a lieu pendant que le client attend sa page de confirmation.
- **Un renvoi ne crée pas un second devis.** L'appel porte une clé dérivée de la
  commande — jamais tirée au hasard, sans quoi la protection ne servirait à
  rien. Un client qui recharge sa confirmation, ou une notification de paiement
  rejouée, retombent sur le même devis.
- **Le prix envoyé est celui de la ligne de commande**, jamais un recalcul. La
  boutique a encaissé sur cette base ; un devis annonçant autre chose serait
  faux avant d'être lu.
- La configuration part dans le détail de la ligne, qui est conservé — et non
  dans le champ de variables, que l'API accepte puis ignore.

### Corrigé

- Le commentaire d'installation annonçait « un seul hook » alors que le module
  en enregistre huit.

## [0.11.0] — 2026-08-12

Le back-office rattrape la façade.

### Ajouté

- **Un seul sélecteur pour les deux catalogues.** Atelier et sous-traitance
  dans la même liste, chacun dans son groupe. Deux listes obligeraient à
  choisir d'abord laquelle regarder, alors qu'un marchand cherche un **produit**
  et se moque de savoir qui le fabrique. Un tarif périmé se signale dès le
  choix, pas après.
- **La fiche technique se saisit** — résolution, couleurs, fonds perdus, marge.
  Un champ vide retombe sur le réglage de boutique, puis sur l'usage du métier,
  et le filigrane montre ce qui s'appliquera.
- **Les prestations aussi** — bon à tirer, création graphique. Une ligne par
  option, `Libellé|supplément en euros`. La première ligne est le choix par
  défaut et doit être gratuite : démarrer sur une option payante gonflerait le
  prix d'appel sans que le visiteur l'ait demandé.

  Elles s'affichent en façade après le délai — ce sont des suppléments, et les
  placer avant ferait choisir des options avant de savoir ce que coûte le
  produit qu'elles complètent. Leur montant s'ajoute **côté serveur**, et le
  récapitulatif le montre séparément pour qu'un client rapproche son devis du
  prix affiché sans deviner ce qui a été ajouté.

### Corrigé

- **Un contrôleur AJAX ne rend plus 500 en silence.** Une exception non
  rattrapée partait en réponse vide : le navigateur n'avait ni prix ni raison,
  et le visiteur voyait un configurateur muet. Elle devient un refus lisible,
  et sa trace part dans `var/logs/` — le seul endroit joignable depuis une
  requête web.
- Trois libellés de l'ancien sélecteur restaient au catalogue de traduction
  sans plus exister dans le code. Le générateur les a signalés en orphelins.

## [0.10.0] — 2026-08-12

Les options portent enfin leur nom.

### Ajouté

- **Les libellés de l'ERP.** Les options s'affichaient avec leur code
  technique : `O100`, `99x210`, `NOF`. Elles portent maintenant leur nom —
  « 10 cm de diamètre (rond) », « DL », « Standard » — et leurs dimensions
  réelles quand l'ERP les connaît, ce qui rend les vignettes exactes plutôt
  que déduites.

  Le dictionnaire est incomplet par construction : un code sans libellé
  retombe sur son code. C'est le cas **normal**, pas une erreur — mieux vaut
  `NOF` que rien.

- **Les prestations de l'imprimeur** — bon à tirer, création graphique — se
  règlent au back-office, produit par produit, avec cascade sur un réglage de
  boutique. Elles ne remontent jamais à l'ERP : il sait ce que le sous-traitant
  fabrique, pas ce que l'imprimeur ajoute par-dessus.

  Leur supplément s'ajoute au prix **sans jamais le recalculer**, et l'addition
  se fait côté serveur. Un supplément additionné dans le navigateur serait un
  montant que la boutique n'a pas vérifié.

### Corrigé

- **Les dimensions de l'ERP sont en centimètres**, les codes bruts en
  millimètres. Vérifié sur trois formats normalisés — DL rendu 9,9 × 21 vaut
  99 × 210 mm, A3 rendu 29,7 × 42 vaut 297 × 420. Les mélanger dessinait un A4
  dix fois plus petit qu'un « 210x297 » sur la même ligne.

## [0.9.0] — 2026-08-12

Le configurateur prend la forme qu'on attend d'un configurateur d'imprimerie.

### Ajouté

- **Les formats se montrent, en vignettes à l'échelle.** Un rectangle
  proportionnel dessiné en SVG, avec ses dimensions en millimètres. « A6 » et
  « 100x300 » ne se comparent qu'en sachant ce qu'ils valent ; deux rectangles
  se comparent d'un regard. Les formats normalisés sont connus, les mesures
  brutes sont lues ; un code illisible retombe sur un rectangle neutre plutôt
  que d'inventer des proportions fausses.
- **Les autres critères passent en liste déroulante.** Un grammage n'a pas de
  forme : en faire des cartes ne donnerait que des libellés dans des cadres,
  occupant dix fois la place pour la même information.
- **Les délais affichent leur date de livraison estimée**, comptée en jours
  **ouvrés** et écrite dans la langue du visiteur — côté serveur, comme les
  montants.
- **Le bouton d'ajout au panier vit dans le récapitulatif**, sous le prix.
  Celui du thème est plus bas, hors de vue une fois la grille dépliée. Il ne le
  double pas : il le **déclenche**, pour que le panier reçoive exactement ce que
  PrestaShop attend.
- **Un bloc fiche technique et gabarits.** Résolution, couleurs, fonds perdus,
  marge de sécurité — réglables au back-office, avec les usages du métier pour
  défaut. Les gabarits sont des fichiers : tant qu'aucun n'est déposé, la
  colonne ne s'affiche pas, une liste de liens morts valant moins que rien.

### Corrigé

- Quatre libellés de la fiche technique étaient passés à `trans()` **en
  variable**. L'extraction ne lit que des littéraux : ils échappaient à toute
  traduction, sans qu'aucun garde puisse le voir. C'est le générateur de
  catalogue qui les a signalés, en orphelins.

### Reste à faire

BAT numérique et création graphique — ce sont des prestations de l'imprimeur,
pas du catalogue du sous-traitant : elles se régleront au back-office produit.
Et les options s'affichent encore avec leur code brut, l'ERP tenant un
dictionnaire de libellés que l'API n'expose pas.

## [0.8.0] — 2026-08-12

Le configurateur de sous-traitance, et la page qui le porte.

### Ajouté

- **Un second configurateur**, pour les fiches liées au catalogue de
  sous-traitance. Il descend un **arbre de choix** — chaque option restreint la
  suivante — puis affiche la grille tarifaire.

  Une **ligne de cartes par critère**, pas des listes déroulantes : un acheteur
  de flyers compare des formats et des grammages, et comparer suppose de voir
  côte à côte. Les lignes apparaissent au fur et à mesure, parce que l'arbre est
  élagué : une carte de visite tombe de 11 760 combinaisons théoriques à 220
  réelles, et afficher toutes les lignes d'un coup obligerait à les remplir de
  choix qui n'existent pas ensemble.

  **Chaque quantité porte son prix**, et le prix montré est celui du délai le
  moins cher — pour que les quantités se comparent entre elles, et non au délai
  qui se trouve sélectionné.

  **Le délai devient un choix** quand il y en a plusieurs, avec « Meilleure
  offre » sur le moins cher. Un seul délai proposé n'est pas un choix : la ligne
  disparaît plutôt que de demander de trancher entre une option et rien.

- **Un relais côté boutique.** Le navigateur ne peut pas interroger l'ERP :
  l'appel porterait le jeton de la boutique, lisible dans l'onglet réseau du
  premier visiteur venu. La boutique parle à l'ERP, le jeton ne sort jamais du
  serveur, et la réponse est reconstruite par liste blanche.

- **La page produit s'élargit** quand le module la pilote — colonne latérale
  retirée, image ramenée à 38 %, configurateur à 62 %. Seulement sur ces fiches :
  les autres gardent la mise en page du thème, intacte.

### Limites connues

- Les options s'affichent avec leur **code brut** (`115CP`, `NOF`) et non leur
  libellé. L'ERP tient un dictionnaire de libellés que l'API n'expose pas
  encore.
- Le bloc **fiche technique** (résolution, couleurs, fonds perdus, gabarits)
  reste à faire.

## [0.7.0] — 2026-08-12

Une fiche peut désormais pointer **deux natures** de produit E-KO.

### Ajouté

- **La liaison porte une source.** E-KO sait chiffrer de deux façons, et elles
  n'ont presque rien en commun :

  - `atelier` — le prix est **calculé** : matières, temps machine, imposition,
    majorations. Les options sont des champs libres, l'identifiant un nombre.
  - `printoclock` — le prix est **lu** dans une grille rapportée du
    sous-traitant, par couple (quantité, délai). Les options forment un **arbre**
    de choix discrets, et l'identifiant est un code.

  Une colonne entière ne pouvait donc pas suffire, et deviner la source à la
  forme de l'identifiant aurait marché jusqu'au premier code numérique.

### Migration

La table de liaison est élargie **au premier usage**, sans intervention : les
deux colonnes sont ajoutées, l'ancien identifiant est **recopié**, puis
l'ancienne colonne est retirée. Dans cet ordre — retirer d'abord perdrait
toutes les liaisons du marchand en silence.

Les liaisons existantes deviennent `atelier`, et rien ne change pour elles.

## [0.6.1] — 2026-08-12

### Corrigé

- **Le bloc de personnalisation natif est masqué.** PrestaShop identifie une
  configuration par une « customization », et le module s'en sert comme
  support : il y range la configuration en clair, qui suit jusqu'à la facture.
  Mais le thème rendait aussi ce champ au client, avec sa zone de texte et son
  « N'oubliez pas de sauvegarder votre personnalisation ». C'est de la
  plomberie, pas une question à poser à un acheteur — et ce qu'il y aurait
  écrit **aurait écrasé** la configuration, donc le libellé de sa facture.

  Le champ n'étant pas obligatoire, le masquer ne bloque aucun ajout au panier.
  Le configurateur devient la seule saisie.

## [0.6.0] — 2026-08-12

Le configurateur ressemble enfin à un configurateur.

### Ajouté

- **Une feuille de style.** Le module n'en embarquait aucune : dix classes
  référencées, zéro règle. Le configurateur portait ce que le thème voulait
  bien lui donner, et le prix se perdait au milieu du formulaire.
- **Le montant est écrit par PrestaShop**, pas par le navigateur. Il affichait
  « 36.13 € » sur une boutique française ; le JavaScript ne sait ni quelle
  langue ni quelle devise il sert, `formatPrice()` si.

### Corrigé

- **Le configurateur s'affichait SOUS le bouton d'ajout au panier.** Il faut
  configurer avant de commander. Il se rend désormais là où le thème affiche
  son prix (`displayProductPriceBlock` / `after_price`), donc en tête de fiche.
  L'ancien point d'accroche reste enregistré comme repli pour les thèmes qui
  n'exposent pas le premier — un garde interdit le double affichage.
- **Un seul des deux boutons d'ajout au panier était verrouillé.** Les thèmes
  modernes en posent deux — « Ajouter au panier » et « Acheter maintenant » —
  avec le même attribut. Le verrou de la 0.5.1 n'en fermait qu'un, et le second
  menait droit au paiement.
- **La configuration partait en JSON sur la commande et la facture.** Le client
  lisait `{"width":"1000","height":"700"}` sous « Votre personnalisation ».
  Elle s'écrit maintenant en toutes lettres, avec les libellés et les unités de
  l'ERP : « Largeur : 1000 mm · Hauteur : 700 mm · Bâche : POWERJET 440g ».
- **Le bloc était dimensionné en `rem`.** Beaucoup de thèmes posent
  `html { font-size: 10px }` : le configurateur s'y retrouvait à 62 % de sa
  taille — libellés à 8,75 px, prix total à 17,5 px. Tout est en `em`, qui
  hérite du thème au lieu de parier sur sa racine.

### Note d'exploitation

Vider les paquets concaténés d'une boutique **en exploitation** la laisse sans
style le temps d'une requête, celle qui les régénère. Le faire aux heures
creuses, ou accepter ce battement.

## [0.5.2] — 2026-08-11

Le dernier champ B2B que la boutique ne savait pas remplir.

### Ajouté

- **Le code APE** est repris depuis l'ERP (E-KO 1.95.99 et supérieur) et posé
  sur le compte client. Sur une version antérieure, le champ reste vide et rien
  ne casse.

### Notes de conception

- Le code **n'est pas déduit du SIREN**. Deux entreprises aux numéros voisins
  exercent des métiers sans rapport, et un code faux se recopie ensuite partout
  où il passe. Sans code chez l'ERP, le champ reste vide.
- Un code invalide est **laissé de côté**, jamais écrit tel quel : PrestaShop
  refuse l'enregistrement du client **entier** sur un `ape` mal formé — mesuré,
  `validateFields()` rend « La propriété Customer->ape n'est pas valide ». Le
  compte ne serait pas créé du tout. Un `ape` vide, lui, passe sans réserve.
- La forme est rangée à l'entrée — majuscules, sans séparateur — même si l'ERP
  la range déjà : un intégrateur tiers peut avoir écrit par un autre chemin.

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

[Non publié]: https://github.com/mvignaud/ekosyncimprimerie-prestashop/compare/v0.11.0...HEAD
[0.11.0]: https://github.com/mvignaud/ekosyncimprimerie-prestashop/releases/tag/v0.11.0
[0.10.0]: https://github.com/mvignaud/ekosyncimprimerie-prestashop/releases/tag/v0.10.0
[0.9.0]: https://github.com/mvignaud/ekosyncimprimerie-prestashop/releases/tag/v0.9.0
[0.8.0]: https://github.com/mvignaud/ekosyncimprimerie-prestashop/releases/tag/v0.8.0
[0.7.0]: https://github.com/mvignaud/ekosyncimprimerie-prestashop/releases/tag/v0.7.0
[0.6.1]: https://github.com/mvignaud/ekosyncimprimerie-prestashop/releases/tag/v0.6.1
[0.6.0]: https://github.com/mvignaud/ekosyncimprimerie-prestashop/releases/tag/v0.6.0
[0.5.2]: https://github.com/mvignaud/ekosyncimprimerie-prestashop/releases/tag/v0.5.2
[0.5.1]: https://github.com/mvignaud/ekosyncimprimerie-prestashop/releases/tag/v0.5.1
[0.5.0]: https://github.com/mvignaud/ekosyncimprimerie-prestashop/releases/tag/v0.5.0
[0.4.1]: https://github.com/mvignaud/ekosyncimprimerie-prestashop/releases/tag/v0.4.1
[0.4.0]: https://github.com/mvignaud/ekosyncimprimerie-prestashop/releases/tag/v0.4.0
[0.3.0]: https://github.com/mvignaud/ekosyncimprimerie-prestashop/releases/tag/v0.3.0
[0.2.0]: https://github.com/mvignaud/ekosyncimprimerie-prestashop/releases/tag/v0.2.0
[0.1.0]: https://github.com/mvignaud/ekosyncimprimerie-prestashop/releases/tag/v0.1.0
