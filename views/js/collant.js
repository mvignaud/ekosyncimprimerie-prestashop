/**
 * EKO — le récapitulatif qui reste sous les yeux.
 *
 * ─── POURQUOI CE FICHIER EST À PART ────────────────────────────────────────
 *
 * `configurateur-poc.css` rend le récapitulatif collant en grand écran :
 *
 *     .eko-poc__resume { position: sticky; top: var(--eko-collant, 1.5em); }
 *
 * Mais aucune feuille de style ne sait mesurer un en-tête. Or le thème de cette
 * boutique en a un, collant, haut de 141 px : à 1,5 em, le récapitulatif se
 * fige DERRIÈRE lui et disparaît dès qu'on fait défiler. Mesuré sur la fiche
 * textile — le bloc collait à 21 px, l'en-tête couvrait de 0 à 141.
 *
 * Ce script pose `--eko-collant`. Il est chargé par TOUS les configurateurs qui
 * emploient la feuille — imprimerie, objets, textile — parce que le décalage
 * n'a rien de propre à l'un d'eux : c'est une propriété du THÈME. Trois copies
 * auraient donné trois valeurs le jour où l'en-tête change de hauteur.
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

(function () {
  'use strict';

  if (window.ekoCollant) {
    return;
  }

  /**
   * La hauteur de ce qui est collé EN HAUT de la fenêtre, à cet instant.
   *
   * ⚠️ Les trois gardes comptent. Un élément de hauteur nulle ne masque rien ;
   * un élément dont le haut est loin du bord n'est pas collé en haut (c'est
   * peut-être notre propre récapitulatif) ; un élément déjà sorti par le haut
   * ne masque plus rien non plus. Sans elles, la mesure attrapait n'importe
   * quel bloc portant « sticky » dans son nom de classe.
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

  /**
   * Suit l'en-tête et tient `--eko-collant` à jour sur un élément.
   */
  function suivre(cible) {
    if (!cible || cible.dataset.ekoCollant === '1') {
      return;
    }

    cible.dataset.ekoCollant = '1';
    var dernier = -1;

    function mesurer() {
      var h = Math.round(hauteurCollante());

      if (h === dernier) {
        return;
      }

      dernier = h;
      cible.style.setProperty('--eko-collant', (h + 16) + 'px');
    }

    mesurer();
    // L'en-tête n'est souvent collant qu'une fois la page défilée, et il change
    // de hauteur en se réduisant : on remesure au défilement, mais on n'écrit
    // que quand la valeur bouge réellement.
    window.addEventListener('scroll', mesurer, { passive: true });
    window.addEventListener('resize', mesurer);
    setTimeout(mesurer, 600);
  }

  /**
   * Prend en charge tous les configurateurs présents, et ceux qui arrivent.
   *
   * ⚠️ Les configurateurs se dessinent APRÈS le chargement, par leur propre
   * script. Mesurer une seule fois au `DOMContentLoaded` ne trouvait rien : la
   * racine `.eko-poc` n'existait pas encore.
   */
  function brancher() {
    var trouves = document.querySelectorAll('.eko-poc');
    trouves.forEach(suivre);

    return trouves.length > 0;
  }

  window.ekoCollant = { hauteurCollante: hauteurCollante, suivre: suivre, brancher: brancher };

  brancher();
  document.addEventListener('DOMContentLoaded', brancher);

  // ⚠️ L'OBSERVATEUR SE DÉBRANCHE DÈS QU'IL A TROUVÉ. Il ne sert qu'à attendre
  // que le configurateur se dessine, ce qui arrive une fois. Le laisser en
  // place ferait tourner un `querySelectorAll` à CHAQUE mutation de la page —
  // et le configurateur se redessine entièrement à chaque clic du visiteur.
  // Une veille qui coûte plus cher que ce qu'elle guette.
  if (window.MutationObserver) {
    var veille = new MutationObserver(function () {
      if (brancher()) {
        veille.disconnect();
      }
    });

    veille.observe(document.documentElement, { childList: true, subtree: true });

    // Filet : sur une fiche sans configurateur, rien n'arrivera jamais.
    setTimeout(function () { veille.disconnect(); }, 15000);
  }
})();
