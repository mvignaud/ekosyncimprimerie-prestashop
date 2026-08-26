{**
 * EKO Sync — Imprimerie · configurateur d'ATELIER
 *
 * ─── POURQUOI CE GABARIT EST PRESQUE VIDE ──────────────────────────────────
 *
 * Il ne rend qu'une racine et des données. Tout l'écran est bâti par le
 * JavaScript, dans les MÊMES classes que le configurateur de sous-traitance —
 * `eko-poc__ligne`, `eko-poc__carte`, `eko-poc__resume` — et la feuille de
 * style de celui-ci est chargée ici aussi.
 *
 * C'est le seul moyen d'obtenir une cohérence réelle : 1 350 lignes de style
 * déjà écrites, éprouvées sur 84 fiches en production, plutôt qu'une imitation
 * qui divergerait à la première retouche.
 *
 * Ce qui diffère tient à la nature du produit : l'atelier saisit des cotes
 * CONTINUES là où l'autre descend un arbre de choix discrets. Les dimensions
 * sont donc des champs, et les matières des cartes — mêmes cartes.
 *
 * ⚠️ LES TEXTES PASSENT PAR ICI. Un texte écrit dans le fichier JavaScript
 * échappe à `trans()` et reste en français sur une boutique anglaise.
 *}
<div class="eko-poc eko-poc--atelier"
     data-id-product="{$eko.id_product|intval}"
     data-url="{$eko.url|escape:'html':'UTF-8'}"
     data-url-gabarit="{$eko.url_gabarit|escape:'html':'UTF-8'}"
     data-gabarit="{l s='Télécharger le gabarit' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-gabarit-note="{l s='Plan de travail aux cotes que vous avez saisies, fond perdu et zone de sécurité compris.' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-gabarit-seuil="{$eko.gabarit_seuil_mm|intval}"
     data-gabarit-echelles="{$eko.gabarit_echelles|@json_encode|escape:'html':'UTF-8'}"
     {* Deux textes : l'un rappelle la règle en toutes circonstances, l'autre
        n'apparaît que lorsque les cotes saisies la déclenchent vraiment. *}
     data-gabarit-seuil-note="{l s='Au-delà de %s mm, le gabarit est fourni à l’échelle — elle est écrite sur le document.' sprintf=[$eko.gabarit_seuil_mm|intval] d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-gabarit-reduit="{l s='Vos cotes dépassent %1$s mm : ce gabarit sera fourni à l’échelle 1:%2$s.' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     {if $eko.indisponible}data-indisponible="1"{/if}
     data-variables="{$eko.variables|@json_encode|escape:'html':'UTF-8'}"
     {* Les prestations de l'imprimeur — bon a tirer, creation graphique.
        Servies des le rendu : le client doit pouvoir choisir avant qu'un prix
        existe. *}
     data-services="{$eko.services|@json_encode|escape:'html':'UTF-8'}"
     data-gratuit="{l s='Gratuit' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-je-configure="{l s='Je configure mon produit' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-dimensions="{l s='Dimensions' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-oui="{l s='Oui' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-non="{l s='Non' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-recap="{l s='Votre commande' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-attente="{l s='Calcul du prix…' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-unite="{l s='l’unité' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-ht="{l s='HT' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-ttc="{l s='TTC' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-livree="{l s='Livraison estimée' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-prix-public="{l s='Prix public TTC' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-ajouter="{l s='Ajouter au panier' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-echec="{l s='Configuration impossible à chiffrer.' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-injoignable="{l s='Le prix n’a pas pu être obtenu.' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-sans-quantite="{l s='Ce thème n’expose pas de champ quantité : le prix ne peut pas être garanti.' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-attendez-prix="{l s='Le prix de cette configuration est en cours de calcul.' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-indispo-titre="{l s='Ce produit se chiffre sur mesure.' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-indispo-texte="{l s='Son configurateur est momentanément indisponible : nous ne pouvons pas afficher de prix fiable pour l’instant. Contactez-nous pour un devis, ou revenez dans quelques minutes.' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}">

  <h2 class="eko-poc__titre">{l s='Je configure mon produit' d='Modules.Ekosyncimprimerie.Shop'}</h2>

  {* Les lignes de critères, bâties par le JS depuis `data-variables`. *}
  <div class="eko-poc__etapes" aria-live="polite"></div>

  {* Le récapitulatif : ce qui a été choisi, et ce que ça coûte. *}
  <aside class="eko-poc__resume" aria-live="polite"></aside>
</div>

{include file='module:ekosyncimprimerie/views/templates/hook/_technique.tpl'}
