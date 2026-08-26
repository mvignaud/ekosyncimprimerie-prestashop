/**
 * EKO Sync — Imprimerie · configurateur d'ATELIER
 *
 * ─── POURQUOI CE SCRIPT ÉMET LES CLASSES DU CONFIGURATEUR DE SOUS-TRAITANCE ─
 *
 * La boutique vend deux natures de produits, et le visiteur n'a aucune raison
 * de le savoir. Plutôt que d'écrire une seconde feuille de style qui
 * ressemblerait à la première et divergerait à la première retouche, ce
 * script bâtit LA MÊME STRUCTURE — `eko-poc__ligne`, `eko-poc__carte`,
 * `eko-poc__resume` — et la feuille de l'autre est chargée ici aussi.
 *
 * 1 350 lignes de style déjà éprouvées sur 84 fiches en production, acquises
 * d'un coup. Toute retouche visuelle profite désormais aux deux.
 *
 * ─── CE QUI DIFFÈRE, ET POURQUOI ────────────────────────────────────────────
 *
 * La sous-traitance descend un ARBRE de choix discrets ; l'atelier saisit des
 * cotes CONTINUES. « Choisissez votre format » n'a pas d'équivalent quand on
 * tape 1837 mm. Les dimensions sont donc des champs, groupés sur une ligne
 * « Dimensions » ; tout le reste — matières, options oui/non — devient des
 * cartes, exactement les mêmes.
 *
 * ⚠️ `em` et jamais `rem`, comme dans la feuille partagée.
 */
(function () {
  'use strict';

  /* ─── Outils ─────────────────────────────────────────────────────────── */

  function racine() {
    return document.querySelector('.eko-poc--atelier');
  }

  function txt(r, cle, defaut) {
    return (r && r.dataset[cle]) || defaut;
  }

  function echapper(t) {
    var d = document.createElement('div');
    d.textContent = t;

    return d.innerHTML;
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

  /**
   * Le champ quantité du THÈME, jamais un second.
   *
   * Un champ de plus créerait deux vérités : le prix serait mémorisé pour la
   * quantité de ce bloc, et le panier facturerait celle du thème.
   */
  function champQuantite() {
    return document.querySelector('#quantity_wanted, input[name="qty"]');
  }

  function boutonsPanier() {
    return document.querySelectorAll('[data-button-action="add-to-cart"]');
  }

  function formulairePanier() {
    return document.querySelector('#add-to-cart-or-refresh');
  }

  /* ─── Le verrou d'achat ──────────────────────────────────────────────── */

  /**
   * Les boutons que NOUS avons fermés, et eux seuls.
   *
   * Rouvrir tous les boutons d'achat de la page rouvrirait aussi ceux qu'une
   * rupture de stock ou un autre module avait fermés pour de bonnes raisons.
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
   * Poser la personnalisation dans le formulaire d'ajout au panier.
   *
   * ⚠️ SANS CECI, LE PRIX CONFIGURÉ NE SUIT PAS : PrestaShop ajoute la fiche
   * NUE, donc au prix catalogue.
   */
  function poserPersonnalisation(id) {
    var f = formulairePanier();

    if (!f || !id) {
      return;
    }

    var champ = f.querySelector('input[name="id_customization"]');

    if (champ) {
      champ.value = id;

      return;
    }

    champ = document.createElement('input');
    champ.type = 'hidden';
    champ.name = 'id_customization';
    champ.value = id;
    f.appendChild(champ);
  }

  /* ─── Sortir de la colonne d'achat ───────────────────────────────────── */

  /**
   * Sort le configurateur de la colonne du prix et le pose SOUS la fiche.
   *
   * Le module rend son bloc dans une colonne de 447 px là où la page en offre
   * 1 224. Changer de hook ne règle rien — les hooks produit d'un thème rendent
   * tous DANS une colonne. Il faut sortir de la grille, ce que seul le
   * navigateur peut faire une fois la page bâtie. C'est le geste du
   * configurateur de sous-traitance, et donc la mise en page de référence.
   */
  function deplacerSousLaFiche(r) {
    var fiche = document.querySelector('.product-container');
    var deja = document.querySelector('.eko-conf-section');

    if (deja) {
      if (!deja.contains(r)) {
        deja.appendChild(r);
      }

      return deja;
    }

    if (!fiche || !fiche.parentElement || !fiche.contains(r)) {
      return null;
    }

    var section = document.createElement('section');
    section.className = 'eko-conf-section';
    section.id = 'eko-configurateur';

    // ⚠️ Après la RANGÉE d'en-tête, pas après tout le conteneur : celui-ci
    // porte aussi les onglets « Description / Détails ».
    var entete = r.closest('.row') || fiche.firstElementChild;

    if (entete && entete.parentElement) {
      entete.parentElement.insertBefore(section, entete.nextSibling);
    } else {
      fiche.parentElement.insertBefore(section, fiche.nextSibling);
    }

    section.appendChild(r);

    // La fiche technique voyage AVEC : rendue par le même hook, elle est dans
    // le bloc prix, qu'on masque juste après.
    var tech = document.querySelector('.eko-tech');

    if (tech) {
      section.appendChild(tech);
    }

    return section;
  }

  /**
   * Le bouton qui descend vers le configurateur, à la place qu'il occupait.
   *
   * ⚠️ ENFANT DIRECT du conteneur. Glissé plus profond, il tombe dans un bloc
   * que le thème neutralise et occupe zéro pixel sans que rien ne le signale.
   */
  function ancreVersConfigurateur(r, section) {
    var hote = document.querySelector('.summary-container')
      || document.querySelector('.product-prices');

    if (!hote || !section || document.querySelector('.eko-poc-ancre')) {
      return;
    }

    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'eko-poc-ancre';
    b.textContent = txt(r, 'jeConfigure', 'Je configure mon produit');
    b.addEventListener('click', function () {
      section.scrollIntoView({ block: 'start', behavior: 'smooth' });
    });

    var informations = hote.querySelector(':scope > .product-information');

    if (informations) {
      hote.insertBefore(b, informations);
    } else {
      hote.appendChild(b);
    }
  }

  /**
   * Masquer ce que le thème affiche de son côté.
   *
   * Le prix natif montre le prix d'appel — pas celui de la configuration — et
   * le bloc de personnalisation expose la plomberie du module.
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

    // Les boutons natifs et le sélecteur de quantité du thème sont masqués par
    // la feuille partagée, sous `body.eko-poc-page` — au même endroit et de la
    // même façon que sur les fiches de sous-traitance. Les masquer une seconde
    // fois ici ferait deux vérités pour un même geste.
  }

  /* ─── Les lignes de critères ─────────────────────────────────────────── */

  /**
   * Une ligne de cartes — la même que celle du configurateur de sous-traitance.
   */
  function ligneCartes(titre, cartes, actifIndex, surChoix) {
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

      var nom = document.createElement('span');
      nom.className = 'eko-poc__carte-nom';
      nom.textContent = c.nom;
      b.appendChild(nom);

      if (c.note) {
        var note = document.createElement('span');
        note.className = 'eko-poc__carte-note';
        note.textContent = c.note;
        b.appendChild(note);
      }

      b.addEventListener('click', function () {
        piste.querySelectorAll('.eko-poc__carte').forEach(function (autre) {
          autre.classList.remove('eko-poc__carte--actif');
          autre.setAttribute('aria-pressed', 'false');
        });

        b.classList.add('eko-poc__carte--actif');
        b.setAttribute('aria-pressed', 'true');
        surChoix(i, c);
      });

      piste.appendChild(b);
    });

    l.appendChild(piste);

    return l;
  }

  /**
   * Une ligne en liste déroulante — la même que celle de la sous-traitance.
   *
   * Une matière n'est pas un format : on ne la compare pas à l'œil, on la
   * choisit par son nom. Dix vinyles en cartes occupent trois rangées et
   * noient les critères suivants ; une liste tient sur une ligne.
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
      op.value = o.valeur;
      op.textContent = o.nom + (o.note ? ' — ' + o.note : '');
      op.selected = String(o.valeur) === String(choisi);
      sel.appendChild(op);
    });

    sel.addEventListener('change', function () {
      surChoix(sel.value);
    });

    l.appendChild(sel);

    return l;
  }

  /**
   * La ligne des cotes : des champs, parce qu'une largeur est continue.
   *
   * Groupés sur UNE ligne : largeur et hauteur se lisent ensemble, et deux
   * lignes séparées casseraient le rythme des critères qui suivent.
   */
  function ligneCotes(titre, champs, surSaisie) {
    var l = document.createElement('div');
    l.className = 'eko-poc__ligne eko-poc__ligne--cotes';

    var tete = document.createElement('div');
    tete.className = 'eko-poc__ligne-tete';

    var t = document.createElement('h3');
    t.className = 'eko-poc__critere';
    t.textContent = titre;
    tete.appendChild(t);
    l.appendChild(tete);

    var piste = document.createElement('div');
    piste.className = 'eko-poc__cotes';

    champs.forEach(function (v) {
      var enveloppe = document.createElement('label');
      enveloppe.className = 'eko-poc__cote';

      var nom = document.createElement('span');
      nom.className = 'eko-poc__cote-nom';
      nom.textContent = v.label + (v.unit ? ' (' + v.unit + ')' : '');
      enveloppe.appendChild(nom);

      var i = document.createElement('input');
      i.type = 'number';
      i.className = 'eko-poc__cote-champ';
      i.min = '1';
      i.value = v['default'] != null ? String(v['default']) : '';
      i.dataset.cle = v.key;
      i.addEventListener('input', surSaisie);
      i.addEventListener('change', surSaisie);
      enveloppe.appendChild(i);

      piste.appendChild(enveloppe);
    });

    l.appendChild(piste);

    return l;
  }

  /* ─── Le récapitulatif ───────────────────────────────────────────────── */

  function rendreResume(r, zone, d, choix, modele, prestations, choixPrestations) {
    zone.innerHTML = '';

    var h = document.createElement('h3');
    h.className = 'eko-poc__resume-titre';
    h.textContent = txt(r, 'recap', 'Votre commande');
    zone.appendChild(h);

    var p = document.createElement('p');
    p.className = 'eko-poc__resume-prix';
    p.innerHTML =
      '<span class="eko-poc__resume-total">' + echapper(d.total_price_texte || '') + '</span>' +
      '<span class="eko-poc__resume-regime">' + echapper(txt(r, d.hors_taxe ? 'ht' : 'ttc', '')) + '</span>';
    zone.appendChild(p);

    var u = document.createElement('p');
    u.className = 'eko-poc__resume-unite';
    u.textContent = (d.unit_price_texte || '') + ' ' + txt(r, 'unite', 'l’unité');
    zone.appendChild(u);

    if (d.public_texte) {
      var pub = document.createElement('p');
      pub.className = 'eko-poc__resume-public';
      pub.textContent = txt(r, 'prixPublic', 'Prix public TTC') + ' : ' + d.public_texte;
      zone.appendChild(pub);
    }

    if (d.mention_prix) {
      var m = document.createElement('p');
      m.className = 'eko-poc__resume-mention';
      m.textContent = d.mention_prix;
      zone.appendChild(m);
    }

    // Ce que le visiteur a choisi, en toutes lettres.
    var ul = document.createElement('ul');
    ul.className = 'eko-poc__resume-liste';

    Object.keys(choix).forEach(function (cle) {
      var def = modele[cle];

      if (!def) {
        return;
      }

      var valeur = choix[cle];

      if (def.type === 'boolean') {
        valeur = String(valeur) === '1' ? txt(r, 'oui', 'Oui') : txt(r, 'non', 'Non');
      } else if (def.options) {
        (def.options || []).forEach(function (o) {
          if (String(o.value) === String(valeur)) {
            valeur = o.label;
          }
        });
      } else if (def.unit) {
        valeur = valeur + ' ' + def.unit;
      }

      var li = document.createElement('li');
      li.textContent = def.label + ' : ' + valeur;
      ul.appendChild(li);
    });

    if (d.date_texte) {
      var liD = document.createElement('li');
      liD.className = 'eko-poc__resume-date';
      liD.textContent = txt(r, 'livree', 'Livraison estimée') + ' : ' + d.date_texte;
      ul.appendChild(liD);
    }

    if (d.note_delai) {
      var liN = document.createElement('li');
      liN.textContent = d.note_delai;
      ul.appendChild(liN);
    }

    // ─── LES PRESTATIONS RETENUES, EN TOUTES LETTRES ────────────────────
    //
    // Elles étaient absentes de ce récapitulatif alors qu'elles sont FACTURÉES :
    // choisir « BAT numérique » ajoutait 18 € au total sans qu'une seule ligne
    // ne dise pourquoi. Le visiteur voyait un prix grimper de dix-huit euros
    // entre deux clics, sans justification à l'écran.
    //
    // La sous-traitance les liste depuis toujours, après la date de livraison.
    // On reprend le même endroit et la même forme — « critère : valeur » — pour
    // que les deux configurateurs racontent la même commande.
    (prestations || []).forEach(function (sv) {
      var retenu = choixPrestations ? choixPrestations[sv.cle] : null;

      if (!retenu) {
        return;
      }

      var liP = document.createElement('li');
      liP.textContent = sv.label + ' : ' + retenu;
      ul.appendChild(liP);
    });

    zone.appendChild(ul);

    // Les avertissements du moteur : contractuels, ils se lisent AVANT l'achat.
    if (Array.isArray(d.warnings) && d.warnings.length) {
      var av = document.createElement('ul');
      av.className = 'eko-poc__resume-avertissements';
      d.warnings.forEach(function (w) {
        var li = document.createElement('li');
        li.textContent = String(w);
        av.appendChild(li);
      });
      zone.appendChild(av);
    }

    // Le bouton d'achat, DANS le récapitulatif — comme sur l'autre chemin.
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'eko-poc__resume-panier';
    b.textContent = txt(r, 'ajouter', 'Ajouter au panier');
    b.addEventListener('click', function () {
      var natif = document.querySelector('[data-button-action="add-to-cart"]');

      if (natif) {
        natif.click();
      }
    });
    zone.appendChild(b);

    // ─── Le gabarit, aux cotes saisies ────────────────────────────────
    //
    // Un plan de travail ne se déduit d'aucune liste ici : il se calcule à
    // partir de ce que le client vient de taper. Le lien porte donc les cotes,
    // et le PDF est produit à la demande.
    var lg = parseFloat(choix.width);
    var ht = parseFloat(choix.height);

    if (r.dataset.urlGabarit && lg > 0 && ht > 0) {
      var g = document.createElement('a');
      g.className = 'eko-poc__resume-gabarit';
      g.href = r.dataset.urlGabarit
        + '&id_product=' + encodeURIComponent(r.dataset.idProduct)
        + '&largeur=' + encodeURIComponent(lg)
        + '&hauteur=' + encodeURIComponent(ht);
      g.rel = 'nofollow';
      g.textContent = txt(r, 'gabarit', 'Télécharger le gabarit');
      zone.appendChild(g);

      var note = document.createElement('p');
      note.className = 'eko-poc__resume-gabarit-note';
      note.textContent = txt(r, 'gabaritNote', '');

      // ⚠️ LE SEUIL SE DIT AVANT LE TÉLÉCHARGEMENT, pas seulement sur le PDF.
      //
      // Le format PDF plafonne à 200 pouces par côté : au-delà, le gabarit est
      // réduit. L'écrire sur le document est nécessaire mais tardif — un client
      // qui commande une bâche de six mètres doit le savoir en cliquant, pas en
      // ouvrant le fichier.
      var seuil = parseFloat(r.dataset.gabaritSeuil || '0');
      var echelle = seuil > 0 ? echellePour(Math.max(lg, ht), seuil, r) : 1;

      if (echelle > 1) {
        note.className = 'eko-poc__resume-gabarit-note eko-poc__resume-gabarit-note--reduit';
        note.textContent = (txt(r, 'gabaritReduit', '') || '')
          .replace('%1$s', String(Math.round(seuil)))
          .replace('%2$s', String(echelle));
      } else if (seuil > 0) {
        note.textContent += ' ' + txt(r, 'gabaritSeuilNote', '');
      }

      zone.appendChild(note);
    }

    if (Array.isArray(d.reassurances) && d.reassurances.length) {
      var ur = document.createElement('ul');
      ur.className = 'eko-poc__reassure';
      d.reassurances.forEach(function (x) {
        var li = document.createElement('li');
        li.textContent = typeof x === 'string' ? x : (x && (x.nom || x.texte)) || '';

        if (li.textContent) {
          ur.appendChild(li);
        }
      });
      zone.appendChild(ur);
    }
  }

  /* ─── Le moteur ──────────────────────────────────────────────────────── */

  /**
   * La réduction qu'appliquera le serveur, calculée à l'identique.
   *
   * ⚠️ L'échelle est décidée par `Gabarit::echellePour()` en PHP. On ne la
   * recopie pas : la liste des rapports voyage dans `data-gabarit-echelles`,
   * et le seuil dans `data-gabarit-seuil`. Deux tables divergentes
   * annonceraient au client une échelle que le document ne porterait pas.
   */
  function echellePour(max, seuil, r) {
    if (!(max > seuil)) {
      return 1;
    }

    var echelles;

    try {
      echelles = JSON.parse(r.dataset.gabaritEchelles || '[]');
    } catch (e) {
      echelles = [];
    }

    for (var i = 0; i < echelles.length; i++) {
      if (max / echelles[i] <= seuil) {
        return echelles[i];
      }
    }

    return echelles.length ? echelles[echelles.length - 1] : 1;
  }

  var generation = 0;

  function demarrer() {
    var r = racine();

    if (!r || r.dataset.ekoPret === '1') {
      return;
    }

    r.dataset.ekoPret = '1';

    // ⚠️ TOUTE LA MISE EN PAGE TIENT DANS CETTE CLASSE.
    //
    // La feuille partagée s'en sert pour masquer les deux rails latéraux — d'où
    // une page qui passe de 924 à 1 224 px —, les blocs de réassurance du
    // thème, ses mises en avant vides, son « Size Guide » générique et ses
    // commandes natives. Un hook produit ne peut pas atteindre `<body>` ; le
    // navigateur, si.
    //
    // Elle ne se pose QUE sur une fiche pilotée par le module : les autres
    // gardent la mise en page du thème, intacte.
    document.body.classList.add('eko-poc-page');

    var section = deplacerSousLaFiche(r);

    if (section) {
      ancreVersConfigurateur(r, section);
    }

    masquerNatif();

    var zoneEtapes = r.querySelector('.eko-poc__etapes');
    var zoneResume = r.querySelector('.eko-poc__resume');

    // Fiche liée dont le prix est indisponible : on verrouille et on s'arrête.
    // Un formulaire sans champ obtiendrait sinon un prix pour les valeurs par
    // défaut et rouvrirait les boutons.
    if (r.dataset.indisponible === '1') {
      zoneResume.innerHTML =
        '<p class="eko-poc__erreur"><strong>' + echapper(txt(r, 'indispoTitre', '')) + '</strong> '
        + echapper(txt(r, 'indispoTexte', '')) + '</p>';
      commande(false, txt(r, 'echec', ''));

      return;
    }

    var quantite = champQuantite();

    if (!quantite) {
      zoneResume.innerHTML = '<p class="eko-poc__erreur">' + echapper(txt(r, 'sansQuantite', '')) + '</p>';
      commande(false, txt(r, 'sansQuantite', ''));

      return;
    }

    var variables = [];

    try {
      variables = JSON.parse(r.dataset.variables || '[]') || [];
    } catch (e) {
      variables = [];
    }

    // Les prestations de l'imprimeur. Elles ne viennent PAS de l'ERP : ce sont
    // des propriétés du marchand, réglées produit par produit au back-office,
    // et le chemin de sous-traitance les sert depuis toujours. Le configurateur
    // d'atelier, lui, les ignorait — le client ne pouvait donc ni demander un
    // bon à tirer ni commander une création graphique sur un grand format.
    var prestations = [];
    var choixPrestations = {};

    try {
      prestations = JSON.parse(r.dataset.services || '[]') || [];
    } catch (e) {
      prestations = [];
    }

    var modele = {};
    variables.forEach(function (v) { modele[v.key] = v; });

    // L'état courant : ce que le visiteur a choisi.
    var choix = {};
    variables.forEach(function (v) {
      if (v['default'] != null) {
        choix[v.key] = v.type === 'boolean' ? (v['default'] ? '1' : '0') : v['default'];
      } else if (v.type === 'boolean') {
        choix[v.key] = '0';
      }
    });

    var minuteur = null;
    var enCours = null;

    /**
     * Montrer ou cacher les lignes dépendantes d'une case à cocher.
     *
     * Une ligne cachée n'est pas seulement invisible : sa valeur est RETIRÉE
     * de la configuration envoyée au chiffrage. La laisser partirait un choix
     * que le visiteur n'a pas fait.
     */
    function appliquerCascade() {
      zoneEtapes.querySelectorAll('[data-depend-de]').forEach(function (l) {
        var maitre = l.dataset.dependDe;
        var actif = String(choix[maitre]) === '1';

        l.hidden = !actif;

        // La ligne porte sa propre clé : la retrouver par le DOM serait fragile.
        if (!actif && l.dataset.cle) {
          delete choix[l.dataset.cle];
        }
      });
    }

    function differer() {
      appliquerCascade();

      // Le verrou tombe AU GESTE, pas au bout de l'attente : entre la frappe et
      // l'appel, le prix affiché ne décrit plus la configuration.
      commande(false, txt(r, 'attendezPrix', ''));

      if (minuteur) {
        clearTimeout(minuteur);
      }

      minuteur = setTimeout(chiffrer, 400);
    }

    function chiffrer() {
      var moi = ++generation;

      if (enCours) {
        enCours.abort();
      }

      zoneResume.innerHTML = '<p class="eko-poc__attente">' + echapper(txt(r, 'attente', '')) + '</p>';
      commande(false, txt(r, 'attendezPrix', ''));

      var params = new URLSearchParams();
      params.set('ajax', '1');
      params.set('id_product', r.dataset.idProduct);
      params.set('quantity', quantite.value || '1');

      Object.keys(choix).forEach(function (cle) {
        if (choix[cle] !== '' && choix[cle] != null) {
          params.set('variables[' + cle + ']', choix[cle]);
        }
      });

      // Le NOM de l'option seulement, jamais son prix : le supplément est
      // relu des réglages et additionné côté serveur. Un montant venu du
      // navigateur serait un montant que la boutique n'a pas vérifié.
      Object.keys(choixPrestations).forEach(function (cle) {
        if (choixPrestations[cle]) {
          params.set('services[' + cle + ']', choixPrestations[cle]);
        }
      });

      enCours = new AbortController();

      fetch(r.dataset.url + '&' + params.toString(), {
        signal: enCours.signal,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
        .then(function (rep) { return rep.json(); })
        .then(function (d) {
          // ⚠️ `abort()` ne peut rien contre une requête DÉJÀ RÉSOLUE : deux
          // frappes rapprochées peuvent revenir dans le désordre.
          if (moi !== generation) {
            return;
          }

          if (!d || d.ok !== true) {
            zoneResume.innerHTML = '<p class="eko-poc__erreur">'
              + echapper((d && d.message) || txt(r, 'echec', '')) + '</p>';
            commande(false, (d && d.message) || '');

            return;
          }

          rendreResume(r, zoneResume, d, choix, modele, prestations, choixPrestations);
          poserPersonnalisation(d.id_customization);
          commande(true);
        })
        .catch(function (e) {
          if (e.name === 'AbortError' || moi !== generation) {
            return;
          }

          zoneResume.innerHTML = '<p class="eko-poc__erreur">' + echapper(txt(r, 'injoignable', '')) + '</p>';
          commande(false, txt(r, 'injoignable', ''));
        });
    }

    /* ─── Bâtir les lignes ─────────────────────────────────────────────── */

    zoneEtapes.innerHTML = '';

    var cotes = variables.filter(function (v) { return v.type === 'integer' || v.type === 'decimal'; });
    var autres = variables.filter(function (v) { return v.type !== 'integer' && v.type !== 'decimal'; });

    if (cotes.length) {
      zoneEtapes.appendChild(ligneCotes(txt(r, 'dimensions', 'Dimensions'), cotes, function (ev) {
        choix[ev.target.dataset.cle] = ev.target.value;
        differer();
      }));
    }

    autres.forEach(function (v) {
      if (v.type === 'boolean') {
        var actif = String(choix[v.key]) === '1' ? 0 : 1;

        zoneEtapes.appendChild(ligneCartes(
          v.label,
          [{ nom: txt(r, 'oui', 'Oui') }, { nom: txt(r, 'non', 'Non') }],
          actif,
          function (i) {
            choix[v.key] = i === 0 ? '1' : '0';
            differer();
          }
        ));

        return;
      }

      var options = v.options || [];

      if (!options.length) {
        return;
      }

      var entrees = options.map(function (o) {
        // `weight_g_m2` et `thickness_um` viennent de l'ERP : ils disent au
        // client ce qu'il choisit, là où un nom seul ne montre rien.
        var notes = [];

        if (o.weight_g_m2) {
          notes.push(o.weight_g_m2 + ' g/m²');
        }

        if (o.thickness_um) {
          notes.push(o.thickness_um + ' µm');
        }

        return { nom: o.label, note: notes.join(' · '), valeur: o.value };
      });

      var l = ligneListe(v.label, entrees, choix[v.key], function (valeur) {
        choix[v.key] = valeur;
        differer();
      });

      // ⚠️ LA CASCADE. Une liste `material_choice_<X>` ne concerne QUE le cas
      // où l'option booléenne `<X>` est retenue : demander « quel film de
      // lamination ? » à qui n'en veut pas est une question sans objet, et sa
      // réponse partait au chiffrage.
      //
      // La dépendance n'est déclarée nulle part côté ERP — `conditional_display`
      // vaut `null` sur toutes les variables, mesuré. Elle se lit donc dans le
      // NOM, qui suit une convention : `material_choice_lamination` dépend de
      // `lamination`. On ne l'applique QUE si le booléen existe vraiment.
      var prefixe = 'material_choice_';

      if (v.key.indexOf(prefixe) === 0) {
        var maitre = v.key.slice(prefixe.length);

        if (modele[maitre] && modele[maitre].type === 'boolean') {
          l.dataset.dependDe = maitre;
          l.dataset.cle = v.key;
        }
      }

      zoneEtapes.appendChild(l);
    });

    appliquerCascade();

    // ─── Les prestations, après les critères de fabrication ─────────────
    //
    // Elles viennent en dernier parce qu'elles portent sur la commande, pas
    // sur la pièce : on choisit d'abord ce qu'on fait fabriquer, ensuite ce
    // qu'on demande à l'imprimeur par-dessus.
    prestations.forEach(function (sv) {
      if (!sv || !sv.options || !sv.options.length) {
        return;
      }

      // La PREMIÈRE option fait foi, et elle est gratuite par convention du
      // module : démarrer sur une option payante gonflerait le prix d'appel
      // sans que le visiteur l'ait demandé.
      choixPrestations[sv.cle] = sv.options[0].nom;

      // ⚠️ LA MÊME STRUCTURE QUE LA SOUS-TRAITANCE, PAS DES CARTES GÉNÉRIQUES.
      //
      // `ligneCartes()` rendait ici une piste de cartes `.eko-poc__carte` : la
      // description et le prix se retrouvaient concaténés dans une seule ligne
      // de note, et la feuille — qui connaît pourtant `.eko-poc__ligne--prestations`
      // et ses tuiles — n'avait aucune prise. Les deux configurateurs montraient
      // donc deux habillages différents pour le MÊME choix, sur la même boutique.
      //
      // On émet la structure des tuiles, à l'identique : ligne modifiée, grille
      // qui lit `data-combien`, et une tuile par option portant son icône, son
      // nom, sa description et son prix.
      var ligne = document.createElement('div');
      ligne.className = 'eko-poc__ligne eko-poc__ligne--prestations';

      var titre = document.createElement('h3');
      titre.className = 'eko-poc__critere';
      titre.textContent = sv.label + ' :';
      ligne.appendChild(titre);

      var grille = document.createElement('div');
      grille.className = 'eko-poc__prestations';
      // Le CSS lit le NOMBRE d'options plutôt qu'une classe par cas : deux
      // prestations s'étalent, cinq se replient, une sixième ne casse rien.
      grille.dataset.combien = String(sv.options.length);

      sv.options.forEach(function (o, i) {
        var actif = i === 0;

        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'eko-poc__presta' + (actif ? ' eko-poc__presta--actif' : '');
        b.setAttribute('aria-pressed', actif ? 'true' : 'false');

        var corps = dessinPrestation(o.nom)
          + '<span class="eko-poc__presta-nom">' + echapper(o.nom) + '</span>';

        if (o.texte) {
          corps += '<span class="eko-poc__presta-texte">' + echapper(o.texte) + '</span>';
        }

        // « Gratuit » plutôt qu'un « 0,00 € » : un montant nul se lit comme un
        // prix que le site aurait oublié de calculer.
        corps += '<span class="eko-poc__presta-prix">'
          + echapper(o.prix_texte || txt(r, 'gratuit', 'Gratuit'))
          + '</span>';

        b.innerHTML = corps;

        b.addEventListener('click', function () {
          if (choixPrestations[sv.cle] === o.nom) {
            return;
          }

          // La sélection se repeint TOUT DE SUITE, avant l'aller-retour au
          // serveur : sinon la tuile reste éteinte le temps du chiffrage et le
          // visiteur reclique, croyant avoir manqué la cible.
          grille.querySelectorAll('.eko-poc__presta').forEach(function (autre) {
            autre.classList.remove('eko-poc__presta--actif');
            autre.setAttribute('aria-pressed', 'false');
          });

          b.classList.add('eko-poc__presta--actif');
          b.setAttribute('aria-pressed', 'true');

          choixPrestations[sv.cle] = o.nom;
          differer();
        });

        grille.appendChild(b);
      });

      ligne.appendChild(grille);
      zoneEtapes.appendChild(ligne);
    });

    quantite.addEventListener('change', differer);
    quantite.addEventListener('input', differer);

    // Un premier chiffrage à l'ouverture : le visiteur voit un prix pour les
    // valeurs par défaut, sans avoir à toucher quoi que ce soit.
    chiffrer();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', demarrer);
  } else {
    demarrer();
  }

  // Changer la quantité fait re-rendre le bloc produit par PrestaShop : notre
  // bloc est alors remplacé, sans ses écouteurs. Sans cette reprise, le
  // configurateur meurt au premier changement de quantité.
  if (window.prestashop && typeof window.prestashop.on === 'function') {
    window.prestashop.on('updatedProduct', function () {
      var r = racine();

      // Repartir de zéro : le thème vient de remplacer son champ quantité, et
      // nos écouteurs sont partis avec lui.
      if (r) {
        delete r.dataset.ekoPret;
      }

      fermesParNous = [];
      masquerNatif();
      demarrer();
    });
  }
})();
