{**
 * EKO Sync — Imprimerie
 *
 * Fiche technique, guide et gabarits — COMMUN aux deux configurateurs.
 *
 * ⚠️ Ce bloc ne vivait que dans le gabarit de sous-traitance. Le marchand
 * remplissait donc 300 DPI, CMJN, fonds perdus et un guide pour N'IMPORTE
 * QUELLE fiche — le formulaire du back-office ne fait aucune différence — et
 * côté atelier rien n'en ressortait jamais. Saisi, enregistré, invisible.
 *
 * Il vit désormais ici, inclus des deux côtés.
 *
 * Le réceptacle « Spécifications techniques » reste dans le balisage mais n'est
 * rempli que par le configurateur de sous-traitance, dont les cotes dépendent
 * du format choisi dans l'arbre. Vide, il est invisible : on ne le branche pas
 * côté atelier, il n'aurait rien à montrer.
 *
 * Le garde `isset` ci-dessous permet d'inclure ce fichier depuis un gabarit qui
 * n'assigne pas `technique` sans que Smarty ne bronche.
 *}
{if isset($eko.technique)}
{**
 * Fiche technique et gabarits.
 *
 * Ces valeurs ne viennent PAS de l'ERP : elles décrivent ce que l'imprimeur
 * attend d'un fichier, pas ce que le fournisseur fabrique. Elles se règlent
 * donc au back-office, sur la fiche produit, avec des valeurs par défaut qui
 * sont les usages de l'imprimerie — 300 DPI, CMJN, 2 mm de fond perdu.
 *
 * Le bloc ne s'affiche que s'il a quelque chose à dire : un cadre vide sur une
 * fiche produit se lit comme un oubli.
 *}
{if $eko.technique.lignes|@count || $eko.technique.gabarits|@count || $eko.technique.guide}
  <section class="eko-tech">
    <h2 class="eko-tech__titre">
      {l s='Gabarits & instructions' d='Modules.Ekosyncimprimerie.Shop'}
    </h2>

    <div class="eko-tech__colonnes">
      {if $eko.technique.lignes|@count}
        <div class="eko-tech__bloc">
          <h3 class="eko-tech__sous-titre">{l s='Fiche technique' d='Modules.Ekosyncimprimerie.Shop'}</h3>
          <dl class="eko-tech__liste">
            {foreach from=$eko.technique.lignes item=t}
              <div class="eko-tech__ligne">
                <dt>{$t.label|escape:'html':'UTF-8'}</dt>
                <dd>{$t.valeur|escape:'html':'UTF-8'}</dd>
              </div>
            {/foreach}
          </dl>
          {**
           * Le lien « Spécifications techniques » vient se poser ici, rempli
           * par le configurateur : les cotes dépendent du FORMAT choisi, et il
           * change à chaque clic. Le réceptacle reste vide — donc invisible —
           * tant qu'aucune configuration n'est faite.
           *}
          <div class="eko-tech__specs"></div>
        </div>
      {/if}

      {**
       * Le guide propre au produit.
       *
       * Il remplace le « Size Guide » du thème, qui sert le MÊME contenu à
       * toutes les fiches. Sur une imprimerie, un guide des tailles n'a de sens
       * que sur les textiles ; ailleurs il vaut mieux ne rien afficher.
       *
       * Le contenu est saisi au back-office par le marchand : on le rend en
       * HTML, comme une description produit — même confiance, même origine.
       *}
      {if $eko.technique.guide}
        <div class="eko-tech__bloc">
          <h3 class="eko-tech__sous-titre">{$eko.technique.guide.titre|escape:'html':'UTF-8'}</h3>
          <div class="eko-tech__guide">{$eko.technique.guide.contenu nofilter}</div>
        </div>
      {/if}

      {**
       * Les gabarits, repliés au-delà de cinq.
       *
       * Un dépliant en compte trente-quatre : déroulés d'un bloc, ils font
       * trois écrans de liens identiques et noient le reste de la fiche. Les
       * cinq premiers suffisent à montrer ce qui existe ; le bouton dit
       * combien il en reste, pour qu'on sache s'il vaut la peine d'être
       * ouvert.
       *}
      {if $eko.technique.gabarits|@count}
        {$ekoGabTotal = $eko.technique.gabarits|@count}
        <div class="eko-tech__bloc eko-tech__bloc--gabarits{if $ekoGabTotal > 5} eko-gab-repli{/if}">
          <h3 class="eko-tech__sous-titre">{l s='Gabarits' d='Modules.Ekosyncimprimerie.Shop'}</h3>
          <ul class="eko-tech__gabarits">
            {foreach from=$eko.technique.gabarits item=g name=gab}
              <li{if $smarty.foreach.gab.iteration > 5} class="eko-gab-cache"{/if}>
                <span>{$g.nom|escape:'html':'UTF-8'}</span>
                <a href="{$g.url|escape:'html':'UTF-8'}" rel="nofollow" download>
                  {l s='Télécharger le gabarit' d='Modules.Ekosyncimprimerie.Shop'}
                </a>
              </li>
            {/foreach}
          </ul>
          {**
           * ⚠️ `{l s='…%n%…'|replace:…}` NE FONCTIONNE PAS : le modificateur
           * s'applique à la chaîne SOURCE, avant traduction, et le `%n%`
           * ressort tel quel dans la page. `{l}` a son propre mécanisme —
           * `sprintf` — et c'est le seul que l'extracteur de catalogue
           * comprenne aussi.
           *}
          {if $ekoGabTotal > 5}
            {assign var='ekoGabReste' value=$ekoGabTotal - 5}
            {assign var='ekoGabPlus' value={l s='Voir les %n% autres gabarits' sprintf=['%n%' => $ekoGabReste] d='Modules.Ekosyncimprimerie.Shop'}}
            {assign var='ekoGabMoins' value={l s='Voir moins' d='Modules.Ekosyncimprimerie.Shop'}}
            <button type="button" class="eko-gab-plus" data-plus="{$ekoGabPlus|escape:'html':'UTF-8'}" data-moins="{$ekoGabMoins|escape:'html':'UTF-8'}">{$ekoGabPlus|escape:'html':'UTF-8'}</button>
          {/if}
        </div>
      {/if}
    </div>
  </section>
{/if}

{**
 * Le repli des gabarits.
 *
 * Délégué sur le document : le thème re-rend son bloc produit sur
 * `updatedProduct`, et un écouteur posé sur le bouton disparaîtrait avec lui.
 *}
<script>
(function () {
    'use strict';

    if (window.ekoGabaritsBranches) { return; }
    window.ekoGabaritsBranches = true;

    // La fenêtre « données techniques ». Même délégation qu'au panier, pour la
    // même raison : le thème re-rend son bloc produit, et un écouteur posé sur
    // le bouton disparaîtrait avec lui.
    document.addEventListener('click', function (evenement) {
        var ouvrir = evenement.target.closest('.eko-fitech__ouvrir');

        if (ouvrir) {
            var fenetre = document.getElementById(ouvrir.dataset.cible);

            if (fenetre) {
                fenetre.hidden = false;
                document.body.style.overflow = 'hidden';
            }

            return;
        }

        var fermer = evenement.target.closest('.eko-fitech__fermer, .eko-fitech__voile');

        if (fermer) {
            var boite = fermer.closest('.eko-fitech');

            if (boite) {
                boite.hidden = true;
                document.body.style.overflow = '';
            }
        }
    });

    document.addEventListener('keydown', function (evenement) {
        if (evenement.key !== 'Escape') { return; }

        Array.prototype.forEach.call(
            document.querySelectorAll('.eko-fitech:not([hidden])'),
            function (f) { f.hidden = true; }
        );

        document.body.style.overflow = '';
    });

    document.addEventListener('click', function (evenement) {
        var bouton = evenement.target.closest('.eko-gab-plus');

        if (!bouton) { return; }

        var bloc = bouton.closest('.eko-tech__bloc--gabarits');

        if (!bloc) { return; }

        var replie = bloc.classList.toggle('eko-gab-repli');
        bouton.textContent = replie ? bouton.dataset.plus : bouton.dataset.moins;
    });
}());
</script>
{/if}
