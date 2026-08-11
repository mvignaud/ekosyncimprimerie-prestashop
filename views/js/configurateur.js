/**
 * EKO Sync — Imprimerie
 *
 * Le configurateur ne calcule RIEN. Il rassemble les choix, demande le prix au
 * serveur, et affiche ce qu'on lui rend. Un calcul ici — même une simple
 * multiplication par la quantité — recréerait une seconde vérité et ferait
 * diverger la boutique des devis de l'ERP.
 */
(function () {
  'use strict';

  var racine = document.querySelector('.eko-configurateur');

  if (!racine) {
    return;
  }

  var resultat = racine.querySelector('.eko-configurateur__resultat');
  var quantite = racine.querySelector('#eko-quantite');
  var enCours = null;
  var minuteur = null;

  /** Les choix du visiteur, tels qu'ils partent à l'ERP. */
  function choix() {
    var v = {};

    racine.querySelectorAll('.eko-configurateur__saisie').forEach(function (champ) {
      var valeur = champ.value;

      // Un champ laissé vide n'est pas « zéro » : on ne l'envoie pas, et
      // l'ERP appliquera son défaut ou dira ce qui manque.
      if (valeur !== '' && valeur !== null) {
        v[champ.dataset.cle] = valeur;
      }
    });

    return v;
  }

  function afficher(html) {
    resultat.innerHTML = html;
  }

  function chiffrer() {
    // Une requête chasse la précédente : sans cela, deux réponses arrivées
    // dans le désordre afficheraient le prix de l'avant-dernier choix.
    if (enCours) {
      enCours.abort();
    }

    afficher('<p class="eko-configurateur__attente">Calcul du prix…</p>');

    var params = new URLSearchParams();
    params.set('ajax', '1');
    params.set('id_product', racine.dataset.idProduct);
    params.set('quantity', quantite.value || '1');

    var v = choix();
    Object.keys(v).forEach(function (cle) {
      params.set('variables[' + cle + ']', v[cle]);
    });

    enCours = new AbortController();

    fetch(racine.dataset.url + '&' + params.toString(), {
      signal: enCours.signal,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || d.ok !== true) {
          // Le refus de l'ERP est une information : on le montre plutôt que
          // de laisser le visiteur devant un prix figé qui ne le concerne plus.
          afficher(
            '<p class="eko-configurateur__erreur">' +
            (d && d.message ? echapper(d.message) : 'Configuration impossible à chiffrer.') +
            '</p>'
          );
          return;
        }

        var lignes =
          '<p class="eko-configurateur__prix">' +
          '<span class="eko-configurateur__total">' + echapper(String(d.total_price)) + ' €</span>' +
          '<span class="eko-configurateur__unitaire">' +
          echapper(String(d.unit_price)) + ' € l’unité' +
          '</span></p>';

        if (d.lead_days) {
          lignes +=
            '<p class="eko-configurateur__delai">Délai indicatif : ' +
            echapper(String(d.lead_days)) + ' jour(s) ouvré(s)</p>';
        }

        afficher(lignes);
      })
      .catch(function (e) {
        if (e.name === 'AbortError') {
          return;
        }

        afficher('<p class="eko-configurateur__erreur">Le prix n’a pas pu être obtenu.</p>');
      });
  }

  function echapper(t) {
    var d = document.createElement('div');
    d.textContent = t;

    return d.innerHTML;
  }

  /** Le visiteur tape ; on attend qu'il s'arrête avant d'appeler. */
  function differer() {
    clearTimeout(minuteur);
    minuteur = setTimeout(chiffrer, 400);
  }

  racine.querySelectorAll('.eko-configurateur__saisie').forEach(function (champ) {
    champ.addEventListener('change', differer);
    champ.addEventListener('input', differer);
  });

  quantite.addEventListener('change', differer);
  quantite.addEventListener('input', differer);

  // Un premier chiffrage à l'ouverture : le visiteur voit un prix pour les
  // valeurs par défaut, sans avoir à toucher quoi que ce soit.
  chiffrer();
})();
