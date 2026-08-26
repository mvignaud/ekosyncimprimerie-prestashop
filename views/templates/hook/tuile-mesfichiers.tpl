{**
 * EKO Sync — Imprimerie
 *
 * La tuile « Mes fichiers », dans le compte client.
 *
 * Les classes reprennent celles des tuiles natives (`.links`, `.link-item`) :
 * une tuile maison qui ne leur ressemble pas se lit comme une publicité.
 *}
<a class="col-lg-4 col-md-6 col-sm-6 col-xs-12 link-item eko-tuile-fichiers" href="{$eko_url_mesfichiers|escape:'html':'UTF-8'}">
	<i class="material-icons">&#xE2C6;</i>
	{l s='Mes fichiers' d='Modules.Ekosyncimprimerie.Shop'}
	<span class="eko-tuile-fichiers__note">
		{l s='Déposer les fichiers d’impression de vos commandes' d='Modules.Ekosyncimprimerie.Shop'}
	</span>
</a>
