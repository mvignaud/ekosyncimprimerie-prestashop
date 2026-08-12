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
 * Les lignes apparaissent AU FUR ET À MESURE. L'arbre est élagué — chaque choix
 * restreint le suivant, et c'est ce qui fait tomber une carte de visite de
 * 11 760 combinaisons théoriques à 220 réelles. Afficher toutes les lignes d'un
 * coup obligerait à les remplir de choix qui n'existent pas ensemble.
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
   */
  function vignetteFormat(code, refMax) {
    var d = dimensions(code);
    var boite = 58;

    if (!d) {
      return '<svg class="eko-poc__svg" viewBox="0 0 ' + boite + ' ' + boite + '" aria-hidden="true">'
        + '<rect x="8" y="14" width="42" height="30" rx="2" class="eko-poc__svg-forme"/></svg>';
    }

    var plus = Math.max(d.l, d.h);
    var echelle = (boite - 8) / Math.max(refMax || plus, 1);
    var l = Math.max(6, d.l * echelle);
    var h = Math.max(6, d.h * echelle);

    return '<svg class="eko-poc__svg" viewBox="0 0 ' + boite + ' ' + boite + '" aria-hidden="true">'
      + '<rect x="' + ((boite - l) / 2).toFixed(1) + '" y="' + ((boite - h) / 2).toFixed(1) + '"'
      + ' width="' + l.toFixed(1) + '" height="' + h.toFixed(1) + '" rx="1.5" class="eko-poc__svg-forme"/>'
      + '</svg>';
  }

  /** La mesure lisible d'un format, ou son code si on ne sait pas le lire. */
  function mesure(code) {
    var d = dimensions(code);

    return d ? d.l + ' × ' + d.h + ' mm' : '';
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
    function estFormat(etape) {
      return /format|dimension|taille/i.test(String(etape.code || ''));
    }

    /** Une ligne de vignettes proportionnelles. */
    function ligneFormats(titre, options, choisi, surChoix) {
      var refMax = options.reduce(function (m, o) {
        var d = dimensions(o);

        return d ? Math.max(m, d.l, d.h) : m;
      }, 0);

      var cartes = options.map(function (o) {
        return { nom: o, note: mesure(o), svg: vignetteFormat(o, refMax) };
      });

      var l = ligne(titre, cartes, choisi === null ? -1 : options.indexOf(choisi), function (i, c) {
        surChoix(c.nom);
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

      if (choisi !== null) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'eko-poc__choix';
        b.innerHTML = '<span>' + echapper(choisi) + '</span>'
          + '<span class="eko-poc__choix-modifier">' + echapper(txt(r, 'modifier', '')) + '</span>';
        b.addEventListener('click', function () { surChoix(choisi); });
        l.appendChild(b);

        return l;
      }

      var sel = document.createElement('select');
      sel.className = 'form-control eko-poc__liste';

      var vide = document.createElement('option');
      vide.value = '';
      vide.textContent = txt(r, 'choisir', '—');
      sel.appendChild(vide);

      options.forEach(function (o) {
        var op = document.createElement('option');
        op.value = o;
        op.textContent = o;
        sel.appendChild(op);
      });

      sel.addEventListener('change', function () {
        if (sel.value !== '') {
          surChoix(sel.value);
        }
      });

      l.appendChild(sel);

      return l;
    }

    // ─── L'arbre, étape par étape ──────────────────────────────────────────

    function chargerEtape() {
      grille = null;
      quantiteChoisie = null;
      delaiChoisi = null;
      commande(false, txt(r, 'attendez', ''));
      rendreResume();

      var attente = message('attente', txt(r, 'attente', 'Chargement…'));
      zoneEtapes.appendChild(attente);

      appeler({ quoi: 'arbre' })
        .then(function (d) {
          attente.remove();

          if (!d || d.ok !== true) {
            zoneEtapes.appendChild(message('erreur', (d && d.message) || txt(r, 'echec', '')));

            return;
          }

          etapes = d.steps || [];

          if (d.complete || !d.options.length) {
            chargerGrille();

            return;
          }

          var rang = d.rank || 0;
          var etape = etapes[rang] || {};
          var titre = (etape.label || '') + ' :';

          function choisir(valeur) {
            selection.push(valeur);
            redessinerDepuis(rang);
            chargerEtape();
          }

          // Le format se MONTRE, le reste se choisit dans une liste.
          //
          // Un format est une forme : deux rectangles côte à côte se comparent
          // instantanément, là où « A6 » et « 100x100 » demandent de savoir ce
          // qu'ils valent. Un grammage ou une finition n'a pas de forme — en
          // faire des cartes ne donnerait que des libellés dans des cadres,
          // occupant dix fois la place d'une liste pour rien.
          if (estFormat(etape)) {
            zoneEtapes.appendChild(ligneFormats(titre, d.options, null, choisir));
          } else {
            zoneEtapes.appendChild(ligneListe(titre, d.options, null, choisir));
          }
        })
        .catch(function (e) {
          if (e.name === 'AbortError') {
            return;
          }

          attente.remove();
          zoneEtapes.appendChild(message('erreur', txt(r, 'echec', '')));
        });
    }

    /**
     * Rejouer une étape déjà franchie.
     *
     * Tout ce qui suit devient caduc : les choix suivants n'existent peut-être
     * pas sous le nouveau, et la grille encore moins. On tronque plutôt que de
     * garder une sélection qui a l'air valide et ne l'est plus.
     */
    function redessinerDepuis(rang) {
      var lignes = zoneEtapes.querySelectorAll('.eko-poc__ligne');

      for (var i = lignes.length - 1; i >= rang; i--) {
        lignes[i].remove();
      }

      selection = selection.slice(0, rang + 1);

      var etape = etapes[rang] || {};
      var titre = (etape.label || '') + ' :';
      var choix = selection[rang];

      function revenir() {
        selection = selection.slice(0, rang);
        var restantes = zoneEtapes.querySelectorAll('.eko-poc__ligne');

        for (var j = restantes.length - 1; j >= rang; j--) {
          restantes[j].remove();
        }

        chargerEtape();
      }

      // Une étape franchie garde SA forme : un format reste une vignette, une
      // liste reste une liste. Changer d'apparence après le choix ferait
      // douter d'avoir cliqué au bon endroit.
      zoneEtapes.appendChild(
        estFormat(etape)
          ? ligneFormats(titre, [choix], choix, revenir)
          : ligneListe(titre, [choix], choix, revenir)
      );
    }

    // ─── La grille ─────────────────────────────────────────────────────────

    function chargerGrille() {
      var attente = message('attente', txt(r, 'calcul', 'Calcul…'));
      zoneEtapes.appendChild(attente);

      appeler({ quoi: 'grille' })
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
            nom: c.delay,
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
          '<strong>' + echapper(v) + '</strong>';
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
        '<strong>' + echapper(c.delay) + '</strong>';
      ul.appendChild(liD);

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

    chargerEtape();
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
