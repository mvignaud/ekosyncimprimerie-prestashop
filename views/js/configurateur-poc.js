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
  function vignetteFormat(d, refMax, rond) {
    var boite = 58;
    var ouvre = '<svg class="eko-poc__svg" viewBox="0 0 ' + boite + ' ' + boite + '" aria-hidden="true">';

    if (!d) {
      return ouvre + '<rect x="8" y="14" width="42" height="30" rx="2" class="eko-poc__svg-forme"/></svg>';
    }

    var plus = Math.max(d.l, d.h);
    var echelle = (boite - 8) / Math.max(refMax || plus, 1);
    var l = Math.max(6, d.l * echelle);
    var h = Math.max(6, d.h * echelle);

    // Un format rond dessiné en carré est une vignette qui ment sur ce qu'on
    // achète. Le libellé le dit — « 10 cm de diamètre (rond) » — et c'est la
    // seule source dont on dispose : l'ERP ne fournit pas de dessin ici.
    if (rond) {
      return ouvre + '<circle cx="' + (boite / 2) + '" cy="' + (boite / 2) + '"'
        + ' r="' + (Math.max(l, h) / 2).toFixed(1) + '" class="eko-poc__svg-forme"/></svg>';
    }

    return ouvre
      + '<rect x="' + ((boite - l) / 2).toFixed(1) + '" y="' + ((boite - h) / 2).toFixed(1) + '"'
      + ' width="' + l.toFixed(1) + '" height="' + h.toFixed(1) + '" rx="1.5" class="eko-poc__svg-forme"/>'
      + '</svg>';
  }

  /** Tous les boutons d'ajout au panier — les thèmes en posent souvent deux. */
  function boutonsPanier() {
    return document.querySelectorAll('[data-button-action="add-to-cart"]');
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

    masquerNatif();
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
    /** La grille de la configuration complète, une fois obtenue. */
    var grille = null;
    var quantiteChoisie = null;
    var delaiChoisi = null;
    var enCours = null;

    function appeler(params) {
      if (enCours) {
        enCours.abort();
      }

      enCours = new AbortController();

      var p = new URLSearchParams();
      p.set('ajax', '1');
      p.set('id_product', r.dataset.idProduct);
      Object.keys(params).forEach(function (k) { p.set(k, params[k]); });
      selection.forEach(function (v) { p.append('selection[]', v); });
      Object.keys(choixServices).forEach(function (k) {
        p.set('services[' + k + ']', choixServices[k]);
      });

      return fetch(r.dataset.url + '&' + p.toString(), {
        signal: enCours.signal,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (x) { return x.json(); });
    }

    // ─── Le rendu ──────────────────────────────────────────────────────────

    function ligne(titre, cartes, actifIndex, surChoix) {
      var l = document.createElement('div');
      l.className = 'eko-poc__ligne';

      var t = document.createElement('h3');
      t.className = 'eko-poc__critere';
      t.textContent = titre;
      l.appendChild(t);

      var piste = document.createElement('div');
      piste.className = 'eko-poc__cartes';

      cartes.forEach(function (c, i) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'eko-poc__carte' + (i === actifIndex ? ' eko-poc__carte--actif' : '');
        b.setAttribute('aria-pressed', i === actifIndex ? 'true' : 'false');

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

      l.appendChild(piste);

      return l;
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

      var cartes = options.map(function (o) {
        var d = dimensionsDe(o);
        var l = libelles[o];
        var nom = nomDe(o);

        return {
          nom: nom,
          note: d ? d.l + ' × ' + d.h + ' mm' : '',
          // Le dessin du fournisseur quand il y en a un — il montre la FORME
          // réelle, ce qu'un rectangle ne peut pas faire pour un découpé.
          // À défaut, la vignette proportionnelle, ronde ou rectangulaire.
          svg: (l && l.svg)
            ? '<span class="eko-poc__svg eko-poc__svg--erp">' + l.svg + '</span>'
            : vignetteFormat(d, refMax, /\brond\b|diam[èe]tre/i.test(nom))
        };
      });

      var l = ligne(titre, cartes, options.indexOf(choisi), function (i) {
        surChoix(options[i]);
      });

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
        Object.keys(d.labels || {}).forEach(function (k) { libelles[k] = d.labels[k]; });

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

      var l = ligne(txt(r, 'quantite', 'Quantité') + ' :', cartes, actif, function (i, c) {
        quantiteChoisie = c.valeur;
        choisirDelaiLePlusEconomique();
        rendreQuantites();
        rendreDelais();
        rendreResume();
      });

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

      var base = cases.reduce(function (a, b) { return b.lot < a.lot ? b : a; });

      var cartes = cases
        .slice()
        .sort(function (a, b) { return a.lot - b.lot; })
        .map(function (c) {
          return {
            nom: nomDe(c.delay),
            date: c.date_texte,
            prix: c.lot <= base.lot
              ? txt(r, 'offert', 'Inclus')
              : txt(r, 'supplement', 'Supplément') + ' ' + c.lot_texte,
            badge: c.lot <= base.lot ? txt(r, 'meilleure', '') : '',
            valeur: c.delay
          };
        });

      var actif = cartes.map(function (c) { return c.valeur; }).indexOf(delaiChoisi);

      var l = ligne(txt(r, 'livraison', 'Délai') + ' :', cartes, actif, function (i, c) {
        delaiChoisi = c.valeur;
        rendreDelais();
        rendreResume();
      });

      l.dataset.ligne = 'delai';
      l.classList.add('eko-poc__ligne--delais');
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

      // Le bouton d'ajout au panier vit ICI, sous le prix. Celui du thème est
      // plus bas, hors de vue une fois la grille dépliée : un visiteur qui
      // vient de choisir sa quantité doit pouvoir commander sans chercher.
      // Il ne double pas le bouton du thème — il le DÉCLENCHE, pour que le
      // panier reçoive exactement ce que PrestaShop attend.
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'eko-poc__resume-panier';
      b.textContent = txt(r, 'ajouter', 'Ajouter au panier');
      b.addEventListener('click', function () {
        var natif = boutonsPanier()[0];

        if (natif && !natif.disabled) {
          natif.click();
        }
      });
      zoneResume.appendChild(b);

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
