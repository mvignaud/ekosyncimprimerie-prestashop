{**
 * EKO Sync — Imprimerie
 *
 * Le configurateur de la fiche produit.
 *
 * Les champs viennent de l'ERP : c'est lui qui sait ce qu'un produit accepte.
 * Rien n'est écrit en dur ici — ajouter une option au produit dans l'atelier la
 * fait apparaître sur la boutique sans toucher à ce fichier.
 *
 * ─── PAS DE CHAMP QUANTITÉ ICI ─────────────────────────────────────────────
 *
 * La quantité est celle de PrestaShop, jamais une seconde saisie. Un champ de
 * plus créerait deux vérités : le prix serait mémorisé pour la quantité de ce
 * bloc, et le panier facturerait celle du thème. Le JS s'accroche donc au
 * champ du thème (`#quantity_wanted`).
 *
 * ─── LES TEXTES DU JS PASSENT PAR ICI ──────────────────────────────────────
 *
 * Un texte écrit dans le fichier JavaScript échappe à `trans()` et reste en
 * français sur une boutique anglaise. Les messages du configurateur sont donc
 * traduits ici et transmis en attributs `data-`.
 *}
<div class="eko-configurateur"
     data-id-product="{$eko.id_product|intval}"
     data-url="{$eko.url|escape:'html':'UTF-8'}"
     data-attente="{l s='Calcul du prix…' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-unite="{l s='l’unité' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-delai="{l s='Délai indicatif' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-jours="{l s='jour(s) ouvré(s)' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-echec="{l s='Configuration impossible à chiffrer.' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-injoignable="{l s='Le prix n’a pas pu être obtenu.' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-sans-quantite="{l s='Ce thème n’expose pas de champ quantité : le prix ne peut pas être garanti.' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-attendez-prix="{l s='Le prix de cette configuration est en cours de calcul.' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}">
  <h3 class="eko-configurateur__titre">{l s='Configurez votre produit' d='Modules.Ekosyncimprimerie.Shop'}</h3>

  <div class="eko-configurateur__champs">
    {foreach from=$eko.variables item=v}
      <div class="form-group eko-configurateur__champ">
        <label class="form-control-label" for="eko-{$v.key|escape:'html':'UTF-8'}">
          {$v.label|escape:'html':'UTF-8'}
          {if $v.required}<span class="eko-configurateur__requis" aria-hidden="true">*</span>{/if}
          {if $v.unit}<span class="eko-configurateur__unite">({$v.unit|escape:'html':'UTF-8'})</span>{/if}
        </label>

        {if $v.options}
          <select class="form-control eko-configurateur__saisie"
                  id="eko-{$v.key|escape:'html':'UTF-8'}"
                  data-cle="{$v.key|escape:'html':'UTF-8'}">
            {if !$v.required}<option value="">{l s='— au choix —' d='Modules.Ekosyncimprimerie.Shop'}</option>{/if}
            {foreach from=$v.options item=o}
              <option value="{$o.value|escape:'html':'UTF-8'}"
                      {if $v.default == $o.value}selected{/if}>{$o.label|escape:'html':'UTF-8'}</option>
            {/foreach}
          </select>
        {elseif $v.type == 'integer' || $v.type == 'decimal'}
          <input type="number" class="form-control eko-configurateur__saisie"
                 id="eko-{$v.key|escape:'html':'UTF-8'}"
                 data-cle="{$v.key|escape:'html':'UTF-8'}"
                 value="{$v.default|escape:'html':'UTF-8'}"
                 {if $v.type == 'decimal'}step="0.01"{/if}
                 min="0">
        {else}
          <input type="text" class="form-control eko-configurateur__saisie"
                 id="eko-{$v.key|escape:'html':'UTF-8'}"
                 data-cle="{$v.key|escape:'html':'UTF-8'}"
                 value="{$v.default|escape:'html':'UTF-8'}">
        {/if}

        {if $v.help}<p class="help-block">{$v.help|escape:'html':'UTF-8'}</p>{/if}
      </div>
    {/foreach}
  </div>

  {* Le prix ne s'affiche qu'une fois chiffré : rien ici ne calcule quoi que ce
     soit, et un prix affiché avant réponse serait un prix inventé. *}
  <div class="eko-configurateur__resultat" aria-live="polite">
    <p class="eko-configurateur__attente">{l s='Choisissez vos options pour obtenir un prix.' d='Modules.Ekosyncimprimerie.Shop'}</p>
  </div>

  <p class="eko-configurateur__note">
    {l s='Le prix s’applique à la quantité choisie ci-dessous.' d='Modules.Ekosyncimprimerie.Shop'}
  </p>
</div>
