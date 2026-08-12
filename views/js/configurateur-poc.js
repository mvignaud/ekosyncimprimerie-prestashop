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

  function commande(autorisee, motif) {
    boutonsPanier().forEach(function (b) {
      b.disabled = !autorisee;

      if (autorisee) {
        b.removeAttribute('aria-disabled');
        b.removeAttribute('title');
      } else {
        b.setAttribute('aria-disabled', 'true');
        b.setAttribute('title', motif || '');
      }
    });
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
    /** Le nom du produit, tel que l'ERP le donne. */
    var nomProduit = '';
    /** Les ventes phares réglées au back-office : nom + format visé. */
    var ventes = [];
    /** Les réassurances affichées sous le bouton de commande. */
    var reassurances = [];
    /** La grille de la configuration complète, une fois obtenue. */
    var grille = null;
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
      selection.forEach(function (v) { p.append('selection[]', v); });
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

      return (l && l.name) ? l.name : code;
    }

    /**
     * Les dimensions connues de l'ERP priment sur celles qu'on devine.
     *
     * ⚠️ Elles sont en CENTIMÈTRES chez le fournisseur, là où les codes bruts
     * sont en millimètres. Vérifié sur trois formats normalisés : DL rendu
     * 9,9 × 21 vaut bien 99 × 210 mm, A3 rendu 29,7 × 42 vaut 297 × 420, A4
     * rendu 21 × 29,7 vaut 210 × 297. Les mélanger dessinerait un A4 dix fois
     * plus petit qu'un « 210x297 » sur la même ligne.
     */
    function dimensionsDe(code) {
      var l = libelles[code];

      return (l && l.width && l.height)
        ? { l: l.width * 10, h: l.height * 10 }
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
    function descendre(depuis) {
      selection = selection.slice(0, depuis);
      rangs = rangs.slice(0, depuis);

      return appeler({ quoi: 'arbre' }).then(function (d) {
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
    function rendreEtapes() {
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

    function chargerGrille() {
      var attente = message('attente', txt(r, 'calcul', 'Calcul…'));
      zoneEtapes.appendChild(attente);

      return appeler({ quoi: 'grille' })
        .then(function (d) {
          attente.remove();

          if (!d || d.ok !== true) {
            zoneEtapes.appendChild(message('erreur', (d && d.message) || txt(r, 'echec', '')));

            return;
          }

          grille = d;

          // La quantité la plus faible d'abord : c'est le point d'entrée le
          // moins engageant, et le visiteur monte s'il y trouve son compte.
          quantiteChoisie = d.quantities.length ? Math.min.apply(null, d.quantities) : null;
          choisirDelaiLePlusEconomique();
          rendreQuantites();
          rendreDelais();
          rendrePrestations();
          rendreResume();
        })
        .catch(function (e) {
          if (e.name === 'AbortError') {
            return;
          }

          attente.remove();
          zoneEtapes.appendChild(message('erreur', txt(r, 'echec', '')));
        });
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
      zoneEtapes.appendChild(l);
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

        var prix = c.lot <= base.lot
          ? '<span class="eko-poc__delai-offert">' + echapper(txt(r, 'offert', 'Inclus')) + '</span>'
          : '<span class="eko-poc__delai-sup">'
            + '<span>' + echapper(txt(r, 'supplement', 'Supplément')) + '</span>'
            + '<strong>' + echapper(c.lot_texte) + '</strong></span>';

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
      zoneEtapes.appendChild(l);
    }

    /**
     * Les prestations de l'imprimeur, en listes déroulantes.
     *
     * Elles viennent APRÈS le délai : ce sont des suppléments, et les placer
     * avant ferait choisir des options avant même de savoir ce que coûte le
     * produit qu'elles complètent.
     */
    function rendrePrestations() {
      services.forEach(function (sv) {
        retirerLigne('svc-' + sv.cle);

        var l = document.createElement('div');
        l.className = 'eko-poc__ligne eko-poc__ligne--liste';
        l.dataset.ligne = 'svc-' + sv.cle;

        var t = document.createElement('h3');
        t.className = 'eko-poc__critere';
        t.textContent = sv.label + ' :';
        l.appendChild(t);

        var sel = document.createElement('select');
        sel.className = 'form-control eko-poc__liste';

        sv.options.forEach(function (o) {
          var op = document.createElement('option');
          op.value = o.nom;
          // Le supplément est écrit par le serveur ; ici on ne fait
          // qu'indiquer qu'il y en a un, sans le mettre en forme nous-mêmes.
          op.textContent = o.nom;
          op.selected = choixServices[sv.cle] === o.nom;
          sel.appendChild(op);
        });

        sel.addEventListener('change', function () {
          choixServices[sv.cle] = sel.value;
          // Un supplément change le PRIX : il faut redemander la grille, pas
          // l'ajuster dans le navigateur.
          chargerGrille();
        });

        l.appendChild(sel);
        zoneEtapes.appendChild(l);
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

      selection.forEach(function (v, i) {
        var li = document.createElement('li');
        li.innerHTML =
          '<span>' + echapper((etapes[i] && etapes[i].label) || '') + '</span>' +
          '<strong>' + echapper(nomDe(v)) + '</strong>';
        ul.appendChild(li);
      });

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

      // Le prix est connu pour ce couple exact : la commande peut s'ouvrir.
      commande(true);
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
