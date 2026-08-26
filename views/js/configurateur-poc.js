/**
 * EKO Sync — Imprimerie
 *
 * Le configurateur de sous-traitance : un arbre de choix, puis une grille.
 *
 * ─── LA FORME, ET POURQUOI ─────────────────────────────────────────────────
 *
 * Une LIGNE DE CARTES par critère, empilées. Pas des listes déroulantes : un
 * acheteur de flyers compare des formats et des grammages, et comparer suppose
 * de voir côte à côte. Une liste déroulante cache tout sauf un.
 *
 * TOUTES LES LIGNES SONT VISIBLES D'EMBLÉE, avec un choix par défaut. Un
 * configurateur qui les dévoile une par une cache au visiteur ce qu'on va lui
 * demander : il ne sait pas s'il en a pour trois clics ou pour dix, et il ne
 * peut pas revenir en arrière sur ce qu'il n'a pas encore vu.
 *
 * L'arbre étant ÉLAGUÉ — chaque choix restreint le suivant, ce qui fait tomber
 * une carte de visite de 11 760 combinaisons à 220 — les options d'une étape
 * dépendent des précédentes. On descend donc l'arbre une fois au chargement en
 * prenant la première option à chaque rang, ce qui donne à la fois une
 * configuration valide par défaut ET la liste des choix de chaque étape.
 *
 * Changer un choix au rang N invalide les rangs suivants : on redescend depuis
 * là, en gardant ce qui reste valide.
 *
 * ─── CE QUE LE NAVIGATEUR NE FAIT PAS ──────────────────────────────────────
 *
 * Il ne calcule aucun prix, et ne met aucun montant en forme. Le serveur rend
 * les deux : le nombre pour comparer, le texte pour afficher. Le JavaScript ne
 * sait ni quelle langue ni quelle devise il sert.
 *
 * Il ne devine pas non plus les libellés : ils viennent des attributs `data-`
 * du gabarit, donc du catalogue de traduction.
 */
(function () {
  'use strict';

  function racine() {
    return document.querySelector('.eko-poc');
  }

  /**
   * Toutes les trois secondes, et pas moins.
   *
   * Un calcul en prend dix-sept ; relire toutes les trois secondes faisait
   * vingt requêtes par minute et par visiteur en attente. Le sondage ne peut
   * PAS être mis en cache — c'est un état — et cinq visiteurs simultanés
   * dépassaient à eux seuls le seau de requêtes de la boutique. Cinq secondes
   * divisent la charge par 1,7 sans que le visiteur le voie.
   */
  var INTERVALLE_SONDE = 5000;

  /**
   * Deux minutes, et on abandonne.
   *
   * Le serveur a ses propres gardes — une demande abandonnée se reconnaît à
   * son âge — mais un réseau coupé ne les atteint jamais. Sans cette borne, la
   * page tournerait jusqu'à ce qu'on la ferme.
   */
  var ATTENTE_MAX = 120000;

  function txt(r, cle, defaut) {
    return r.dataset[cle] || defaut;
  }

  function echapper(t) {
    var d = document.createElement('div');
    d.textContent = t;

    return d.innerHTML;
  }


  /**
   * Les dimensions d'un format, en millimètres.
   *
   * Deux écritures cohabitent chez le fournisseur : les formats normalisés
   * (`A5`, `DL`) et les mesures brutes (`100x100`, `85x54`). Les premières ne
   * se déduisent pas, on les connaît ; les secondes se lisent.
   *
   * Faute de reconnaître le code, on rend `null` — et la vignette retombe sur
   * un rectangle neutre plutôt que d'inventer des proportions fausses, qui
   * feraient croire à un format carré ce qui est un format long.
   */
  function dimensions(code) {
    var connus = {
      A3: [297, 420], A4: [210, 297], A5: [148, 210], A6: [105, 148],
      A7: [74, 105], DL: [99, 210], CV: [85, 54]
    };

    var k = String(code).toUpperCase().replace(/\s/g, '');

    if (connus[k]) {
      return { l: connus[k][0], h: connus[k][1] };
    }

    var m = k.match(/^(\d{2,4})[X*](\d{2,4})$/);

    return m ? { l: parseInt(m[1], 10), h: parseInt(m[2], 10) } : null;
  }

  /**
   * La vignette d'un format : un rectangle À L'ÉCHELLE.
   *
   * C'est tout l'intérêt — un A6 et un A4 se distinguent d'un coup d'œil par
   * leur taille relative, ce qu'aucun libellé ne fait. Le plus grand format de
   * la ligne fixe l'échelle, les autres s'y rapportent.
   *
   * Le SVG est écrit ici plutôt que chargé : une image par format supposerait
   * un fichier par format, à produire et à maintenir. Le marchand pourra en
   * poser une depuis le back-office s'il veut la sienne ; d'ici là le dessin
   * suffit, et il est juste.
   *
   * ⚠️ Les cotes sont reçues, pas relues depuis le code. Cette fonction les a
   * longtemps redéduites de son côté, et elle n'y arrivait pas pour les formats
   * dont le code ne porte pas ses mesures — les ronds notamment. La carte
   * affichait alors « 100 × 100 mm », venu de l'ERP, au-dessus d'un rectangle
   * générique 42 × 30. Deux sources pour une même mesure en donnent tôt ou tard
   * deux réponses ; il n'y en a plus qu'une.
   */
  function vignetteFormat(d, refMax, rond, nom, repere) {
    var boite = 96;
    var ouvre = '<svg class="eko-poc__svg" viewBox="0 0 ' + boite + ' ' + boite + '"'
      + ' role="img" aria-hidden="true">';

    if (!d) {
      return ouvre + '<rect x="14" y="24" width="68" height="48" rx="3" class="eko-poc__svg-forme"/></svg>';
    }

    var marge = 10;
    var echelle = (boite - marge * 2) / Math.max(refMax || Math.max(d.l, d.h), 1);
    var l = Math.max(10, d.l * echelle);
    var h = Math.max(10, d.h * echelle);
    var sortie = ouvre;

    // Le format de RÉFÉRENCE, en pointillés derrière : c'est lui qui donne
    // l'échelle. Un rectangle seul dit ses proportions, pas sa taille — « A6 »
    // et « A4 » se ressemblent tant qu'on n'a rien à quoi les rapporter.
    if (repere && (repere.l !== d.l || repere.h !== d.h)) {
      var rl = repere.l * echelle;
      var rh = repere.h * echelle;

      sortie += '<rect x="' + ((boite - rl) / 2).toFixed(1) + '" y="' + ((boite - rh) / 2).toFixed(1) + '"'
        + ' width="' + rl.toFixed(1) + '" height="' + rh.toFixed(1) + '" rx="2"'
        + ' class="eko-poc__svg-repere"/>';
    }

    sortie += rond
      ? '<circle cx="' + (boite / 2) + '" cy="' + (boite / 2) + '"'
        + ' r="' + (Math.max(l, h) / 2).toFixed(1) + '" class="eko-poc__svg-forme"/>'
      : '<rect x="' + ((boite - l) / 2).toFixed(1) + '" y="' + ((boite - h) / 2).toFixed(1) + '"'
        + ' width="' + l.toFixed(1) + '" height="' + h.toFixed(1) + '" rx="2" class="eko-poc__svg-forme"/>';

    // Le nom DANS la silhouette, comme sur la référence — à condition qu'il y
    // tienne. Un « 10 cm de diamètre (rond) » écrit dans un rond de trente
    // pixels déborderait de partout ; le libellé sous la carte le porte déjà.
    //
    // La taille du texte se DÉDUIT de la place disponible. Une taille fixe
    // débordait : « 20x15 » s'affichait « 0x1 », rogné des deux côtés par sa
    // propre silhouette. On compte 0,62 de large par caractère pour une grasse,
    // et on renonce en dessous de 8 — illisible, autant ne rien écrire.
    var court = String(nom || '');
    var taille = court === '' ? 0 : Math.min(22, (l * 0.92) / (court.length * 0.62), h * 0.55);

    if (court !== '' && court.length <= 6 && taille >= 8) {
      sortie += '<text x="' + (boite / 2) + '" y="' + (boite / 2) + '"'
        + ' class="eko-poc__svg-nom" font-size="' + taille.toFixed(1) + '"'
        + ' text-anchor="middle" dominant-baseline="central">'
        + echapper(court) + '</text>';
    }

    return sortie + '</svg>';
  }

  /**
   * Les pictogrammes des réassurances, dessinés ici.
   *
   * Dessinés plutôt que chargés : quatre fichiers d'icônes, c'est quatre
   * requêtes de plus et un dossier à maintenir dans un module distribué. Et un
   * tracé suit la couleur du texte, ce qu'une image ne fait pas.
   *
   * Aucun logo officiel ici : une marque territoriale ou un label appartient à
   * son émetteur. Le marchand pointe le sien depuis le back-office.
   */
  function pictogramme(nom) {
    var traits = {
      origine: '<path d="M12 2 4 6v6c0 5 3.4 9.1 8 10 4.6-.9 8-5 8-10V6l-8-4Z"/>'
        + '<path d="m9 12 2 2 4-4"/>',
      livraison: '<path d="M1 6h13v10H1z"/><path d="M14 9h4l3 3v4h-7z"/>'
        + '<circle cx="6" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
      fichier: '<path d="M13 2H6a1 1 0 0 0-1 1v18a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8l-6-6Z"/>'
        + '<path d="M13 2v6h6"/><circle cx="11" cy="14" r="2.5"/><path d="m13 16 2.5 2.5"/>',
      paiement: '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>'
        + '<path d="M6 15h4"/>'
    };

    return '<svg class="eko-poc__reassure-icone" viewBox="0 0 24 24" fill="none"'
      + ' stroke="currentColor" stroke-width="1.6" stroke-linecap="round"'
      + ' stroke-linejoin="round" aria-hidden="true">' + (traits[nom] || traits.origine) + '</svg>';
  }

  /**
   * Le dessin d'une prestation, choisi d'après SON LIBELLÉ.
   *
   * ─── POURQUOI D'APRÈS LE LIBELLÉ, ET PAS D'APRÈS LE RANG ───────────────────
   *
   * Le marchand saisit ses prestations en texte libre, dans l'ordre qui lui
   * plaît, et il en ajoute. Attribuer le premier dessin à la première ligne
   * donnerait un crayon à « je fournis mon fichier » dès que quelqu'un
   * réordonne sa liste — un dessin faux est pire qu'un dessin générique, il
   * annonce autre chose que ce qu'on achète.
   *
   * On lit donc les mots. Les accents sont retirés avant la comparaison : le
   * marchand écrit « créé » ou « cree » selon son clavier, et les deux doivent
   * tomber sur le même dessin.
   *
   * Rien ne correspond → le dessin neutre. Jamais de dessin au hasard.
   */
  function dessinPrestation(nom) {
    var traits = {
      // Un fichier qui monte : « je fournis mon fichier ».
      depot: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z"/>'
        + '<path d="M14 3v5h5"/><path d="M12 18v-6"/><path d="m9.5 14.5 2.5-2.5 2.5 2.5"/>',
      // Un crayon sur une page : « je crée mon fichier en ligne ».
      crayon: '<path d="M13 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6"/>'
        + '<path d="M18.5 2.5a2.1 2.1 0 0 1 3 3L14 13l-4 1 1-4 7.5-7.5Z"/>',
      // Une palette : « je confie ma création à un graphiste ».
      graphiste: '<path d="M12 3a9 9 0 1 0 0 18c1.1 0 1.7-.8 1.7-1.6 0-.5-.2-.8-.5-1.1'
        + '-.3-.3-.5-.7-.5-1.1 0-.9.7-1.6 1.6-1.6H16a5 5 0 0 0 5-5c0-4-4-7.6-9-7.6Z"/>'
        + '<circle cx="7.5" cy="11.5" r="1"/><circle cx="10.5" cy="7.5" r="1"/>'
        + '<circle cx="15" cy="8.5" r="1"/>',
      // Un œil sur une page : le bon à tirer, qu'on regarde avant d'imprimer.
      relecture: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7"/>'
        + '<path d="M14 3v5h5"/>'
        + '<path d="M8 15.5s1.6-2.5 4-2.5 4 2.5 4 2.5-1.6 2.5-4 2.5-4-2.5-4-2.5Z"/>'
        + '<circle cx="12" cy="15.5" r="1"/>',
      // Une page barrée : « sans BAT », « aucune création ».
      sans: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z"/>'
        + '<path d="M14 3v5h5"/><path d="m9.5 17 5-5"/><path d="m9.5 12 5 5"/>',
      // Le neutre : une page cochée. Il ne raconte rien de faux.
      neutre: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z"/>'
        + '<path d="M14 3v5h5"/><path d="m9 15 2 2 4-4"/>'
    };

    // Les mots sont cherchés DANS L'ORDRE : « sans » l'emporte sur tout, parce
    // que « sans création graphique » contient « création ».
    var regles = [
      ['sans', ['sans ', 'aucun', 'pas de ', 'non ']],
      ['depot', ['fourni', 'mon fichier', 'mes fichiers', 'upload', 'envoi', 'depot', 'pret a imprimer']],
      ['graphiste', ['graphiste', 'confie', 'sur mesure', 'devis', 'accompagn', 'studio', 'avance', 'premium', 'complete']],
      ['crayon', ['creation', 'cree', 'creer', 'en ligne', 'personnalis', 'maquette', 'design']],
      ['relecture', ['bat', 'bon a tirer', 'epreuve', 'validation', 'verif', 'controle', 'relecture']]
    ];

    var plat = String(nom || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    var choisi = 'neutre';

    regles.some(function (regle) {
      return regle[1].some(function (mot) {
        if (plat.indexOf(mot) === -1) {
          return false;
        }

        choisi = regle[0];

        return true;
      });
    });

    return '<svg class="eko-poc__presta-icone" viewBox="0 0 24 24" fill="none"'
      + ' stroke="currentColor" stroke-width="1.5" stroke-linecap="round"'
      + ' stroke-linejoin="round" aria-hidden="true">' + traits[choisi] + '</svg>';
  }

  /** Tous les boutons d'ajout au panier — les thèmes en posent souvent deux. */
  function boutonsPanier() {
    return document.querySelectorAll('[data-button-action="add-to-cart"]');
  }

  /**
   * Le formulaire d'ajout au panier de PrestaShop.
   *
   * Il est MASQUÉ sur les fiches que le module pilote, jamais retiré : il porte
   * le jeton, l'identifiant produit, l'identifiant de configuration et la
   * quantité. Un élément masqué reste dans le document et reste déclenchable
   * par script — le retirer casserait l'ajout au panier au lieu de le nettoyer.
   */
  function formulairePanier() {
    return document.querySelector('#add-to-cart-or-refresh');
  }

  /**
   * Ouvre ou ferme la commande.
   *
   * ⚠️ On ne RÉOUVRE que ce qu'on a soi-même fermé. `boutonsPanier()` ramène
   * tous les boutons d'ajout du document, et certains sont désactivés par la
   * boutique pour de bonnes raisons — rupture de stock, produit non
   * commandable. Les rouvrir en bloc laisserait commander ce que PrestaShop
   * refuse. On note donc ceux qu'on ferme, et eux seuls sont rouverts.
   */
  var fermesParNous = [];

  function commande(autorisee, motif) {
    if (!autorisee) {
      boutonsPanier().forEach(function (b) {
        if (b.disabled) {
          return;
        }

        b.disabled = true;
        b.setAttribute('aria-disabled', 'true');
        b.setAttribute('title', motif || '');

        if (fermesParNous.indexOf(b) === -1) {
          fermesParNous.push(b);
        }
      });

      return;
    }

    fermesParNous.forEach(function (b) {
      b.disabled = false;
      b.removeAttribute('aria-disabled');
      b.removeAttribute('title');
    });

    fermesParNous = [];
  }

  /**
   * Masquer ce que le thème affiche de son côté.
   *
   * Le prix natif montre le prix catalogue — zéro sur ces fiches — et le bloc
   * de personnalisation expose la plomberie du module. Sur un thème qui rend le
   * configurateur DANS le bloc prix, on masque les voisins et pas le bloc,
   * sinon on s'emporte avec.
   */
  function masquerNatif() {
    var r = racine();
    var prix = document.querySelector('.product-prices');

    if (prix) {
      if (r && prix.contains(r)) {
        Array.prototype.forEach.call(prix.children, function (enfant) {
          if (!enfant.contains(r)) {
            enfant.style.display = 'none';
          }
        });
      } else {
        prix.style.display = 'none';
      }
    }

    var perso = document.querySelector('.product-customization');

    if (perso) {
      perso.style.display = 'none';
    }
  }

  /**
   * Sort le configurateur de la colonne d'achat et le pose SOUS la fiche.
   *
   * ─── POURQUOI DÉPLACER PLUTÔT QUE CHANGER DE HOOK ──────────────────────
   *
   * Le module s'accroche à `displayProductPriceBlock`, qui rend le bloc dans la
   * colonne étroite réservée au prix. Un configurateur à quatorze formats et
   * vingt-trois quantités n'y tient pas : il écrasait l'image du produit, sa
   * description et ses arguments de vente, qui se retrouvaient tous sous la
   * ligne de flottaison — ou nulle part.
   *
   * Changer de hook ne réglerait pas le problème : les hooks produit d'un thème
   * rendent tous DANS une colonne. Il faut sortir de la grille, ce que seul le
   * navigateur peut faire une fois la page bâtie.
   *
   * La fiche retrouve ainsi sa forme habituelle — image à gauche, texte et
   * appel à l'action à droite — et le configurateur prend toute la largeur
   * juste en dessous, comme sur la référence.
   */
  function deplacerSousLaFiche(r) {
    var fiche = document.querySelector('.product-container');

    if (!fiche || !fiche.parentElement || fiche.contains(r) === false) {
      return null;
    }

    var section = document.createElement('section');
    section.className = 'eko-poc-section';
    section.id = 'eko-configurateur';

    // ⚠️ Juste après la RANGÉE d'en-tête, pas après tout le conteneur produit.
    // Ce conteneur porte aussi les onglets « Description / Détails du produit »
    // du thème : poser le configurateur après lui le renvoyait sous des pages
    // de texte, alors qu'il doit venir avant. On vise donc la rangée, et les
    // onglets tombent naturellement en dessous.
    var entete = r.closest('.row') || fiche.firstElementChild;

    if (entete && entete.parentElement) {
      entete.parentElement.insertBefore(section, entete.nextSibling);
    } else {
      fiche.parentElement.insertBefore(section, fiche.nextSibling);
    }

    section.appendChild(r);

    // ⚠️ La fiche technique voyage AVEC le configurateur. Elle est rendue par
    // le même hook, donc dans le même bloc prix — et ce bloc est masqué juste
    // après. La laisser sur place l'aurait fait disparaître de la page en même
    // temps, alors qu'elle avait été demandée expressément.
    var tech = document.querySelector('.eko-tech');

    if (tech) {
      section.appendChild(tech);
    }

    return section;
  }

  /**
   * Le bouton qui descend vers le configurateur, à la place qu'il occupait.
   *
   * Sans lui, le visiteur qui arrive sur la fiche voit une image, un texte, et
   * rien qui indique où l'on choisit et où l'on achète — le configurateur étant
   * désormais plus bas.
   */
  function ancreVersConfigurateur(r, section) {
    var hote = document.querySelector('.summary-container')
      || document.querySelector('.product-prices');

    if (!hote || !section) {
      return;
    }

    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'eko-poc-ancre';
    b.textContent = txt(r, 'jeConfigure', 'Je configure mon produit');
    b.addEventListener('click', function () {
      section.scrollIntoView({ block: 'start', behavior: 'smooth' });
    });

    // ⚠️ ENFANT DIRECT du conteneur, toujours. Glissé au plus près de la
    // description, le bouton tombait dans un bloc que le thème neutralise —
    // `visibility: hidden` et `font-size: 0` sur ce qu'il n'attend pas — et il
    // occupait zéro pixel sans que rien ne le signale. Mesuré, deux fois.
    //
    // On vise donc la position par le bloc SUIVANT — les informations produit
    // ferment la colonne, le bouton se pose juste avant, donc après les
    // arguments de vente. Faute de les trouver, il ferme la colonne lui-même.
    var informations = hote.querySelector(':scope > .product-information');

    if (informations) {
      hote.insertBefore(b, informations);
    } else {
      hote.appendChild(b);
    }
  }

  /**
   * La hauteur de ce qui reste collé en haut de l'écran.
   *
   * Le récapitulatif colle lui aussi, et il passait SOUS l'en-tête du thème :
   * son titre et son prix disparaissaient derrière le menu dès qu'on faisait
   * défiler. Mesuré à l'exécution plutôt que codé en dur — la hauteur d'un
   * en-tête dépend du thème, de sa barre d'annonce, et du fait qu'il se réduise
   * ou non au défilement.
   *
   * On ne retient que ce qui est ancré EN HAUT : un élément collant à mi-page
   * ne masque rien.
   */
  function hauteurCollante() {
    var haut = 0;

    document.querySelectorAll('header, .header, [class*="sticky"], [class*="fixed"]')
      .forEach(function (e) {
        var st = getComputedStyle(e);

        if (st.position !== 'fixed' && st.position !== 'sticky') {
          return;
        }

        var b = e.getBoundingClientRect();

        if (b.height === 0 || b.top > 4 || b.bottom <= 0) {
          return;
        }

        haut = Math.max(haut, b.bottom);
      });

    return haut;
  }

  function suivreEnteteCollant(r) {
    var dernier = -1;

    function mesurer() {
      var h = Math.round(hauteurCollante());

      if (h === dernier) {
        return;
      }

      dernier = h;
      r.style.setProperty('--eko-collant', (h + 16) + 'px');
    }

    mesurer();
    // L'en-tête n'est souvent collant qu'une fois la page défilée, et il change
    // de hauteur en se réduisant : on remesure au défilement, mais seulement
    // quand la valeur bouge réellement.
    window.addEventListener('scroll', mesurer, { passive: true });
    window.addEventListener('resize', mesurer);
    setTimeout(mesurer, 600);
  }

  function demarrer() {
    var r = racine();

    if (!r || r.dataset.ekoPret === '1') {
      return;
    }

    r.dataset.ekoPret = '1';

    // La pleine largeur est posée ICI et pas dans le gabarit : la classe vit
    // sur `<body>`, hors de portée d'un hook produit. Et elle ne se pose que
    // sur une fiche pilotée par le module — les autres gardent la mise en page
    // du thème, intacte.
    document.body.classList.add('eko-poc-page');

    var zoneEtapes = r.querySelector('.eko-poc__etapes');
    var zoneResume = r.querySelector('.eko-poc__resume');

    // Déplacer AVANT de masquer : une fois le configurateur sorti du bloc prix,
    // ce bloc n'a plus rien à montrer que le prix catalogue — nul sur ces
    // fiches — et `masquerNatif()` peut le masquer en entier.
    var section = deplacerSousLaFiche(r);

    masquerNatif();
    ancreVersConfigurateur(r, section);
    suivreEnteteCollant(r);
    commande(false, txt(r, 'attendez', ''));

    /** Les choix faits, dans l'ordre des étapes. */
    var selection = [];
    /** Les libellés des étapes, tels que l'ERP les nomme. */
    var etapes = [];
    /** Le dictionnaire des options : code -> nom, dessin, dimensions. */
    var libelles = {};
    /** Les prestations de l'imprimeur, et le choix courant. */
    var services = [];
    var choixServices = {};
    /** Les rangs de l'arbre : leurs options, dans l'ordre d'affichage. */
    var rangs = [];
    /**
     * ─── DEUX MODES, UN SEUL CONFIGURATEUR ──────────────────────────────
     *
     * `arbre` — le premier sous-traitant. Chaque choix restreint le suivant,
     *   la combinaison est un CHEMIN, et changer une option en haut de la
     *   liste invalide tout ce qui est en dessous.
     *
     * `formulaire` — le second. Une vingtaine de champs largement
     *   INDÉPENDANTS, que le client remplit dans l'ordre qu'il veut. Rien à
     *   redescendre : changer un champ ne change que le prix.
     *
     * Tout le reste est commun — les quantités, les délais, les prestations,
     * le récapitulatif, l'ajout au panier, le prix natif masqué. Un second
     * fichier aurait divergé du premier à la toute première retouche, et le
     * visiteur aurait vu deux boutiques. C'est déjà la règle qui gouverne la
     * feuille de style des deux configurateurs.
     */
    var MODE = (r.dataset.mode === 'formulaire') ? 'formulaire' : 'arbre';
    /** Formulaire : la description de chaque champ, telle que l'ERP la donne. */
    var champs = [];
    /** Formulaire : la valeur courante de chaque champ. */
    var config = {};
    /** Formulaire : le champ qui porte la matière, s'il y en a un. */
    var cleSupport = '';
    /** Le nom du produit, tel que l'ERP le donne. */
    var nomProduit = '';
    /** Les ventes phares réglées au back-office : nom + format visé. */
    var ventes = [];
    /**
     * Le numéro de la demande en cours.
     *
     * Deux réponses peuvent revenir dans le désordre — un réseau lent sur la
     * première, rapide sur la seconde. Sans ce jeton, la plus ancienne écrase
     * la plus récente et le visiteur se retrouve avec le prix d'une
     * configuration qu'il vient de quitter.
     */
    var generation = 0;
    /** Les réassurances affichées sous le bouton de commande. */
    var reassurances = [];
    /** La grille de la configuration complète, une fois obtenue. */
    var grille = null;
    /**
     * Le minuteur qui relit un calcul en cours.
     *
     * ⚠️ IL SE COUPE À CHAQUE NOUVELLE DEMANDE. Sans ça, un visiteur qui
     * change de cote pendant un calcul en laisse deux tourner : le premier
     * finit après le second et écrase l'écran avec le prix d'une dimension
     * qu'il vient de quitter.
     */
    var sonde = null;
    var quantiteChoisie = null;
    var delaiChoisi = null;
    var enCours = null;

    /** Le mot que le récapitulatif dit quand l'ajout au panier échoue. */
    var messagePanier = document.createElement('p');
    messagePanier.className = 'eko-poc__panier-refus';
    messagePanier.hidden = true;

    /**
     * Ajoute la configuration au panier.
     *
     * ─── POURQUOI UN ALLER-RETOUR AVANT DE DÉCLENCHER ────────────────────
     *
     * Le prix affiché vient de l'ERP ; PrestaShop, lui, ne connaît que le prix
     * catalogue de la fiche. Tant que la boutique n'a pas RETENU la
     * configuration et enregistré son prix, ajouter au panier ajoute au prix
     * catalogue. Mesuré : le configurateur affichait 68,04 €, la ligne au
     * panier valait 0,00 €.
     *
     * On demande donc au serveur de retenir la configuration — il rappelle
     * l'ERP, vérifie que la case choisie existe encore, et rend l'identifiant
     * de configuration. C'est seulement ensuite qu'on déclenche le formulaire.
     *
     * Le montant ne voyage jamais depuis le navigateur : un prix venu du client
     * serait un prix que la boutique n'a pas vérifié.
     */
    function ajouterAuPanier(bouton) {
      var f = formulairePanier();

      if (!f || quantiteChoisie === null || delaiChoisi === null) {
        return;
      }

      bouton.disabled = true;
      messagePanier.hidden = true;

      appeler({ quoi: 'retenir', quantite: quantiteChoisie, delai: delaiChoisi }, true)
        .then(function (d) {
          if (!d || d.ok !== true || !d.id_customization) {
            throw new Error((d && d.message) || txt(r, 'echecPanier', ''));
          }

          var champ = f.querySelector('input[name="id_customization"]');

          if (champ) {
            champ.value = d.id_customization;
          }

          // UN lot, pas N exemplaires : le nombre d'exemplaires est porté par
          // la configuration, et le sous-traitant tarife le lot entier.
          var qte = f.querySelector('#quantity_wanted, input[name="qty"]');

          if (qte) {
            qte.value = d.quantite_panier || 1;
          }

          var natif = f.querySelector('[data-button-action="add-to-cart"]');

          if (!natif) {
            throw new Error(txt(r, 'echecPanier', ''));
          }

          // Le bouton du thème est masqué mais bien présent : le déclencher
          // laisse PrestaShop faire son propre travail — jeton, contrôle de
          // stock, mise à jour du panier volant.
          natif.disabled = false;
          natif.click();
        })
        .catch(function (e) {
          messagePanier.textContent = e.message || txt(r, 'echecPanier', '');
          messagePanier.hidden = false;
        })
        .then(function () {
          bouton.disabled = false;
        });
    }

    /**
     * Un appel au relais.
     *
     * `seul` détache l'appel du contrôleur partagé. Les appels de navigation
     * s'annulent l'un l'autre — c'est voulu, une réponse périmée ne doit pas
     * écraser la suivante. L'ajout au panier, lui, ne doit JAMAIS être annulé
     * par un rendu qui passe : le visiteur a cliqué.
     */
    function appeler(params, seul) {
      var signal;

      if (seul) {
        signal = undefined;
      } else {
        if (enCours) {
          enCours.abort();
        }

        enCours = new AbortController();
        signal = enCours.signal;
      }

      var p = new URLSearchParams();
      p.set('ajax', '1');
      p.set('id_product', r.dataset.idProduct);
      Object.keys(params).forEach(function (k) { p.set(k, params[k]); });
      if (MODE === 'formulaire') {
        // Une table de valeurs, pas un chemin. Les champs vides ne partent
        // pas : côté serveur, une clé sans valeur ne fait pas partie de
        // l'empreinte, et l'y mettre changerait la ligne cherchée.
        Object.keys(config).forEach(function (k) {
          if (config[k] !== '' && config[k] !== null && config[k] !== undefined) {
            p.set('config[' + k + ']', config[k]);
          }
        });
      } else {
        selection.forEach(function (v) { p.append('selection[]', v); });
      }
      Object.keys(choixServices).forEach(function (k) {
        p.set('services[' + k + ']', choixServices[k]);
      });

      return fetch(r.dataset.url + '&' + p.toString(), {
        signal: signal,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (x) { return x.json(); });
    }

    // ─── Le rendu ──────────────────────────────────────────────────────────

    /**
     * Une ligne de cartes.
     *
     * Deux façons de contenir une ligne trop longue, et elles ne servent pas
     * la même chose :
     *
     *   `defiler` — la ligne devient un ruban à flèches, avec « Tout voir ».
     *     Pour les FORMATS : leurs vignettes doivent rester assez grandes pour
     *     se comparer, donc on ne peut pas en réduire la taille.
     *
     *   `replier` — les premières cartes seulement, le reste derrière « Voir + ».
     *     Pour les QUANTITÉS : elles se lisent en colonne de chiffres, et les
     *     grosses quantités n'intéressent qu'une minorité de visiteurs.
     *
     * Vingt-trois quantités et quatorze formats posés d'un coup repoussaient le
     * deuxième critère sous la ligne de flottaison.
     */
    function ligne(titre, cartes, actifIndex, surChoix, options) {
      var defiler = (options || {}).defiler;
      var replier = (options || {}).replier;
      var legendeDessous = (options || {}).legendeDessous;
      var l = document.createElement('div');
      l.className = 'eko-poc__ligne';

      var tete = document.createElement('div');
      tete.className = 'eko-poc__ligne-tete';

      var t = document.createElement('h3');
      t.className = 'eko-poc__critere';
      t.textContent = titre;
      tete.appendChild(t);
      l.appendChild(tete);

      var piste = document.createElement('div');
      piste.className = 'eko-poc__cartes';

      cartes.forEach(function (c, i) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'eko-poc__carte' + (i === actifIndex ? ' eko-poc__carte--actif' : '');
        b.setAttribute('aria-pressed', i === actifIndex ? 'true' : 'false');

        // Les vignettes de format portent leur légende SOUS le cadre, et non
        // dedans : le cadre encadre alors le dessin seul, ce qui laisse les
        // dessins s'aligner entre eux quelle que soit la longueur des noms.
        // « Carré 14,8 x 14,8 » sur deux lignes déformait sa carte et
        // désalignait toute la rangée.
        if (legendeDessous) {
          b.innerHTML = c.svg || '';
          b.setAttribute('aria-label', c.nom + (c.note ? ', ' + c.note : ''));
          b.addEventListener('click', function () { surChoix(i, c); });

          var enveloppe = document.createElement('div');
          enveloppe.className = 'eko-poc__vignette'
            + (i === actifIndex ? ' eko-poc__vignette--actif' : '');
          enveloppe.appendChild(b);

          var leg = document.createElement('span');
          leg.className = 'eko-poc__vignette-legende';
          leg.innerHTML = '<strong>' + echapper(c.nom) + '</strong>'
            + (c.note ? ' — ' + echapper(c.note) : '');
          enveloppe.appendChild(leg);

          piste.appendChild(enveloppe);

          return;
        }

        var html = (c.svg || '') + '<span class="eko-poc__carte-nom">' + echapper(c.nom) + '</span>';

        if (c.prix) {
          html += '<span class="eko-poc__carte-prix">' + echapper(c.prix) + '</span>';
        }

        if (c.date) {
          html += '<span class="eko-poc__carte-date">' + echapper(txt(r, 'livree', 'Livraison estimée'))
            + '<strong>' + echapper(c.date) + '</strong></span>';
        }

        if (c.note) {
          html += '<span class="eko-poc__carte-note">' + echapper(c.note) + '</span>';
        }

        if (c.badge) {
          html += '<span class="eko-poc__badge">' + echapper(c.badge) + '</span>';
        }

        b.innerHTML = html;
        b.addEventListener('click', function () { surChoix(i, c); });
        piste.appendChild(b);
      });

      if (defiler && cartes.length > defiler) {
        l.appendChild(ruban(piste, tete, actifIndex));

        return l;
      }

      l.appendChild(piste);

      if (replier && cartes.length > replier) {
        replierApres(piste, replier, actifIndex);
      }

      return l;
    }

    /**
     * Ne montre que les premières cartes, le reste derrière un « Voir + ».
     *
     * Le bouton dit COMBIEN il en reste. « Voir + » seul ne dit pas si l'on
     * déplie deux cartes ou quinze, et un visiteur qui cherche 5 000
     * exemplaires doit savoir qu'ils existent avant de quitter la page.
     *
     * Si le choix courant est parmi les cartes cachées — ce qui arrive après un
     * changement en amont qui réduit la grille — on déplie d'emblée : cacher
     * ce qui est sélectionné donnerait un récapitulatif sans origine visible.
     */
    function replierApres(piste, combien, actifIndex) {
      if (actifIndex >= combien) {
        return;
      }

      var caches = [].slice.call(piste.children, combien);

      caches.forEach(function (c) { c.hidden = true; });

      var plus = document.createElement('button');
      plus.type = 'button';
      plus.className = 'eko-poc__voir-plus';
      plus.textContent = txt(r, 'voirPlus', 'Voir plus') + ' (' + caches.length + ')';
      plus.addEventListener('click', function () {
        caches.forEach(function (c) { c.hidden = false; });
        plus.remove();
      });

      piste.appendChild(plus);
    }

    /**
     * Enferme une piste de cartes dans un ruban défilant.
     *
     * ─── POURQUOI LE DÉFILEMENT NATIF, ET PAS UN `transform` ───────────────
     *
     * Un carousel bâti sur `transform` doit réimplémenter ce que le navigateur
     * sait déjà faire : le glissement au doigt, la molette horizontale du
     * pavé tactile, le défilement au clavier, et l'amenée dans le champ de
     * vision d'un élément qui reçoit le focus. Un conteneur `overflow-x` donne
     * tout cela gratuitement, et les flèches ne sont plus qu'un `scrollBy`.
     *
     * C'est aussi ce qui rend le ruban utilisable sans JavaScript actif sur
     * les cartes : si le script échoue après le rendu, on peut encore défiler.
     */
    function ruban(piste, tete, actifIndex) {
      var cadre = document.createElement('div');
      cadre.className = 'eko-poc__ruban';

      var vue = document.createElement('div');
      vue.className = 'eko-poc__vue';
      vue.appendChild(piste);

      var avant = fleche('avant', '‹');
      var apres = fleche('apres', '›');

      cadre.appendChild(avant);
      cadre.appendChild(vue);
      cadre.appendChild(apres);

      function pas(sens) {
        // D'une vue entière moins une carte : le repère visuel de la carte
        // partiellement vue survit au défilement, sinon on ne sait plus où
        // l'on en est.
        var carte = piste.firstElementChild;
        var large = carte ? carte.getBoundingClientRect().width + 10 : 160;

        vue.scrollBy({ left: sens * Math.max(large, vue.clientWidth - large), behavior: 'smooth' });
      }

      avant.addEventListener('click', function () { pas(-1); });
      apres.addEventListener('click', function () { pas(1); });

      // Une flèche qui ne mène nulle part se désactive plutôt que de rester
      // cliquable sans effet — c'est ce que fait la référence.
      function butees() {
        var reste = vue.scrollWidth - vue.clientWidth - vue.scrollLeft;

        avant.disabled = vue.scrollLeft <= 1;
        apres.disabled = reste <= 1;
      }

      vue.addEventListener('scroll', butees, { passive: true });

      // Les butées se recalculent dès que la vue a une largeur. Un appel unique
      // au montage ne suffit pas : quand le ruban est bâti il n'est pas encore
      // dans le document, `clientWidth` vaut zéro, et les deux flèches restent
      // actives alors qu'on est en butée gauche. Mesuré. L'observateur couvre
      // aussi le redimensionnement et l'arrivée des polices.
      if (typeof ResizeObserver === 'function') {
        new ResizeObserver(butees).observe(vue);
      } else {
        window.addEventListener('resize', butees);
      }

      // Le bouton « Tout voir » déplie le ruban en grille. Une fois déplié, il
      // ne se replie pas : quelqu'un qui a demandé à tout voir ne veut pas
      // que ça se referme sous ses yeux.
      var tout = document.createElement('button');
      tout.type = 'button';
      tout.className = 'eko-poc__tout-voir';
      tout.textContent = txt(r, 'toutVoir', 'Tout voir');
      tout.addEventListener('click', function () {
        cadre.classList.add('eko-poc__ruban--deplie');
        tout.remove();
      });
      tete.appendChild(tout);

      // Le choix courant doit être visible d'emblée : après un changement en
      // amont, le format retenu peut se retrouver au douzième rang.
      //
      // ⚠️ Un minuteur, pas `requestAnimationFrame`. Le ruban n'est pas encore
      // dans le document quand on le bâtit, il faut donc attendre — mais les
      // trames d'animation ET les observateurs de taille sont suspendus dans un
      // onglet masqué, alors que les minuteurs continuent. Mesuré : les deux
      // flèches restaient actives en butée gauche.
      setTimeout(function () {
        var actif = piste.children[actifIndex];

        if (actif) {
          // `scrollIntoView` plutôt qu'un calcul d'offset : `offsetLeft` se
          // mesure depuis le premier ancêtre positionné, qui n'est pas la piste
          // — la carte étant elle-même en `position: relative`.
          actif.scrollIntoView({ block: 'nearest', inline: 'center' });
        }

        butees();
      }, 0);

      return cadre;
    }

    function fleche(sens, glyphe) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'eko-poc__fleche eko-poc__fleche--' + sens;
      b.textContent = glyphe;
      b.setAttribute('aria-label', txt(r, sens === 'avant' ? 'precedent' : 'suivant', ''));

      return b;
    }

    function message(classe, texte) {
      var p = document.createElement('p');
      p.className = 'eko-poc__' + classe;
      p.textContent = texte;

      return p;
    }

    /**
     * Cette étape est-elle un format ?
     *
     * Sur le code de l'étape, pas sur son libellé : le libellé est traduit et
     * change d'une langue à l'autre, le code non.
     */
    /**
     * Le nom d'une option, ou son code faute de mieux.
     *
     * Le dictionnaire de l'ERP est incomplet par construction — il se remplit
     * produit par produit. Retomber sur le code est donc le cas NORMAL, pas une
     * erreur : mieux vaut « NOF » que rien.
     */
    function nomDe(code) {
      var l = libelles[code];

      if (l && l.name) {
        return l.name;
      }

      // ⚠️ Le second sous-traitant ne nomme pas ses délais « J+3 » mais
      // « urgence », « express », « standard ». Affichés bruts, ils sortaient
      // en minuscules et en français au milieu d'une boutique anglaise. Les
      // intitulés vivent donc dans le gabarit, où `trans()` les voit.
      var traduit = txt(r, 'delai' + String(code).charAt(0).toUpperCase() + String(code).slice(1), '');

      return traduit || code;
    }

    /**
     * Les dimensions connues de l'ERP priment sur celles qu'on devine.
     *
     * ⚠️ Elles sont en CENTIMÈTRES chez le fournisseur, là où les codes bruts
     * sont en millimètres. Vérifié sur trois formats normalisés : DL rendu
     * 9,9 × 21 vaut bien 99 × 210 mm, A3 rendu 29,7 × 42 vaut 297 × 420, A4
     * rendu 21 × 29,7 vaut 210 × 297. Les mélanger dessinerait un A4 dix fois
     * plus petit qu'un « 210x297 » sur la même ligne.
     *
     * ⚠️ ET CETTE MULTIPLICATION PAR DIX SE VOIT. Un nombre à décimales n'est
     * pas exact en binaire : 52,13 × 10 ne vaut pas 521,3 mais
     * 521,3000000000001, et le beach flag affichait « 521.3000000000001 ×
     * 1886.3999999999999 mm ». Le défaut ne touchait QUE les cotes venues de
     * l'ERP — celles devinées du code sont des entiers et sortaient propres —
     * ce qui donnait une même ligne où certaines cartes étaient lisibles et
     * d'autres non.
     *
     * L'arrondi est posé ICI et non à l'affichage : ces cotes servent aussi
     * d'échelle aux vignettes et de repère médian. Les arrondir au moment de
     * les écrire aurait laissé le dessin travailler sur d'autres nombres que
     * ceux qu'il annonce — deux vérités pour une seule mesure.
     *
     * Au dixième de millimètre, et non à l'unité : c'est la précision réelle
     * du fournisseur, qui donne ses cotes au centième de centimètre.
     */
    function dimensionsDe(code) {
      var l = libelles[code];

      return (l && l.width && l.height)
        ? { l: Math.round(l.width * 100) / 10, h: Math.round(l.height * 100) / 10 }
        : dimensions(code);
    }

    function estFormat(etape) {
      return /format|dimension|taille/i.test(String(etape.code || ''));
    }

    /** Une ligne de vignettes proportionnelles. */
    function ligneFormats(titre, options, choisi, surChoix) {
      var refMax = options.reduce(function (m, o) {
        var d = dimensionsDe(o);

        return d ? Math.max(m, d.l, d.h) : m;
      }, 0);

      // Le format de référence : le plus courant de la ligne, pris comme repère
      // pour tous les autres. Le MÉDIAN plutôt que le plus grand — un repère qui
      // est aussi le maximum ne donne aucune échelle aux petits formats, qui
      // sont justement ceux qu'on distingue le plus mal.
      var mesures = options.map(dimensionsDe).filter(Boolean)
        .sort(function (a, b) { return (a.l * a.h) - (b.l * b.h); });
      var repere = mesures.length ? mesures[Math.floor(mesures.length / 2)] : null;

      // ⚠️ TOUT OU RIEN. Les dessins du fournisseur sont plafonnés en poids :
      // sur quatorze formats, trois passaient et onze retombaient sur la
      // vignette du module. Trois cartes d'un style et onze d'un autre, sur la
      // même ligne, se lisent comme un affichage cassé — bien plus mal que
      // n'importe lequel des deux styles appliqué partout.
      //
      // On n'emploie donc les dessins de l'ERP que s'il en a un pour CHAQUE
      // option de la ligne.
      var tousDessines = options.every(function (o) {
        return libelles[o] && libelles[o].svg;
      });

      var cartes = options.map(function (o) {
        var d = dimensionsDe(o);
        var nom = nomDe(o);

        return {
          nom: nom,
          note: d ? d.l + ' × ' + d.h + ' mm' : '',
          // Le dessin du fournisseur montre la FORME réelle, ce qu'un rectangle
          // ne peut pas faire pour un découpé. À défaut, la vignette du module :
          // silhouette pleine sur le format de référence, nom écrit dedans.
          svg: tousDessines
            ? '<span class="eko-poc__svg eko-poc__svg--erp">' + libelles[o].svg + '</span>'
            : vignetteFormat(d, refMax, /\brond\b|diam[èe]tre/i.test(nom), nom, repere)
        };
      });

      // Quatre visibles, comme la référence : au-delà, les vignettes deviennent
      // trop petites pour que deux formats se comparent d'un coup d'œil, ce qui
      // est toute leur raison d'être.
      var l = ligne(titre, cartes, options.indexOf(choisi), function (i) {
        surChoix(options[i]);
      }, { defiler: 4, legendeDessous: true });

      l.classList.add('eko-poc__ligne--formats');

      return l;
    }

    /**
     * Une ligne à liste déroulante.
     *
     * Repliée sur un seul choix, la liste devient un simple libellé cliquable :
     * un `<select>` à une entrée invite à l'ouvrir pour rien.
     */
    function ligneListe(titre, options, choisi, surChoix) {
      var l = document.createElement('div');
      l.className = 'eko-poc__ligne eko-poc__ligne--liste';

      var t = document.createElement('h3');
      t.className = 'eko-poc__critere';
      t.textContent = titre;
      l.appendChild(t);

      var sel = document.createElement('select');
      sel.className = 'form-control eko-poc__liste';

      options.forEach(function (o) {
        var op = document.createElement('option');
        op.value = o;
        op.textContent = nomDe(o);
        op.selected = (o === choisi);
        sel.appendChild(op);
      });

      sel.addEventListener('change', function () {
        surChoix(sel.value);
      });

      l.appendChild(sel);

      return l;
    }

    // ─── L'arbre, descendu d'un coup ───────────────────────────────────────

    /**
     * Descend l'arbre depuis un rang, en prenant la première option à chaque
     * étape, et note les choix disponibles à chacune.
     *
     * Un seul aller-retour par étape, et il n'y en a jamais plus de sept. Les
     * réponses sont mises en cache par le module : rejouer une descente après
     * un changement ne recoûte que les étapes réellement invalidées.
     */
    /**
     * Le formulaire du second sous-traitant : UN aller-retour, et c'est tout.
     *
     * Il n'y a rien à redescendre — les champs ne se restreignent pas les uns
     * les autres. On le demande une fois à l'ouverture de la fiche ; ensuite,
     * changer un champ ne recharge que la grille.
     *
     * Les valeurs par défaut sont posées ici, y compris celles des champs que
     * l'ERP IMPOSE et qui ne s'affichent pas : elles font partie de la
     * configuration que le fournisseur a chiffrée, et les omettre changerait
     * le prix sans le dire.
     */
    function chargerFormulaire() {
      var mien = ++generation;

      return appeler({ quoi: 'formulaire' }).then(function (d) {
        if (mien !== generation) {
          return true;
        }

        if (!d || d.ok !== true) {
          throw new Error((d && d.message) || txt(r, 'echec', ''));
        }

        champs = d.champs || [];
        cleSupport = d.cle_support || '';

        Object.keys(d.imposees || {}).forEach(function (k) { config[k] = d.imposees[k]; });

        champs.forEach(function (ch) {
          if (ch.type === 'format') {
            // ⚠️ LE FORMAT POSE DEUX CLÉS, pas une. Sans ce premier choix, la
            // fiche s'ouvrait sans hauteur ni largeur, demandait quand même un
            // tarif, et accueillait le visiteur par un refus rouge sur une
            // page qu'il venait d'ouvrir.
            poserFormat(ch, premierFormat(ch));

            return;
          }

          if (config[ch.id] !== undefined) {
            return;
          }

          if (ch.defaut !== '') {
            config[ch.id] = ch.defaut;
          } else if (ch.type === 'liste' && ch.valeurs.length) {
            // La première valeur plutôt qu'un « au choix » vide : une
            // configuration incomplète ne se tarife pas, et une fiche qui
            // s'ouvre sans prix n'apprend rien au visiteur.
            config[ch.id] = ch.valeurs[0].id;
          }
        });

        if (d.ventes && ventes.length === 0) {
          ventes = d.ventes;
        }

        if (d.reassurances && reassurances.length === 0) {
          reassurances = d.reassurances;
        }

        if (d.services && services.length === 0) {
          services = d.services;
          services.forEach(function (sv) {
            if (choixServices[sv.cle] === undefined && sv.options.length) {
              choixServices[sv.cle] = sv.options[0].nom;
            }
          });
        }

        return true;
      });
    }

    function descendre(depuis) {
      if (MODE === 'formulaire') {
        return chargerFormulaire();
      }

      selection = selection.slice(0, depuis);
      rangs = rangs.slice(0, depuis);

      var mien = ++generation;

      return appeler({ quoi: 'arbre' }).then(function (d) {
        // Une descente dépassée n'écrit rien : le visiteur a déjà changé
        // d'avis, et ses options ne sont plus celles-là.
        if (mien !== generation) {
          return true;
        }

        if (!d || d.ok !== true) {
          throw new Error((d && d.message) || txt(r, 'echec', ''));
        }

        etapes = d.steps || [];
        nomProduit = d.name || nomProduit;
        Object.keys(d.labels || {}).forEach(function (k) { libelles[k] = d.labels[k]; });

        if (d.ventes && ventes.length === 0) {
          ventes = d.ventes;
        }

        if (d.reassurances && reassurances.length === 0) {
          reassurances = d.reassurances;
        }

        if (d.services && services.length === 0) {
          services = d.services;
          services.forEach(function (sv) {
            if (choixServices[sv.cle] === undefined && sv.options.length) {
              choixServices[sv.cle] = sv.options[0].nom;
            }
          });
        }

        if (d.complete || !d.options.length) {
          return true;
        }

        // Les choix de CE rang, et le premier retenu par défaut. Le premier
        // plutôt qu'un « au choix » vide : une configuration incomplète ne se
        // tarifie pas, et une page qui s'ouvre sans prix n'apprend rien.
        rangs.push({ rang: d.rank || 0, options: d.options });
        selection.push(d.options[0]);

        return descendre(selection.length);
      });
    }

    /**
     * Les onglets de vente phare, au-dessus des critères.
     *
     * Un raccourci vers une configuration courante : « Top vente A5 » pose le
     * format A5 et laisse le reste aux valeurs par défaut. Le premier onglet
     * ramène à la configuration libre.
     *
     * ─── CE QU'ILS DÉSIGNENT ────────────────────────────────────────────────
     *
     * Le marchand écrit un nom de format tel qu'il apparaît sur le site, ou le
     * code du fournisseur ; on accepte les deux, parce qu'un marchand voit les
     * noms et jamais les codes.
     *
     * Un onglet dont la cible n'existe pas dans l'arbre n'est PAS affiché. Une
     * faute de frappe donnerait sinon un onglet qui ne fait rien — et rien
     * n'est plus difficile à diagnostiquer qu'un bouton silencieux.
     */
    function rendreOnglets() {
      var ancien = zoneEtapes.querySelector('.eko-poc__onglets');

      if (ancien) {
        ancien.remove();
      }

      if (!ventes.length || !rangs.length) {
        return;
      }

      var premier = rangs[0].options;

      function cibleDe(v) {
        var voulu = String(v.cible).toLowerCase();

        for (var i = 0; i < premier.length; i++) {
          var code = premier[i];

          if (String(code).toLowerCase() === voulu
            || String(nomDe(code)).toLowerCase() === voulu) {
            return code;
          }
        }

        return null;
      }

      var utiles = ventes
        .map(function (v) { return { nom: v.nom, code: cibleDe(v) }; })
        .filter(function (v) { return v.code !== null; });

      if (!utiles.length) {
        return;
      }

      var barre = document.createElement('div');
      barre.className = 'eko-poc__onglets';
      barre.setAttribute('role', 'tablist');

      var surMesure = selection.length
        && !utiles.some(function (v) { return v.code === selection[0]; });

      function onglet(nom, code, actif) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'eko-poc__onglet' + (actif ? ' eko-poc__onglet--actif' : '');
        b.setAttribute('role', 'tab');
        b.setAttribute('aria-selected', actif ? 'true' : 'false');
        b.textContent = nom;

        if (!actif) {
          b.addEventListener('click', function () {
            if (code === null) {
              return;
            }

            // Exactement le geste d'un clic sur la carte de format : on pose le
            // choix du premier rang et on redescend. Rien de particulier à
            // maintenir en plus.
            selection[0] = code;
            recharger(1);
          });
        }

        return b;
      }

      barre.appendChild(onglet(txt(r, 'surMesure', 'Configuration personnalisée'), null, surMesure));

      utiles.forEach(function (v) {
        barre.appendChild(onglet(v.nom, v.code, v.code === selection[0]));
      });

      zoneEtapes.insertBefore(barre, zoneEtapes.firstChild);
    }

    /** Redessine TOUTES les lignes de l'arbre, dans l'ordre des étapes. */
    /**
     * Une ligne de choix, à partir de valeurs qui portent DÉJÀ leur libellé.
     *
     * ⚠️ Pas `ligneListe()` : celle-ci prend des CODES et va chercher leur nom
     * dans le dictionnaire commun, indexé par code seul. Ici deux champs
     * différents ont légitimement une valeur « 1 » qui ne veut pas dire la même
     * chose — l'un une matière, l'autre un nombre d'œillets. Un dictionnaire
     * global ferait afficher le libellé du voisin.
     */
    function ligneChoix(ch) {
      var l = document.createElement('div');
      l.className = 'eko-poc__ligne eko-poc__ligne--liste';

      var t = document.createElement('h3');
      t.className = 'eko-poc__critere';
      t.textContent = ch.nom + ' :';
      l.appendChild(t);

      var sel = document.createElement('select');
      sel.className = 'form-control eko-poc__liste';
      // Verrouillé, mais LISIBLE : le client doit voir la matière qu'il
      // achète, même quand c'est le titre de la page qui l'a choisie pour lui.
      sel.disabled = (ch.verrouille === true);

      ch.valeurs.forEach(function (o) {
        var op = document.createElement('option');
        op.value = o.id;
        op.textContent = o.label;
        op.selected = (o.id === config[ch.id]);
        sel.appendChild(op);
      });

      sel.addEventListener('change', function () { majChamp(ch.id, sel.value); });
      l.appendChild(sel);

      return l;
    }

    /**
     * Les formats disponibles POUR LA MATIÈRE COURANTE.
     *
     * Deux matières n'ont pas forcément été construites sur les mêmes cotes :
     * proposer la liste entière ferait choisir un format sans prix.
     */
    function formatsDisponibles(ch) {
      var matiere = cleSupport ? config[cleSupport] : '';

      return (ch.valeurs || []).filter(function (f) {
        return !matiere || !f.support || f.support === matiere;
      });
    }

    function premierFormat(ch) {
      var liste = formatsDisponibles(ch);

      return liste.length ? liste[0] : null;
    }

    /** Pose les DEUX cotes d'un format, ou les retire s'il n'y en a pas. */
    function poserFormat(ch, format) {
      if (!format) {
        delete config[ch.cle_hauteur];
        delete config[ch.cle_largeur];

        return;
      }

      config[ch.cle_hauteur] = format.hauteur;
      config[ch.cle_largeur] = format.largeur;
    }

    /** Le format actuellement retenu, d'après les deux cotes posées. */
    function formatCourant(ch) {
      var h = config[ch.cle_hauteur];
      var l = config[ch.cle_largeur];

      return (ch.valeurs || []).filter(function (f) {
        return f.hauteur === h && f.largeur === l;
      })[0] || null;
    }

    function ligneFormat(ch) {
      var l = document.createElement('div');
      l.className = 'eko-poc__ligne eko-poc__ligne--liste';

      var t = document.createElement('h3');
      t.className = 'eko-poc__critere';
      t.textContent = ch.nom + ' :';
      l.appendChild(t);

      var sel = document.createElement('select');
      sel.className = 'form-control eko-poc__liste';

      var courant = formatCourant(ch);

      if (!courant) {
        // Le visiteur a tapé sa propre dimension : le raccourci ne désigne
        // plus rien, et le laisser afficher un format au hasard ferait croire
        // qu'il commande celui-là.
        var vide = document.createElement('option');
        vide.value = '';
        vide.textContent = txt(r, 'autreDimension', '');
        vide.selected = true;
        sel.appendChild(vide);
      }

      formatsDisponibles(ch).forEach(function (f) {
        var op = document.createElement('option');
        op.value = f.id;
        op.textContent = f.label;
        op.selected = Boolean(courant) && f.id === courant.id;
        sel.appendChild(op);
      });

      sel.addEventListener('change', function () {
        var choisi = formatsDisponibles(ch).filter(function (f) { return f.id === sel.value; })[0];

        if (!choisi || (courant && choisi.id === courant.id)) {
          return;
        }

        poserFormat(ch, choisi);

        // ⚠️ REDESSINER, et pas seulement recalculer. Ce raccourci pose DEUX
        // champs de cote, qui sont libres : sans ce rendu, ils garderaient à
        // l'écran les chiffres d'avant pendant que le prix, lui, changerait.
        // Deux vérités pour une seule commande.
        rendreChamps();
        invaliderGrille();
        chargerGrille();
      });

      l.appendChild(sel);

      return l;
    }

    /** Une saisie libre : une cote, un nombre, un texte. */
    function ligneSaisie(ch) {
      var l = document.createElement('div');
      l.className = 'eko-poc__ligne eko-poc__ligne--liste';

      var t = document.createElement('h3');
      t.className = 'eko-poc__critere';
      t.textContent = ch.nom + ' :';
      l.appendChild(t);

      if (ch.type === 'case') {
        var c = document.createElement('input');
        c.type = 'checkbox';
        c.className = 'eko-poc__case';
        c.checked = (String(config[ch.id]) === '1');
        c.disabled = (ch.verrouille === true);
        c.addEventListener('change', function () { majChamp(ch.id, c.checked ? '1' : '0'); });
        l.appendChild(c);

        return l;
      }

      var i = document.createElement('input');
      i.type = (ch.type === 'nombre' || ch.type === 'cote') ? 'number' : 'text';
      i.className = 'form-control eko-poc__liste';
      i.value = config[ch.id] || '';
      i.disabled = (ch.verrouille === true);

      if (ch.type === 'nombre' || ch.type === 'cote') {
        i.min = '1';
        // ⚠️ `any` et non `1` : les cotes du fournisseur sont en centimètres
        // AVEC décimales — un A4 fait 21 × 29,7. Un pas entier ferait rejeter
        // la saisie par le navigateur, sans message que le visiteur comprenne.
        i.step = 'any';
      }

      // ⚠️ `change` et NON `input` : le second part à chaque touche. Saisir
      // « 120 » demanderait trois tarifications au serveur, dont deux pour des
      // cotes que le visiteur n'a jamais voulues.
      i.addEventListener('change', function () { majChamp(ch.id, i.value.trim()); });
      l.appendChild(i);

      return l;
    }

    /**
     * Change un champ, et ne recharge QUE la grille.
     *
     * Les champs sont indépendants : rien à redescendre, rien à réafficher.
     * Reconstruire le formulaire ferait perdre le curseur du visiteur au
     * milieu d'une saisie de cote.
     */
    function majChamp(id, valeur) {
      if (config[id] === valeur) {
        return;
      }

      config[id] = valeur;

      // ⚠️ CHANGER DE MATIÈRE CHANGE LA LISTE DES FORMATS. Deux matières
      // n'ont pas forcément été construites sur les mêmes cotes : garder
      // l'ancien format demanderait un tarif qui n'existe pas, sur une
      // sélection que le visiteur croirait valide.
      if (cleSupport && id === cleSupport) {
        champs.forEach(function (ch) {
          if (ch.type !== 'format') {
            return;
          }

          if (!formatCourant(ch)) {
            poserFormat(ch, premierFormat(ch));
          }
        });

        rendreChamps();
      }

      // Les lignes qui dépendent de la grille sont CADUQUES le temps du
      // recalcul. Sans ce retrait, elles restaient affichées et cliquables :
      // un clic sur une quantité pendant le recalcul tombait sur une grille
      // remise à zéro, et le configurateur s'arrêtait net.
      invaliderGrille();
      chargerGrille();
    }

    /** Retire tout ce qui n'a plus de sens tant que la grille n'est pas revenue. */
    function invaliderGrille() {
      quantiteChoisie = null;
      delaiChoisi = null;
      retirerLigne('quantite');
      retirerLigne('delai');
      services.forEach(function (sv) { retirerLigne('svc-' + sv.cle); });
    }

    function rendreChamps() {
      zoneEtapes.querySelectorAll('.eko-poc__ligne--champ').forEach(function (l) { l.remove(); });

      champs.forEach(function (ch, i) {
        var l;

        if (ch.type === 'format') {
          l = ligneFormat(ch);
        } else if (ch.type === 'liste' && ch.valeurs.length) {
          l = ligneChoix(ch);
        } else {
          l = ligneSaisie(ch);
        }

        l.classList.add('eko-poc__ligne--champ');
        l.dataset.rang = String(i);
        zoneEtapes.insertBefore(l, zoneEtapes.children[i] || null);
      });
    }

    function rendreEtapes() {
      if (MODE === 'formulaire') {
        rendreChamps();

        return;
      }

      zoneEtapes.querySelectorAll('.eko-poc__ligne--arbre').forEach(function (l) { l.remove(); });

      rangs.forEach(function (info, i) {
        var etape = etapes[info.rang] || {};
        var titre = (etape.label || '') + ' :';
        var choisi = selection[info.rang];

        function surChoix(valeur) {
          if (valeur === selection[info.rang]) {
            return;
          }

          selection[info.rang] = valeur;
          recharger(info.rang + 1);
        }

        var l = estFormat(etape)
          ? ligneFormats(titre, info.options, choisi, surChoix)
          : ligneListe(titre, info.options, choisi, surChoix);

        l.classList.add('eko-poc__ligne--arbre');
        l.dataset.rang = String(i);
        zoneEtapes.insertBefore(l, zoneEtapes.children[i] || null);
      });

      // APRÈS les lignes : elles s'insèrent par index, et une barre déjà en
      // tête décalerait chacune d'un cran.
      rendreOnglets();
    }

    /**
     * Recharge depuis un rang : l'arbre, puis la grille.
     *
     * Tout ce qui suit le rang touché est caduc — les options suivantes
     * n'existent peut-être pas sous le nouveau choix, et la grille encore
     * moins. On redescend plutôt que de garder une sélection qui a l'air
     * valide et ne l'est plus.
     */
    function recharger(depuis) {
      // Les lignes qui dépendent de la grille disparaissent le temps du
      // rechargement ; l'invalidation du PRIX, elle, vit dans `chargerGrille`,
      // point unique que tous les chemins traversent.
      grille = null;
      commande(false, txt(r, 'attendez', ''));
      retirerLigne('quantite');
      retirerLigne('delai');
      services.forEach(function (sv) { retirerLigne('svc-' + sv.cle); });
      rendreResume();

      var attente = message('attente', txt(r, 'attente', 'Chargement…'));
      zoneEtapes.appendChild(attente);

      descendre(depuis)
        .then(function () {
          attente.remove();
          rendreEtapes();

          return chargerGrille();
        })
        .catch(function (e) {
          if (e.name === 'AbortError') {
            return;
          }

          attente.remove();
          zoneEtapes.appendChild(message('erreur', e.message || txt(r, 'echec', '')));
        });
    }

    // ─── La grille ─────────────────────────────────────────────────────────

    /**
     * La grille n'est pas venue : on retire ce qui en dépendait, et on le dit
     * LÀ OÙ ÉTAIT LE PRIX.
     *
     * Écrite en fin de zone, sous vingt-trois cartes de quantité, l'erreur se
     * retrouvait hors de l'écran : le visiteur voyait un configurateur qui ne
     * répondait plus, sans savoir pourquoi.
     */
    function echecDeGrille(motif) {
      grille = null;
      quantiteChoisie = null;
      delaiChoisi = null;

      retirerLigne('quantite');
      retirerLigne('delai');
      services.forEach(function (sv) { retirerLigne('svc-' + sv.cle); });

      commande(false, motif);
      rendreResume();
      zoneResume.appendChild(message('erreur', motif));
    }

    /**
     * Demande la grille, et INVALIDE tout ce qui en dépend en attendant.
     *
     * ⚠️ L'invalidation est ICI, en entrée, et non chez l'appelant. C'est le
     * point unique que tous les chemins traversent — changement d'option,
     * changement de prestation, premier chargement. Posée chez l'appelant, elle
     * manquait à celui qui vient des prestations : changer un bon à tirer
     * laissait l'ancien prix affiché ET le bouton armé pendant le recalcul. Le
     * visiteur pouvait commander un prix que le serveur allait démentir.
     */
    function chargerGrille() {
      grille = null;
      commande(false, txt(r, 'attendez', ''));
      rendreResume();
      arreterSonde();

      var attente = message('attente', txt(r, 'calcul', 'Calcul…'));
      zoneEtapes.appendChild(attente);

      var mien = ++generation;

      return appeler({ quoi: 'grille' })
        .then(function (d) {
          // ⚠️ LE RETRAIT VIENT AVANT LE TEST DU JETON. Placé après, il ne
          // s'exécutait jamais pour une réponse dépassée : chaque changement
          // rapide laissait un « Calcul des tarifs… » définitif sous les
          // champs, et il s'en empilait un par frappe.
          attente.remove();

          // Le jeton se teste APRÈS la réponse, jamais avant l'appel :
          // `abort()` sur une requête déjà résolue ne fait rien, et une réponse
          // arrivée entre-temps écraserait une sélection plus récente.
          if (mien !== generation) {
            return;
          }

          if (!d || d.ok !== true) {
            echecDeGrille((d && d.message) || txt(r, 'echec', ''));

            return;
          }

          // ⚠️ LE TARIF N'EXISTE PAS ENCORE : IL SE CALCULE.
          //
          // Ces produits se vendent à la dimension. La cote que le visiteur
          // vient de taper n'a le plus souvent jamais été mesurée, et le prix
          // ne se déduit pas — la loi n'est pas monotone en surface. Le
          // serveur la fait donc calculer chez le fournisseur, et rend un
          // jeton : on relit jusqu'à ce que ça aboutisse.
          if (d.attente === true && d.ticket) {
            attendreLeCalcul(d, mien);

            return;
          }

          poserGrille(d);
        })
        .catch(function (e) {
          attente.remove();

          if (e.name === 'AbortError' || mien !== generation) {
            return;
          }

          echecDeGrille(txt(r, 'echec', ''));
        });
    }

    /**
     * Relit un calcul en cours jusqu'à ce qu'il aboutisse.
     *
     * ⚠️ LE JETON DE GÉNÉRATION GOUVERNE TOUT. Chaque relecture le vérifie :
     * un visiteur qui change de cote pendant l'attente ne doit pas voir
     * arriver, vingt secondes plus tard, le prix de la dimension qu'il vient
     * de quitter. C'est le même garde que pour les réponses hors délai, et il
     * compte double ici — l'attente dure assez longtemps pour qu'on change
     * d'avis.
     */
    function attendreLeCalcul(depart, mien) {
      var ecoule = 0;
      var annonce = message('attente', texteAttente(depart.secondes || 25, 0));
      zoneEtapes.appendChild(annonce);

      function relire() {
        if (mien !== generation) {
          annonce.remove();

          return;
        }

        appeler({ quoi: 'suivi', ticket: depart.ticket }, true)
          .then(function (d) {
            if (mien !== generation) {
              annonce.remove();

              return;
            }

            if (!d || d.ok !== true) {
              annonce.remove();
              echecDeGrille((d && d.message) || txt(r, 'echec', ''));

              return;
            }

            if (d.attente === true) {
              ecoule += INTERVALLE_SONDE;

              // ⚠️ UNE BORNE, SINON C'EST UN SABLIER ÉTERNEL. Le serveur a ses
              // propres gardes, mais un réseau coupé ne les atteint pas : sans
              // celle-ci, la page tournerait jusqu'à ce qu'on la ferme.
              if (ecoule > ATTENTE_MAX) {
                annonce.remove();
                echecDeGrille(txt(r, 'calculTrop', ''));

                return;
              }

              annonce.textContent = texteAttente(depart.secondes || 25, ecoule);
              sonde = window.setTimeout(relire, INTERVALLE_SONDE);

              return;
            }

            annonce.remove();
            poserGrille(d);
          })
          .catch(function (e) {
            if (e.name === 'AbortError' || mien !== generation) {
              return;
            }

            annonce.remove();
            echecDeGrille(txt(r, 'echec', ''));
          });
      }

      sonde = window.setTimeout(relire, INTERVALLE_SONDE);
    }

    function texteAttente(estimation, ecoule) {
      var reste = Math.max(0, Math.round((estimation * 1000 - ecoule) / 1000));

      return txt(r, 'calculSurMesure', 'Calcul en cours…')
        + (reste > 0 ? ' (' + reste + ' s)' : '');
    }

    function arreterSonde() {
      if (sonde !== null) {
        window.clearTimeout(sonde);
        sonde = null;
      }
    }

    /** Ce qu'on fait d'une grille qui vient d'arriver, par l'un ou l'autre chemin. */
    function poserGrille(d) {
      grille = d;

      // La quantité la plus faible d'abord : c'est le point d'entrée le moins
      // engageant, et le visiteur monte s'il y trouve son compte.
      quantiteChoisie = d.quantities.length ? Math.min.apply(null, d.quantities) : null;
      choisirDelaiLePlusEconomique();
      rendreQuantites();
      rendrePrestations();
      rendreDelais();
      rendreResume();
    }

    function casesPour(q) {
      return (grille.grid || []).filter(function (c) { return c.quantity === q; });
    }

    function choisirDelaiLePlusEconomique() {
      var cases = casesPour(quantiteChoisie);

      if (!cases.length) {
        delaiChoisi = null;

        return;
      }

      delaiChoisi = cases.reduce(function (a, b) { return b.lot < a.lot ? b : a; }).delay;
    }

    function caseCourante() {
      return casesPour(quantiteChoisie).filter(function (c) { return c.delay === delaiChoisi; })[0] || null;
    }

    function retirerLigne(nom) {
      var l = zoneEtapes.querySelector('[data-ligne="' + nom + '"]');

      if (l) {
        l.remove();
      }
    }

    /**
     * Pose une ligne À SA PLACE, et pas à la fin.
     *
     * ⚠️ Les lignes se redessinent une à une — cliquer une quantité redessine
     * la quantité, les délais et le récapitulatif. Chacune était retirée puis
     * AJOUTÉE EN FIN de liste : au premier clic, l'ordre devenait quantités,
     * prestations, délais… puis quantités à nouveau, tout en bas. L'ordre
     * n'était juste qu'au premier affichage.
     *
     * Le rang est donc porté par la ligne, et l'insertion le respecte. Les
     * prestations viennent AVANT le délai : on choisit son bon à tirer et sa
     * création avant de choisir quand on veut être livré.
     */
    function poserLigne(l, rang) {
      l.dataset.rang = String(rang);

      var suivantes = [].slice.call(zoneEtapes.querySelectorAll('[data-ligne]'))
        .filter(function (x) { return Number(x.dataset.rang) > rang; });

      zoneEtapes.insertBefore(l, suivantes[0] || null);
    }

    function rendreQuantites() {
      retirerLigne('quantite');

      // Le prix montré sur chaque quantité est celui du délai LE MOINS CHER,
      // pour que les quantités se comparent entre elles et non au délai qui
      // se trouve sélectionné. Comparer deux quantités sur deux délais
      // différents ferait paraître la plus grosse plus chère.
      var cartes = grille.quantities.map(function (q) {
        var cases = casesPour(q);
        var moins = cases.length ? cases.reduce(function (a, b) { return b.lot < a.lot ? b : a; }) : null;

        return {
          nom: q.toLocaleString() + ' ' + txt(r, 'exemplaires', 'ex.'),
          prix: moins ? moins.lot_texte : '',
          note: moins ? moins.unite_texte + ' ' + txt(r, 'unite', '') : '',
          valeur: q
        };
      });

      var actif = grille.quantities.indexOf(quantiteChoisie);

      // Douze : deux rangées pleines sur la largeur de la colonne. Au-delà on
      // entre dans les quantités d'imprimerie — 10 000, 20 000 — que peu de
      // visiteurs cherchent et qui, affichées, noient les petites.
      var l = ligne(txt(r, 'quantite', 'Quantité') + ' :', cartes, actif, function (i, c) {
        quantiteChoisie = c.valeur;
        choisirDelaiLePlusEconomique();
        rendreQuantites();
        rendreDelais();
        rendreResume();
      }, { replier: 12 });

      l.dataset.ligne = 'quantite';
      poserLigne(l, 10);
    }

    function rendreDelais() {
      retirerLigne('delai');

      var cases = casesPour(quantiteChoisie);

      // Un seul délai proposé n'est pas un choix : l'afficher demanderait au
      // visiteur de trancher entre une option et rien.
      if (cases.length < 2) {
        rendreResume();

        return;
      }

      // Le moins cher d'abord : c'est l'offre incluse, celle qui ne coûte rien
      // de plus. Les suivantes se paient pour aller plus vite.
      var tries = cases.slice().sort(function (a, b) { return a.lot - b.lot; });
      var base = tries[0];
      var rapides = tries.slice(1);
      // L'heure limite de commande, si l'imprimeur en a déclaré une. Elle
      // n'existe QUE si elle a été réglée : « Si commandé avant 18h » est un
      // engagement, et un engagement ne s'invente pas à la place du marchand.

      var l = document.createElement('div');
      l.className = 'eko-poc__ligne eko-poc__ligne--delais';
      l.dataset.ligne = 'delai';

      var tete = document.createElement('div');
      tete.className = 'eko-poc__ligne-tete';

      var t = document.createElement('h3');
      t.className = 'eko-poc__critere';
      t.textContent = txt(r, 'livraison', 'Délai') + ' :';
      tete.appendChild(t);
      l.appendChild(tete);

      function carteDelai(c, grande) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'eko-poc__delai'
          + (grande ? ' eko-poc__delai--phare' : '')
          + (c.delay === delaiChoisi ? ' eko-poc__delai--actif' : '');
        b.setAttribute('aria-pressed', c.delay === delaiChoisi ? 'true' : 'false');

        var gauche = '<span class="eko-poc__delai-nom">' + echapper(nomDe(c.delay)) + '</span>';

        if (c.date_texte) {
          gauche += '<span class="eko-poc__delai-mention">' + echapper(txt(r, 'livree', '')) + '</span>'
            + '<strong class="eko-poc__delai-date">' + echapper(c.date_texte) + '</strong>';
        }

        // ⚠️ LE SUPPLÉMENT, ET NON LE TOTAL. Cette carte écrivait
        // « Supplément » suivi du prix ENTIER du lot : un visiteur lisait que
        // partir plus vite lui coûtait deux cent soixante-sept euros de plus,
        // quand l'écart réel était de vingt-sept. Le mot annonçait une
        // différence, le nombre donnait un total. L'écart est calculé par le
        // serveur, qui seul connaît la devise et la langue du visiteur.
        var prix = (c.lot <= base.lot || !c.sup_texte)
          ? '<span class="eko-poc__delai-offert">' + echapper(txt(r, 'offert', 'Inclus')) + '</span>'
          : '<span class="eko-poc__delai-sup">'
            + '<span>' + echapper(txt(r, 'supplement', 'Supplément')) + '</span>'
            + '<strong>' + echapper(c.sup_texte) + '</strong></span>';

        b.innerHTML = '<span class="eko-poc__delai-corps">' + gauche + '</span>' + prix;
        b.addEventListener('click', function () {
          if (c.delay === delaiChoisi) {
            return;
          }

          delaiChoisi = c.delay;
          rendreDelais();
          rendreResume();
        });

        return b;
      }

      function colonne(titre, cartes, phare, note) {
        var col = document.createElement('div');
        col.className = 'eko-poc__delais-col' + (phare ? ' eko-poc__delais-col--phare' : '');

        if (titre) {
          var h = document.createElement('p');
          h.className = 'eko-poc__delais-titre';
          h.textContent = titre;
          col.appendChild(h);
        }

        cartes.forEach(function (c) { col.appendChild(carteDelai(c, phare)); });

        if (note) {
          var n = document.createElement('p');
          n.className = 'eko-poc__delai-note';
          n.textContent = note;
          col.appendChild(n);
        }

        return col;
      }

      var grilleDelais = document.createElement('div');
      // La grille s'adapte au nombre d'options : une seule offre rapide et les
      // deux colonnes se valent, plusieurs et la colonne de droite prend le
      // large pour empiler sans écraser. Posé en attribut plutôt qu'en classe
      // par palier — le CSS lit le nombre, il n'a pas à connaître les cas.
      grilleDelais.className = 'eko-poc__delais';
      grilleDelais.dataset.rapides = String(rapides.length);

      grilleDelais.appendChild(
        colonne(txt(r, 'meilleure', ''), [base], true, grille.note_delai || '')
      );

      if (rapides.length) {
        grilleDelais.appendChild(
          colonne(txt(r, 'plusVite', ''), rapides, false, grille.note_delai_rapide || '')
        );
      }

      l.appendChild(grilleDelais);
      // 30 : APRÈS les prestations. Le délai se choisit en dernier, une fois
      // connu tout ce qui compose la commande.
      poserLigne(l, 30);
    }

    /**
     * Les prestations de l'imprimeur, en tuiles.
     *
     * ─── POURQUOI DES TUILES ET PLUS UNE LISTE DÉROULANTE ──────────────────
     *
     * Une liste déroulante cache ses options : le visiteur voit « BAT
     * numérique » et doit CLIQUER pour découvrir qu'il existe une relecture par
     * un graphiste. C'est exactement l'inverse de ce qu'on veut d'un choix qui
     * se vend. Trois tuiles côte à côte montrent les trois offres, leur
     * description et leur prix, sans un geste.
     *
     * Le prix affiché vient du SERVEUR, déjà mis en forme et déjà dans le bon
     * régime — hors taxes pour un compte professionnel. Le navigateur ne
     * connaît ni la devise ni les décimales de la boutique.
     *
     * Elles restent APRÈS le délai : ce sont des suppléments, et les placer
     * avant ferait choisir des options avant même de savoir ce que coûte le
     * produit qu'elles complètent.
     */
    function rendrePrestations() {
      services.forEach(function (sv, i) {
        retirerLigne('svc-' + sv.cle);

        var l = document.createElement('div');
        l.className = 'eko-poc__ligne eko-poc__ligne--prestations';
        l.dataset.ligne = 'svc-' + sv.cle;

        var t = document.createElement('h3');
        t.className = 'eko-poc__critere';
        t.textContent = sv.label + ' :';
        l.appendChild(t);

        var grilleTuiles = document.createElement('div');
        grilleTuiles.className = 'eko-poc__prestations';
        // Le CSS lit le NOMBRE d'options plutôt qu'une classe par cas : deux
        // prestations doivent s'étaler, cinq doivent se replier, et le jour où
        // le marchand en ajoute une sixième rien ne doit être retouché ici.
        grilleTuiles.dataset.combien = String(sv.options.length);

        sv.options.forEach(function (o) {
          var actif = choixServices[sv.cle] === o.nom;

          var b = document.createElement('button');
          b.type = 'button';
          b.className = 'eko-poc__presta' + (actif ? ' eko-poc__presta--actif' : '');
          b.setAttribute('aria-pressed', actif ? 'true' : 'false');

          var corps = dessinPrestation(o.nom)
            + '<span class="eko-poc__presta-nom">' + echapper(o.nom) + '</span>';

          if (o.texte) {
            corps += '<span class="eko-poc__presta-texte">' + echapper(o.texte) + '</span>';
          }

          // « Gratuit » plutôt qu'un « 0,00 € » : un montant nul se lit comme
          // un prix que le site a oublié de calculer.
          corps += '<span class="eko-poc__presta-prix">'
            + echapper(o.prix_texte || txt(r, 'gratuit', 'Gratuit'))
            + '</span>';

          b.innerHTML = corps;

          b.addEventListener('click', function () {
            if (choixServices[sv.cle] === o.nom) {
              return;
            }

            choixServices[sv.cle] = o.nom;
            // Le clic repeint TOUT DE SUITE la sélection, avant l'aller-retour
            // au serveur : sans cela la tuile reste éteinte le temps du calcul
            // et le visiteur reclique, croyant avoir manqué la cible.
            rendrePrestations();
            // Un supplément change le PRIX : il faut redemander la grille, pas
            // l'ajuster dans le navigateur.
            chargerGrille();
          });

          grilleTuiles.appendChild(b);
        });

        l.appendChild(grilleTuiles);
        poserLigne(l, 20 + i);
      });
    }

    /**
     * Les deux quantités supérieures, sous le récapitulatif.
     *
     * L'impression est dégressive : passer de 100 à 250 exemplaires coûte
     * souvent quelques euros. Le visiteur ne le sait pas s'il a déjà choisi sa
     * quantité et ne regarde plus la grille — il faut le lui remettre sous les
     * yeux au moment où il décide.
     *
     * ⚠️ On montre les montants du serveur, JAMAIS un écart calculé ici. Le
     * navigateur ne sait ni la devise ni le nombre de décimales de la boutique,
     * et une soustraction faite là produirait un « + 3.0000000004 € ».
     */
    function rendreQuantitesSuperieures() {
      var plus = grille.quantities
        .filter(function (q) { return q > quantiteChoisie; })
        .sort(function (a, b) { return a - b; })
        .slice(0, 2)
        .map(function (q) {
          var cases = casesPour(q).filter(function (x) { return x.delay === delaiChoisi; });

          return cases.length ? { quantite: q, c: cases[0] } : null;
        })
        .filter(Boolean);

      if (plus.length === 0) {
        return;
      }

      var titre = document.createElement('p');
      titre.className = 'eko-poc__superieures-titre';
      titre.textContent = txt(r, 'superieures', '') + ' :';
      zoneResume.appendChild(titre);

      var boite = document.createElement('div');
      boite.className = 'eko-poc__superieures';

      plus.forEach(function (p) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'eko-poc__superieure';
        b.innerHTML =
          '<span class="eko-poc__superieure-qte">' +
          echapper(p.quantite.toLocaleString() + ' ' + txt(r, 'exemplaires', '')) + '</span>' +
          '<span class="eko-poc__superieure-prix">' + echapper(p.c.lot_texte) + '</span>' +
          '<span class="eko-poc__superieure-unite">' +
          echapper(p.c.unite_texte + ' ' + txt(r, 'unite', '')) + '</span>';
        b.addEventListener('click', function () {
          quantiteChoisie = p.quantite;
          choisirDelaiLePlusEconomique();
          rendreQuantites();
          rendreDelais();
          rendreResume();
        });
        boite.appendChild(b);
      });

      zoneResume.appendChild(boite);
    }

    /**
     * Les arguments de réassurance, sous le bouton de commande.
     *
     * Ils n'existent que si le marchand en a réglé : ce sont des engagements —
     * « livraison offerte », « paiement sécurisé » — et le module ne promet
     * rien à sa place.
     *
     * Une icône est soit l'un des pictogrammes dessinés par le module, soit une
     * image de la boutique : le chemin a été validé côté serveur, où seuls un
     * chemin interne et une adresse http(s) passent.
     */
    function rendreReassurances() {
      if (!reassurances.length) {
        return;
      }

      var boite = document.createElement('ul');
      boite.className = 'eko-poc__reassure';

      reassurances.forEach(function (a) {
        var li = document.createElement('li');
        var image = /^(\/|https?:)/.test(a.icone)
          ? '<img class="eko-poc__reassure-icone" src="' + echapper(a.icone) + '" alt="" loading="lazy">'
          : pictogramme(a.icone);

        li.innerHTML = image + '<span>' + echapper(a.nom) + '</span>';
        boite.appendChild(li);
      });

      zoneResume.appendChild(boite);
    }

    // ─── Spécifications techniques et gabarit ────────────────────────────
    //
    // Le configurateur connaît la SÉLECTION ; il ne connaît pas les règles qui
    // en tirent des cotes — lecture du format, format ouvert d'un dépliant,
    // pagination d'une brochure, grammage d'un papier. Ces règles vivent en
    // PHP, et c'est le même code qui sert le panier.
    //
    // On envoie donc les critères tels qu'ils s'affichent dans le
    // récapitulatif, et on reçoit le bloc tout fait. Les réécrire ici ferait
    // deux implémentations d'un même calcul : d'accord le premier jour,
    // divergentes au premier correctif — et le client lirait des cotes sur la
    // fiche et d'autres au panier.
    var zoneSpecs = null;
    var derniereSignature = '';

    /** Ce qu'un champ vaut, écrit pour un humain. */
    function valeurLisible(ch) {
      // ⚠️ Le format ne vit PAS sous son propre identifiant : il pose deux
      // cotes et n'en porte aucune. Lu comme les autres, il rendait une chaîne
      // vide, et la taille commandée disparaissait du récapitulatif — donc de
      // ce que le client relit avant de payer.
      if (ch.type === 'format') {
        var f = formatCourant(ch);

        // Sans format connu, la ligne disparaît du récapitulatif : les deux
        // cotes y figurent déjà, et répéter « sur mesure » n'apprendrait rien.
        return f ? f.label : '';
      }

      if (ch.type === 'cote') {
        var v = config[ch.id];

        return (v === undefined || v === null || v === '') ? '' : (v + ' cm');
      }

      var v = config[ch.id];

      if (v === undefined || v === null || v === '') {
        return '';
      }

      if (ch.type === 'case') {
        return txt(r, String(v) === '1' ? 'oui' : 'non', String(v));
      }

      var trouve = (ch.valeurs || []).filter(function (o) { return o.id === v; })[0];

      return trouve ? trouve.label : String(v);
    }

    function criteresCourants() {
      var criteres = {};

      if (MODE === 'formulaire') {
        champs.forEach(function (ch) {
          var v = valeurLisible(ch);

          if (v !== '') {
            criteres[ch.nom] = v;
          }
        });
      }

      selection.forEach(function (v, i) {
        var label = (etapes[i] && etapes[i].label) || '';

        if (label) {
          criteres[label] = nomDe(v);
        }
      });

      criteres[txt(r, 'quantite', 'Quantité')] = String(quantiteChoisie);

      Object.keys(choixServices).forEach(function (cle) {
        var sv = services.filter(function (x) { return x.cle === cle; })[0];

        if (sv && choixServices[cle]) {
          criteres[sv.label] = choixServices[cle];
        }
      });

      return criteres;
    }

    function rendreSpecifications() {
      var url = r.dataset.urlSpecs;

      if (!url) {
        return;
      }

      var criteres = criteresCourants();
      var signature = JSON.stringify(criteres);

      // Rien n'a changé : on ne redemande pas. Le visiteur qui parcourt les
      // paliers de quantité déclencherait sinon un appel par clic.
      if (signature === derniereSignature && zoneSpecs && zoneSpecs.innerHTML) {
        return;
      }

      derniereSignature = signature;

      // ⚠️ La cible n'est PLUS le récapitulatif : le bloc se pose sous la
      // « Fiche technique » de la section du bas, là où il détaille des
      // valeurs qu'on vient de lire. Dans le récapitulatif, il cassait la
      // grille à deux colonnes de la réassurance.
      zoneSpecs = document.querySelector('.eko-tech__specs');

      if (!zoneSpecs) {
        return;
      }

      var p = new URLSearchParams();
      p.append('id_product', r.dataset.idProduct || '');
      p.append('ajax', '1');

      Object.keys(criteres).forEach(function (cle) {
        p.append('criteres[' + cle + ']', criteres[cle]);
      });

      fetch(url + (url.indexOf('?') === -1 ? '?' : '&') + p.toString(), {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
        .then(function (rep) { return rep.json(); })
        .then(function (d) {
          // Un bloc vide n'est PAS une panne : c'est un format dont on ne sait
          // pas tirer de cotes — une boîte, un beach flag. On n'affiche rien
          // plutôt qu'un message d'erreur qui inquiéterait pour rien.
          zoneSpecs.innerHTML = (d && d.html) || '';
        })
        .catch(function () {
          zoneSpecs.innerHTML = '';
        });
    }

    function rendreResume() {
      zoneResume.innerHTML = '';

      var c = grille ? caseCourante() : null;

      if (!c) {
        zoneResume.appendChild(message('attente', txt(r, 'attendez', '')));
        commande(false, txt(r, 'attendez', ''));

        return;
      }

      var h = document.createElement('h3');
      h.className = 'eko-poc__resume-titre';
      h.textContent = txt(r, 'detail', '');
      zoneResume.appendChild(h);

      var p = document.createElement('p');
      p.className = 'eko-poc__resume-prix';
      p.innerHTML =
        '<span class="eko-poc__resume-total">' + echapper(c.lot_texte) + '</span>' +
        '<span class="eko-poc__resume-regime">' + echapper(txt(r, grille.ht ? 'ht' : 'ttc', '')) + '</span>';
      zoneResume.appendChild(p);

      var u = document.createElement('p');
      u.className = 'eko-poc__resume-unite';
      u.textContent = c.unite_texte + ' ' + txt(r, 'unite', '');
      zoneResume.appendChild(u);

      // L'encart prix public : ce qu'un particulier paierait sur cette même
      // boutique. Il n'apparaît que pour qui achète en hors taxes — revendeur
      // ou compte B2B. Le serveur ne l'envoie qu'à eux, le front n'a donc pas
      // à savoir qui est qui.
      if (c.public_texte) {
        var pub = document.createElement('p');
        pub.className = 'eko-poc__resume-public';
        pub.innerHTML = '<span>' + echapper(txt(r, 'prixPublic', '')) + '</span>'
          + '<strong>' + echapper(c.public_texte) + '</strong>';
        zoneResume.appendChild(pub);
      }

      // La promesse commerciale du marchand, s'il en a réglé une.
      if (grille.mention_prix) {
        var m = document.createElement('p');
        m.className = 'eko-poc__resume-mention';
        m.textContent = grille.mention_prix;
        zoneResume.appendChild(m);
      }

      // Le nom du produit : le récapitulatif est collant et suit le visiteur
      // pendant qu'il fait défiler ; sans le nom, on finit par lire un prix
      // sans savoir de quoi.
      if (nomProduit) {
        var np = document.createElement('p');
        np.className = 'eko-poc__resume-produit';
        np.textContent = nomProduit;
        zoneResume.appendChild(np);
      }

      var ul = document.createElement('ul');
      ul.className = 'eko-poc__resume-liste';

      if (MODE === 'formulaire') {
        champs.forEach(function (ch) {
          var v = valeurLisible(ch);

          if (v === '') {
            return;
          }

          var li = document.createElement('li');
          li.innerHTML =
            '<span>' + echapper(ch.nom) + '</span>' +
            '<strong>' + echapper(v) + '</strong>';
          ul.appendChild(li);
        });
      } else {
        selection.forEach(function (v, i) {
          var li = document.createElement('li');
          li.innerHTML =
            '<span>' + echapper((etapes[i] && etapes[i].label) || '') + '</span>' +
            '<strong>' + echapper(nomDe(v)) + '</strong>';
          ul.appendChild(li);
        });
      }

      var liQ = document.createElement('li');
      liQ.innerHTML =
        '<span>' + echapper(txt(r, 'quantite', '')) + '</span>' +
        '<strong>' + echapper(quantiteChoisie.toLocaleString() + ' ' + txt(r, 'exemplaires', '')) + '</strong>';
      ul.appendChild(liQ);

      var liD = document.createElement('li');
      liD.innerHTML =
        '<span>' + echapper(txt(r, 'livraison', '')) + '</span>' +
        '<strong>' + echapper(nomDe(c.delay)) + '</strong>';
      ul.appendChild(liD);

      // La date estimée, en toutes lettres. « J+5 » demande au visiteur de
      // compter les jours ouvrés lui-même ; « vendredi 21 août » se lit.
      if (c.date_texte) {
        var liL = document.createElement('li');
        liL.className = 'eko-poc__resume-date';
        liL.innerHTML =
          '<span>' + echapper(txt(r, 'livree', '')) + '</span>' +
          '<strong>' + echapper(c.date_texte) + '</strong>';
        ul.appendChild(liL);
      }

      services.forEach(function (sv) {
        var li = document.createElement('li');
        li.innerHTML =
          '<span>' + echapper(sv.label) + '</span>' +
          '<strong>' + echapper(choixServices[sv.cle] || '') + '</strong>';
        ul.appendChild(li);
      });

      // Le supplément se lit SÉPARÉMENT bien qu'il soit déjà dans le total :
      // un client doit pouvoir rapprocher son devis du prix affiché sans
      // deviner ce qui a été ajouté.
      if (grille.supplement) {
        var liS = document.createElement('li');
        liS.className = 'eko-poc__resume-supplement';
        liS.innerHTML =
          '<span>' + echapper(txt(r, 'dontPrestations', 'dont prestations')) + '</span>' +
          '<strong>' + echapper(grille.supplement) + '</strong>';
        ul.appendChild(liS);
      }

      zoneResume.appendChild(ul);

      if (grille.stale) {
        zoneResume.appendChild(message('perime', txt(r, 'perime', '')));
      }

      rendreQuantitesSuperieures();

      // Le SEUL bouton d'ajout au panier de la fiche. Celui du thème et son
      // sélecteur de quantité sont masqués par la feuille de style : ils
      // demandaient un nombre d'exemplaires que le configurateur porte déjà,
      // et un visiteur devant deux quantités ne sait pas laquelle compte.
      //
      // Masqués, pas retirés : le formulaire qui les entoure porte le jeton et
      // l'identifiant de configuration, et c'est lui qu'on déclenche.
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'eko-poc__resume-panier';
      b.textContent = txt(r, 'ajouter', 'Ajouter au panier');
      b.addEventListener('click', function () { ajouterAuPanier(b); });
      zoneResume.appendChild(b);
      zoneResume.appendChild(messagePanier);
      rendreReassurances();
      // Le lien « Spécifications techniques » se pose sous la fiche technique
      // de la section du bas — pas dans le récapitulatif, où il cassait la
      // grille à deux colonnes de la réassurance.
      rendreSpecifications();

      // ⚠️ On n'ouvre la commande QUE sur un tarif frais. Le bandeau disait
      // « Confirmez avant de commander » et le bouton restait armé : personne
      // n'était tenu de confirmer quoi que ce soit. Sur un tarif périmé, le
      // bouton reste fermé et son infobulle dit pourquoi.
      commande(!grille.stale, txt(r, 'perime', ''));

    }

    recharger(0);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', demarrer);
  } else {
    demarrer();
  }

  // Le thème re-rend son bloc produit : on remasque et on réinitialise.
  if (window.prestashop && typeof window.prestashop.on === 'function') {
    window.prestashop.on('updatedProduct', function () {
      masquerNatif();
      demarrer();
    });
  }
})();
