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

        var html = '<span class="eko-poc__carte-nom">' + echapper(c.nom) + '</span>';

        if (c.prix) {
          html += '<span class="eko-poc__carte-prix">' + echapper(c.prix) + '</span>';
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
          var titre = (etapes[rang] && etapes[rang].label) || '';

          zoneEtapes.appendChild(
            ligne(
              titre + ' :',
              d.options.map(function (o) { return { nom: o }; }),
              -1,
              function (i, c) {
                selection.push(c.nom);
                redessinerDepuis(rang);
                chargerEtape();
              }
            )
          );
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

      var titre = (etapes[rang] && etapes[rang].label) || '';

      zoneEtapes.appendChild(
        ligne(titre + ' :', [{ nom: selection[rang] }], 0, function () {
          selection = selection.slice(0, rang);
          var restantes = zoneEtapes.querySelectorAll('.eko-poc__ligne');

          for (var j = restantes.length - 1; j >= rang; j--) {
            restantes[j].remove();
          }

          chargerEtape();
        })
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
          var ecart = c.lot - base.lot;

          return {
            nom: c.delay,
            prix: ecart <= 0
              ? txt(r, 'offert', 'Inclus')
              : txt(r, 'supplement', 'Supplément') + ' ' + ecartTexte(c, base),
            badge: ecart <= 0 ? txt(r, 'meilleure', '') : '',
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
      zoneEtapes.appendChild(l);
    }

    /**
     * L'écart entre deux cases, écrit par le serveur.
     *
     * On ne soustrait pas deux montants pour en fabriquer un troisième : ce
     * serait remettre le navigateur à calculer de l'argent. On affiche le prix
     * du délai, et la carte la moins chère porte « Inclus ».
     */
    function ecartTexte(c) {
      return c.lot_texte;
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
