/**
 * EKO Sync — Imprimerie
 *
 * Le configurateur ne calcule RIEN. Il rassemble les choix, demande le prix au
 * serveur, et affiche ce qu'on lui rend. Un calcul ici — même une simple
 * multiplication par la quantité — recréerait une seconde vérité et ferait
 * diverger la boutique des devis de l'ERP.
 *
 * ─── LA QUANTITÉ EST CELLE DE PRESTASHOP ───────────────────────────────────
 *
 * Le module a longtemps rendu son PROPRE champ quantité. C'était un défaut
 * grave, et silencieux : le prix était mémorisé pour la quantité saisie dans
 * ce champ, tandis que le hook de prix lisait celle du thème. Un visiteur qui
 * demandait 500 exemplaires voyait le prix ERP pour 500 dans le bloc du
 * module, et PrestaShop lui facturait le prix CATALOGUE, parce qu'aucun prix
 * n'était mémorisé pour la quantité 1 restée dans le champ du thème.
 *
 * Deux champs, deux vérités — exactement ce que ce module existe pour éviter.
 *
 * Il n'y a donc plus qu'une quantité : celle de PrestaShop. Les trois thèmes
 * livrés avec la boutique (classic, hummingbird, akira) l'exposent sous
 * `#quantity_wanted` / `name="qty"`. Faute de la trouver, le configurateur
 * refuse de chiffrer plutôt que d'annoncer un prix pour une quantité que le
 * panier n'emploiera pas.
 */
(function () {
  'use strict';

  /**
   * Le champ quantité du thème. Jamais un champ à nous.
   *
   * `#quantity_wanted` est l'identifiant employé par les thèmes du cœur ;
   * `name="qty"` est le repli, car c'est ce nom que le formulaire d'ajout au
   * panier poste, quel que soit l'habillage.
   */
  function champQuantite() {
    return (
      document.querySelector('#quantity_wanted') ||
      document.querySelector('.product-add-to-cart input[name="qty"]') ||
      document.querySelector('input[name="qty"]')
    );
  }

  function echapper(t) {
    var d = document.createElement('div');
    d.textContent = t;

    return d.innerHTML;
  }

  function demarrer() {
    var racine = document.querySelector('.eko-configurateur');

    if (!racine || racine.dataset.ekoPret === '1') {
      return;
    }

    var resultat = racine.querySelector('.eko-configurateur__resultat');
    var quantite = champQuantite();

    if (!quantite) {
      // Sans la quantité du thème, tout prix affiché ici serait un prix pour
      // une quantité que le panier n'emploiera pas. Se taire est la seule
      // réponse honnête.
      resultat.innerHTML =
        '<p class="eko-configurateur__erreur">' +
        echapper(racine.dataset.sansQuantite || 'Configurateur indisponible sur ce thème.') +
        '</p>';

      return;
    }

    racine.dataset.ekoPret = '1';

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

      afficher('<p class="eko-configurateur__attente">' + echapper(racine.dataset.attente || 'Calcul du prix…') + '</p>');

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
              (d && d.message ? echapper(d.message) : echapper(racine.dataset.echec || 'Configuration impossible à chiffrer.')) +
              '</p>'
            );

            return;
          }

          var lignes =
            '<p class="eko-configurateur__prix">' +
            '<span class="eko-configurateur__total">' + echapper(String(d.total_price)) + ' €</span>' +
            '<span class="eko-configurateur__unitaire">' +
            echapper(String(d.unit_price)) + ' € ' + echapper(racine.dataset.unite || 'l’unité') +
            '</span></p>';

          if (d.lead_days) {
            lignes +=
              '<p class="eko-configurateur__delai">' +
              echapper(racine.dataset.delai || 'Délai indicatif') + ' : ' +
              echapper(String(d.lead_days)) + ' ' + echapper(racine.dataset.jours || 'jour(s) ouvré(s)') +
              '</p>';
          }

          afficher(lignes);
        })
        .catch(function (e) {
          if (e.name === 'AbortError') {
            return;
          }

          afficher('<p class="eko-configurateur__erreur">' + echapper(racine.dataset.injoignable || 'Le prix n’a pas pu être obtenu.') + '</p>');
        });
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
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', demarrer);
  } else {
    demarrer();
  }

  // Changer la quantité fait re-rendre le bloc produit par PrestaShop : notre
  // bloc est alors remplacé par un neuf, sans ses écouteurs. Sans cette
  // reprise, le configurateur meurt au premier changement de quantité — et
  // c'est précisément le geste qui compte.
  if (window.prestashop && typeof window.prestashop.on === 'function') {
    window.prestashop.on('updatedProduct', demarrer);
  }
})();
