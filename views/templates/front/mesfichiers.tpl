{**
 * EKO Sync — Imprimerie
 *
 * « Mes fichiers » — le dépôt différé, depuis le compte client.
 *
 * ⚠️ La zone de dépôt n'est PAS redessinée ici : elle vient de
 * `zoneDeDepot()`, le même écrivain que le panier. Deux balisages pour un même
 * geste finissent par diverger, et c'est celui qu'on regarde le moins qui
 * vieillit.
 *
 * Le script est en bas de ce fichier, dans le bloc — un gabarit qui `{extends}`
 * jette tout ce qui vit hors d'un `{block}`, sans le dire.
 *}
{extends file='customer/page.tpl'}

{block name='page_title'}
	{l s='Mes fichiers' d='Modules.Ekosyncimprimerie.Shop'}
{/block}

{block name='page_content'}
	<p class="eko-mesfichiers__intro">
		{l s='Déposez ici les fichiers d’impression de vos commandes. Chaque fichier est contrôlé automatiquement dès son arrivée — format, fonds perdus, résolution, polices — et vous en voyez le verdict aussitôt.' d='Modules.Ekosyncimprimerie.Shop'}
	</p>

	{if !$eko_commandes}
		<div class="eko-mesfichiers__vide">
			<p>{l s='Vous n’avez pas encore de commande à laquelle rattacher un fichier.' d='Modules.Ekosyncimprimerie.Shop'}</p>
			<a href="{$urls.pages.index}" class="btn btn-primary">{l s='Voir le catalogue' d='Modules.Ekosyncimprimerie.Shop'}</a>
		</div>
	{/if}

	{foreach from=$eko_commandes item=commande}
		<section class="eko-mesfichiers__commande{if $commande.en_attente} eko-mesfichiers__commande--attente{/if}">
			<header class="eko-mesfichiers__entete">
				<div>
					<h2 class="eko-mesfichiers__reference">
						{l s='Commande' d='Modules.Ekosyncimprimerie.Shop'} {$commande.reference|escape:'html':'UTF-8'}
					</h2>
					<p class="eko-mesfichiers__meta">
						{$commande.date|escape:'html':'UTF-8'}
						{if $commande.etat} &middot; {$commande.etat|escape:'html':'UTF-8'}{/if}
					</p>
				</div>

				{if $commande.en_attente}
					<p class="eko-mesfichiers__attente">
						{**
						 * Le décompte plutôt qu'un simple « incomplet » : sur une
						 * commande à six lignes, savoir qu'il en manque UNE change
						 * ce que le client fait dans la minute.
						 *}
						{if $commande.en_attente == 1}
							{l s='1 ligne attend son fichier' d='Modules.Ekosyncimprimerie.Shop'}
						{else}
							{l s='%d lignes attendent leur fichier' sprintf=[$commande.en_attente] d='Modules.Ekosyncimprimerie.Shop'}
						{/if}
					</p>
				{/if}
			</header>

			{foreach from=$commande.lignes item=ligne}
				<article class="eko-mesfichiers__ligne">
					<div class="eko-mesfichiers__produit">
						<h3>{$ligne.nom|escape:'html':'UTF-8'}</h3>

						{if $ligne.configuration|@count > 0}
							<ul class="eko-mesfichiers__config">
								{foreach from=$ligne.configuration key=critere item=valeur}
									<li><span>{$critere|escape:'html':'UTF-8'}</span> {$valeur|escape:'html':'UTF-8'}</li>
								{/foreach}
							</ul>
						{/if}
					</div>

					<div class="eko-mesfichiers__depot">
						{$eko_module->zoneDeDepot($ligne.id_customization) nofilter}
					</div>
				</article>
			{/foreach}
		</section>
	{/foreach}

	{**
	 * Le dépôt, en flux vers E-KO.
	 *
	 * ⚠️ Le fichier ne touche JAMAIS le disque de la boutique : le contrôleur
	 * le relaie en flux vers le S3 d'E-KO depuis le temporaire de PHP, que PHP
	 * supprime lui-même. Un `move_uploaded_file()` casserait cet invariant et
	 * remplirait le mutualisé de fichiers de 128 Mo que plus rien n'efface.
	 *
	 * Le script est volontairement séparé de celui du panier : le point de
	 * vérité partagé est le CONTRÔLEUR, déjà unique. Extraire un fichier
	 * commun imposerait de toucher au JavaScript du panier en production pour
	 * un gain de trente lignes.
	 *}
	<script>
	(function () {
		'use strict';

		if (window.ekoMesFichiersBranchee) { return; }

		window.ekoMesFichiersBranchee = true;

		var URL_DEPOT = '{$eko_url_depot|escape:'javascript'}';

		function etat(zone, message, echoue) {
			var note = zone.querySelector('.eko-fichier__note');

			if (!note) { return; }

			note.textContent = message;
			note.classList.toggle('eko-fichier__note--echec', echoue === true);
		}

		document.addEventListener('change', function (e) {
			try {
				var champ = e.target;

				if (!champ || champ.type !== 'file' || !champ.files || !champ.files.length) { return; }

				var zone = champ.closest('.eko-fichier');

				if (!zone) { return; }

				var id = zone.getAttribute('data-custo');
				var restants = champ.files.length;

				etat(zone, '{l s='Envoi en cours…' d='Modules.Ekosyncimprimerie.Shop'|escape:'javascript'}', false);

				Array.prototype.forEach.call(champ.files, function (fichier) {
					var corps = new FormData();

					corps.append('geste', 'envoyer');
					corps.append('id_customization', id);
					corps.append('fichier', fichier);

					fetch(URL_DEPOT, {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'X-Requested-With': 'XMLHttpRequest' },
						body: corps
					})
						.then(function (r) { return r.json(); })
						.then(function (r) {
							restants -= 1;

							if (!r || !r.ok) {
								// ⚠️ Jamais muet : un client qui croit son
								// fichier déposé ne le redéposera pas, et
								// l'atelier attendra.
								etat(zone, (r && r.message) || '{l s='Envoi impossible — réessayez.' d='Modules.Ekosyncimprimerie.Shop'|escape:'javascript'}', true);

								return;
							}

							// On ne reconstruit pas la liste en JavaScript :
							// l'état d'un fichier vient du préflight, côté
							// serveur. On redemande la page quand tout est
							// parti.
							if (restants <= 0) { window.location.reload(); }
						})
						.catch(function () {
							restants -= 1;
							etat(zone, '{l s='Envoi impossible — réessayez.' d='Modules.Ekosyncimprimerie.Shop'|escape:'javascript'}', true);
						});
				});

				champ.value = '';
			} catch (err) { /* ne jamais décapiter les scripts suivants */ }
		});
	}());
	</script>
{/block}
