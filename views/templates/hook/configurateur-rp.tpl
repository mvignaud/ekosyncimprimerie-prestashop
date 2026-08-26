{**
 * EKO Sync — Imprimerie
 *
 * Le configurateur du second sous-traitant.
 *
 * ─── POURQUOI CE GABARIT EST PRESQUE VIDE ──────────────────────────────────
 *
 * Tout le contenu vient de l'ERP : les champs, leurs libellés, leurs valeurs,
 * la grille des quantités, les délais. Rien de cela n'est connu au moment où
 * PrestaShop rend la page.
 *
 * Ce gabarit ne pose donc que la COQUE et les textes traduits. Écrire les
 * libellés ici, dans des attributs `data-`, est ce qui les garde traduisibles :
 * un texte écrit dans un fichier JS échappe à `trans()` et reste en français
 * sur une boutique anglaise.
 *
 * ─── CE QUI LE DISTINGUE DE SON JUMEAU ─────────────────────────────────────
 *
 * `data-mode="formulaire"`. Le même script sert les deux sous-traitants : l'un
 * descend un arbre de choix, l'autre remplit un formulaire de champs
 * indépendants. Cet attribut est le seul endroit où la différence est écrite.
 *
 * Et TROIS INTITULÉS DE DÉLAI de plus. Ce fournisseur ne nomme pas ses délais
 * « J+3 » mais « urgence », « express », « standard ». Sans ces trois lignes,
 * ils s'afficheraient bruts, en minuscules et en français, sur une boutique
 * anglaise.
 *
 * Pas de `data-url-specs` : les cotes de fabrication n'existent que chez le
 * premier sous-traitant, et le script se tait quand l'adresse manque. La FICHE
 * TECHNIQUE, elle, est incluse — elle ne vient d'aucun fournisseur mais du
 * marchand, qui règle résolution, mode colorimétrique et fonds perdus sur
 * chaque produit. L'omettre faisait disparaître un réglage qu'il avait pris la
 * peine de saisir, sans que rien ne le dise.
 *}
<div class="eko-poc"
     data-id-product="{$eko.id_product|intval}"
     data-url="{$eko.url|escape:'html':'UTF-8'}"
     data-mode="formulaire"
     data-delai-urgence="{l s='Urgence' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-delai-express="{l s='Express' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-delai-standard="{l s='Standard' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-oui="{l s='Oui' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     {* Le sur-mesure : ces produits se vendent à la dimension, et une cote
        jamais mesurée se fait calculer chez le fournisseur — une vingtaine de
        secondes pendant lesquelles il faut dire au visiteur ce qui se passe. *}
     data-calcul-sur-mesure="{l s='Nous calculons votre tarif sur mesure…' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-calcul-trop="{l s='Le calcul est plus long que prévu. Rechargez la page ou demandez-nous un devis.' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     {* ⚠️ PAS `data-sur-mesure` : cet attribut EXISTE DÉJÀ plus bas, avec un
        autre sens (« Configuration personnalisée », le titre du récapitulatif).
        Deux attributs de même nom sur une balise : le navigateur garde le
        PREMIER et ignore le second, sans rien signaler. Le récapitulatif se
        serait mis à annoncer des dimensions. *}
     data-autre-dimension="{l s='Dimensions sur mesure' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-non="{l s='Non' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
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
     data-perime="{l s='Ces tarifs n’ont pas été rafraîchis récemment. Confirmez avant de commander.' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-echec="{l s='Options indisponibles pour le moment.' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-attendez="{l s='Choisissez vos options pour obtenir un prix.' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-livree="{l s='Livraison estimée' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-dont-prestations="{l s='dont prestations' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-ajouter="{l s='Ajouter au panier' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-echec-panier="{l s='L’ajout au panier n’a pas abouti. Vérifiez votre configuration et réessayez.' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-tout-voir="{l s='Tout voir' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-voir-plus="{l s='Voir plus' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-precedent="{l s='Formats précédents' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-suivant="{l s='Formats suivants' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-superieures="{l s='Quantités supérieures' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-je-configure="{l s='Je configure mon produit' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-sur-mesure="{l s='Configuration personnalisée' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-plus-vite="{l s='Soyez livré plus rapidement' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-prix-public="{l s='Prix public TTC' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}"
     data-gratuit="{l s='Gratuit' d='Modules.Ekosyncimprimerie.Shop'|escape:'html':'UTF-8'}">

  <h2 class="eko-poc__titre">{l s='Je configure mon produit' d='Modules.Ekosyncimprimerie.Shop'}</h2>

  {* Les lignes de choix, bâties par le JS au fur et à mesure de l'arbre. *}
  <div class="eko-poc__etapes" aria-live="polite"></div>

  {* Le récapitulatif : ce qui a été choisi, et ce que ça coûte. *}
  <aside class="eko-poc__resume" aria-live="polite"></aside>
</div>

{include file='module:ekosyncimprimerie/views/templates/hook/_technique.tpl'}
