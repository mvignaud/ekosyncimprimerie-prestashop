/**
 * EKO Sync — Imprimerie
 *
 * L'éditeur en liste du back-office.
 *
 * ─── CE QU'IL REMPLACE, ET CE QU'IL NE REMPLACE PAS ────────────────────────
 *
 * Les réassurances, les prestations et les ventes phares se saisissaient dans
 * une zone de texte, une ligne par entrée : « Libellé|valeur ». C'est rapide à
 * taper quand on connaît la syntaxe, et opaque quand on ne la connaît pas — le
 * séparateur `|` ne s'invente pas, et rien ne dit quelles icônes existent.
 *
 * Cet éditeur pose une ligne par entrée, avec ses champs. Il n'invente PAS un
 * nouveau format de stockage : il écrit dans la même zone de texte, qui reste
 * la source. Le script échoue ou ne se charge pas ? La zone de texte est encore
 * là, éditable à la main, et rien n'est perdu.
 */
(function () {
  'use strict';

  function champ(type, valeur, classe) {
    var e = document.createElement(type === 'select' ? 'select' : 'input');

    if (type !== 'select') {
      e.type = type;
      e.value = valeur;
    }

    e.className = 'form-control ' + classe;

    return e;
  }

  function editeur(zone) {
    var icones = (zone.dataset.icones || '').split(',').filter(Boolean);
    var urlDepot = zone.dataset.depot || '';
    // Un TROISIÈME champ, quand la saisie en attend un — la description d'une
    // prestation. Vide, l'éditeur garde exactement son comportement à deux
    // champs : c'est ce qui permet de l'ajouter sans toucher aux réassurances
    // ni aux ventes phares, qui n'en ont pas besoin.
    var libelleTroisieme = zone.dataset.libelleTroisieme || '';

    var boite = document.createElement('div');
    boite.className = 'eko-liste';

    var lignes = document.createElement('div');
    lignes.className = 'eko-liste__lignes';
    boite.appendChild(lignes);

    /**
     * Recompose la zone de texte à partir des lignes : elle reste la source.
     *
     * ⚠️ ET ON PRÉVIENT LA PAGE. Écrire dans `value` par script ne déclenche
     * AUCUN événement — c'est la règle du DOM, et elle se paie cher ici : la
     * fiche produit de PrestaShop n'active « Enregistrer et publier » qu'après
     * avoir vu le formulaire changer. Une saisie faite dans cet éditeur passait
     * donc totalement inaperçue, et le bouton restait grisé, sans rien dire.
     *
     * `input` pour ce qui écoute la frappe, `change` pour ce qui écoute la
     * validation d'un champ : les deux, parce qu'on ne sait pas lequel la page
     * surveille — et qu'un événement de trop ne coûte rien.
     */
    function ecrire() {
      var sortie = [];

      lignes.querySelectorAll('.eko-liste__ligne').forEach(function (l) {
        var nom = l.querySelector('.eko-liste__nom').value.trim();
        var val = l.querySelector('.eko-liste__valeur').value.trim();
        var sup = l.querySelector('.eko-liste__extra');
        var txt = sup ? sup.value.trim() : '';

        if (nom === '') {
          return;
        }

        // On n'écrit que les champs RENSEIGNÉS, en s'arrêtant au dernier. Une
        // ligne « Non|0| » traînerait une barre verticale inutile que le
        // marchand relirait comme une erreur de sa part.
        if (txt !== '') {
          sortie.push(nom + '|' + (val === '' ? '0' : val) + '|' + txt);
        } else {
          sortie.push(val === '' ? nom : nom + '|' + val);
        }
      });

      var avant = zone.value;
      zone.value = sortie.join('\n');

      if (zone.value === avant) {
        return;
      }

      ['input', 'change'].forEach(function (nom) {
        zone.dispatchEvent(new Event(nom, { bubbles: true }));
      });
    }

    function apercu(l, valeur) {
      var vue = l.querySelector('.eko-liste__apercu');

      vue.innerHTML = '';

      if (/^(\/|https?:)/.test(valeur)) {
        var img = document.createElement('img');
        img.src = valeur;
        img.alt = '';
        vue.appendChild(img);
      } else if (valeur !== '') {
        vue.textContent = valeur;
      }
    }

    function ajouter(nom, valeur, extra) {
      var l = document.createElement('div');
      l.className = 'eko-liste__ligne';

      var texte = champ('text', nom, 'eko-liste__nom');
      texte.placeholder = zone.dataset.placeholderNom || '';
      l.appendChild(texte);

      // Le champ de valeur reste un texte libre : il porte tantôt un mot-clé
      // d'icône, tantôt un chemin d'image, tantôt un prix. Une liste fermée
      // interdirait le troisième cas sans rien gagner.
      var val = champ('text', valeur, 'eko-liste__valeur');
      val.placeholder = zone.dataset.placeholderValeur || '';
      l.appendChild(val);

      if (icones.length) {
        var choix = champ('select', '', 'eko-liste__icones');
        var vide = document.createElement('option');
        vide.value = '';
        vide.textContent = zone.dataset.libelleIcone || '…';
        choix.appendChild(vide);

        icones.forEach(function (i) {
          var o = document.createElement('option');
          o.value = i;
          o.textContent = i;
          choix.appendChild(o);
        });

        choix.addEventListener('change', function () {
          if (choix.value !== '') {
            val.value = choix.value;
            choix.value = '';
            ecrire();
            apercu(l, val.value);
          }
        });

        l.appendChild(choix);
      }

      var sup = null;

      if (libelleTroisieme !== '') {
        sup = champ('text', extra || '', 'eko-liste__extra');
        sup.placeholder = libelleTroisieme;
        l.appendChild(sup);
      }

      var vue = document.createElement('span');
      vue.className = 'eko-liste__apercu';
      l.appendChild(vue);

      if (urlDepot) {
        var etiquette = document.createElement('label');
        etiquette.className = 'eko-liste__depot btn btn-default';
        etiquette.textContent = zone.dataset.libelleDepot || 'SVG';

        var fichier = document.createElement('input');
        fichier.type = 'file';
        // Le filtre du navigateur n'est qu'un confort : le serveur revérifie
        // le TYPE ET LE CONTENU, parce qu'un attribut `accept` se contourne.
        fichier.accept = '.svg,image/svg+xml';
        fichier.hidden = true;

        fichier.addEventListener('change', function () {
          if (!fichier.files.length) {
            return;
          }

          var corps = new FormData();
          corps.append('icone', fichier.files[0]);

          etiquette.classList.add('eko-liste__depot--encours');

          fetch(urlDepot, { method: 'POST', body: corps, credentials: 'same-origin' })
            .then(function (x) { return x.json(); })
            .then(function (d) {
              if (!d || d.ok !== true) {
                throw new Error((d && d.message) || '');
              }

              val.value = d.chemin;
              ecrire();
              apercu(l, d.chemin);
            })
            .catch(function (e) {
              // Le refus se dit SUR la ligne : une alerte en haut de page, sur
              // un formulaire produit long, se perd hors de l'écran.
              vue.textContent = e.message || (zone.dataset.echecDepot || '');
              vue.classList.add('eko-liste__apercu--erreur');
            })
            .then(function () {
              etiquette.classList.remove('eko-liste__depot--encours');
              fichier.value = '';
            });
        });

        etiquette.appendChild(fichier);
        l.appendChild(etiquette);
      }

      var retirer = document.createElement('button');
      retirer.type = 'button';
      retirer.className = 'eko-liste__retirer btn btn-default';
      retirer.setAttribute('aria-label', zone.dataset.libelleRetirer || '');
      retirer.textContent = '×';
      retirer.addEventListener('click', function () {
        l.remove();
        ecrire();
      });
      l.appendChild(retirer);

      [texte, val, sup].filter(Boolean).forEach(function (c) {
        c.addEventListener('input', function () {
          ecrire();
          apercu(l, val.value);
        });
      });

      lignes.appendChild(l);
      apercu(l, valeur);

      return l;
    }

    var plus = document.createElement('button');
    plus.type = 'button';
    plus.className = 'eko-liste__ajouter btn btn-default';
    plus.textContent = '+ ' + (zone.dataset.libelleAjouter || '');
    plus.addEventListener('click', function () {
      ajouter('', '').querySelector('.eko-liste__nom').focus();
    });
    boite.appendChild(plus);

    zone.value.split(/\r?\n/).forEach(function (ligne) {
      ligne = ligne.trim();

      if (ligne === '') {
        return;
      }

      var i = ligne.indexOf('|');

      if (i === -1) {
        ajouter(ligne, '', '');

        return;
      }

      // Découpage en DEUX ou en TROIS selon ce que la saisie attend. Dans les
      // deux cas le DERNIER champ ramasse ce qui reste, barres comprises : un
      // chemin d'image en contient parfois, une description aussi.
      var reste = ligne.slice(i + 1);
      var j = libelleTroisieme === '' ? -1 : reste.indexOf('|');

      ajouter(
        ligne.slice(0, i).trim(),
        (j === -1 ? reste : reste.slice(0, j)).trim(),
        j === -1 ? '' : reste.slice(j + 1).trim()
      );
    });

    // La zone de texte reste dans le formulaire — c'est elle qui est postée —
    // mais hors de vue : deux champs pour une même valeur inviteraient à en
    // modifier un et à s'étonner que l'autre gagne.
    zone.hidden = true;
    zone.parentNode.insertBefore(boite, zone.nextSibling);
  }

  function demarrer() {
    document.querySelectorAll('textarea.eko-bo-liste').forEach(function (z) {
      if (z.dataset.ekoPret !== '1') {
        z.dataset.ekoPret = '1';
        editeur(z);
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', demarrer);
  } else {
    demarrer();
  }
})();
