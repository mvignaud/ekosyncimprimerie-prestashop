{**
 * EKO Sync — Imprimerie
 *
 * Le lien vers le document de commande, dans le détail d'une commande.
 *
 * ⚠️ CE DOCUMENT VIENT DE L'ERP, il n'est plus composé ici. C'est le MÊME
 * fichier que celui de l'atelier : même numéro, mêmes totaux, mêmes mentions.
 * Deux gabarits pour un même objet finissent par diverger, et le client aurait
 * fini par présenter l'un pendant que l'atelier lisait l'autre.
 *
 * Rendu par `displayOrderDetail`, hook natif déjà appelé par
 * `themes/akira/templates/customer/order-detail.tpl` — le thème enfant ne
 * surcharge aucun gabarit `customer/`.
 *
 * Les classes reprennent celles du lien de facture voisin (`.link-button`) :
 * un client qui distingue mal ses deux liens finit par ouvrir le mauvais.
 *}
<div class="box eko-bon-commande">
	<a href="{$eko_url_bon_commande|escape:'html':'UTF-8'}" class="link-button" rel="nofollow">
		<i class="material-icons">&#xE415;</i>
		{l s='Télécharger mon document de commande (PDF)' d='Modules.Ekosyncimprimerie.Shop'}
	</a>
	<p class="eko-bon-commande__note">
		{l s='Il détaille votre configuration ligne par ligne. C’est le document que notre atelier a sous les yeux. Ce n’est pas une facture.' d='Modules.Ekosyncimprimerie.Shop'}
	</p>
</div>
