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

  /**
   * Le bouton d'ajout au panier du thème.
   *
   * `[data-button-action="add-to-cart"]` est une convention du cœur, respectée
   * par tous les thèmes qui héritent du formulaire produit standard — c'est
   * l'attribut que PrestaShop lui-même cible dans son propre JavaScript.
   */
  function boutonPanier() {
    return document.querySelector('[data-button-action="add-to-cart"]');
  }

  /**
   * Interdire la commande tant que le prix n'est pas connu.
   *
   * ─── Pourquoi c'est indispensable ──────────────────────────────────────
   *
   * Le prix est mémorisé pour le COUPLE (configuration, quantité). Changer la
   * quantité invalide donc le prix connu jusqu'à ce que le nouveau chiffrage
   * revienne — quelques centaines de millisecondes.
   *
   * Dans cette fenêtre, le hook de prix ne trouve rien, se retire, et
   * PrestaShop applique son prix CATALOGUE. Un client qui saisit sa quantité
   * puis clique aussitôt commande donc au mauvais prix, sans que rien ne le
   * signale — ni à lui, ni au marchand.
   *
   * Se taire n'était pas suffisant : il faut aussi empêcher.
   */
  function commande(autorisee, motif) {
    var b = boutonPanier();

    if (!b) {
      return;
    }

    b.disabled = !autorisee;

    if (autorisee) {
      b.removeAttribute('aria-disabled');
      b.removeAttribute('title');
    } else {
      b.setAttribute('aria-disabled', 'true');
      b.setAttribute('title', motif || '');
    }
  }

  /**
   * Masquer le prix que le thème affiche de son côté.
   *
   * Deux prix sur un écran, c'est un prix de trop : le bloc natif montre le
   * prix catalogue tant que l'ERP n'a pas répondu, et le thème le re-rend à
   * chaque changement de quantité — plus vite que notre chiffrage. Le visiteur
   * voit alors deux montants qui se contredisent.
   *
   * `.product-prices` est le conteneur du formulaire produit du cœur. Sur un
   * thème qui ne l'emploie pas, rien n'est masqué et rien ne casse : le
   * configurateur reste la source du prix, le bloc natif redevient seulement
   * bavard.
   */
  function masquerPrixNatif() {
    var bloc = document.querySelector('.product-prices');

    if (bloc) {
      bloc.style.display = 'none';
    }
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

      // Sans quantité fiable, aucun prix ne peut être garanti : on ferme la
      // commande plutôt que de laisser passer un prix catalogue.
      commande(false, racine.dataset.sansQuantite || '');

      return;
    }

    racine.dataset.ekoPret = '1';

    // Le thème vient peut-être de re-rendre son bloc : on le remasque, et on
    // reverrouille la commande jusqu'au prochain prix connu.
    masquerPrixNatif();
    commande(false, racine.dataset.attendezPrix || '');

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

      // Le prix mémorisé ne vaut plus pour la nouvelle quantité : on referme
      // la commande AVANT de partir, et non au retour. L'inverse laisserait le
      // bouton ouvert pendant tout l'aller-retour.
      commande(false, racine.dataset.attendezPrix || '');

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

            commande(false, (d && d.message) ? d.message : '');

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

          // Le seul endroit qui rouvre la commande : un prix vient d'être
          // rendu ET mémorisé côté serveur pour ce couple exact.
          commande(true);
        })
        .catch(function (e) {
          if (e.name === 'AbortError') {
            return;
          }

          afficher('<p class="eko-configurateur__erreur">' + echapper(racine.dataset.injoignable || 'Le prix n’a pas pu être obtenu.') + '</p>');
          commande(false, racine.dataset.injoignable || '');
        });
    }

    /**
     * Le visiteur tape ; on attend qu'il s'arrête avant d'appeler.
     *
     * ⚠️ Le verrou tombe ICI, au geste, et NON dans `chiffrer()`.
     *
     * L'attente de 400 ms ne doit retarder que l'appel réseau. La poser aussi
     * devant le verrou rouvrait le trou qu'on ferme : dès la première frappe,
     * le prix mémorisé ne vaut plus pour la nouvelle quantité, et le bouton
     * restait pourtant cliquable pendant ces 400 ms — plus le temps de
     * l'aller-retour. Mesuré : le bouton était encore ouvert juste après le
     * changement de quantité, avec l'ancien prix affiché à côté.
     */
    function differer() {
      commande(false, racine.dataset.attendezPrix || '');
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
    window.prestashop.on('updatedProduct', function () {
      // Le thème peut remplacer SON bloc prix sans toucher au nôtre. Le garde
      // d'initialisation sortirait alors aussitôt, et le prix natif
      // réapparaîtrait — avec le prix catalogue. On remasque d'abord, on
      // réinitialise ensuite.
      masquerPrixNatif();
      demarrer();
    });
  }
})();
