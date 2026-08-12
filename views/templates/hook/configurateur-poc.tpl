{**
 * EKO Sync — Imprimerie
 *
 * Le configurateur de sous-traitance.
 *
 * ─── POURQUOI CE GABARIT EST PRESQUE VIDE ──────────────────────────────────
 *
 * Tout le contenu vient de l'ERP : les étapes, leurs libellés, les options
 * encore valides à chaque niveau, la grille des quantités, les délais. Rien de
 * cela n'est connu au moment où PrestaShop rend la page — l'arbre se descend
 * choix par choix, et chaque choix change ce qui suit.
 *
 * Ce gabarit ne pose donc que la COQUE et les textes traduits. Le JavaScript
 * bâtit les lignes au fur et à mesure. Écrire les libellés ici, dans des
 * attributs `data-`, est ce qui les garde traduisibles : un texte écrit dans un
 * fichier JS échappe à `trans()` et reste en français sur une boutique
 * anglaise.
 *}
<div class="eko-poc"
     data-id-product="{$eko.id_product|intval}"
     data-url="{$eko.url|escape:'html':'UTF-8'}"
     data-attente="{l s='Chargement des options…' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-calcul="{l s='Calcul des tarifs…' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-quantite="{l s='Quantité' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-livraison="{l s='Je choisis mon délai' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-offert="{l s='Inclus' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-supplement="{l s='Supplément' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-meilleure="{l s='Meilleure offre' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-bonplan="{l s='Bon plan' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-exemplaires="{l s='ex.' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-detail="{l s='Détail de ma commande' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-ht="{l s='HT' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-ttc="{l s='TTC' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-unite="{l s='l’unité' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-modifier="{l s='Modifier' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-perime="{l s='Ces tarifs n’ont pas été rafraîchis récemment. Confirmez avant de commander.' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-echec="{l s='Options indisponibles pour le moment.' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-attendez="{l s='Choisissez vos options pour obtenir un prix.' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}">

  <h2 class="eko-poc__titre">{l s='Je configure mon produit' d='Modules.Ekosyncimprimerie.Shop'}</h2>

  {* Les lignes de choix, bâties par le JS au fur et à mesure de l'arbre. *}
  <div class="eko-poc__etapes" aria-live="polite"></div>

  {* Le récapitulatif : ce qui a été choisi, et ce que ça coûte. *}
  <aside class="eko-poc__resume" aria-live="polite"></aside>
</div>
