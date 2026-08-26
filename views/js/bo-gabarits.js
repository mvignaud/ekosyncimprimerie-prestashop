/**
 * EKO Sync — Imprimerie : dépôt des gabarits, sur la fiche produit.
 *
 * ⚠️ Le formulaire produit de PrestaShop 9 est un formulaire Symfony soumis en
 * AJAX. Un champ fichier injecté par un hook n'y est PAS transmis de façon
 * fiable : le marchand choisit son fichier, croit l'avoir envoyé, et rien
 * n'arrive. On poste donc nous-mêmes, vers le contrôleur d'administration du
 * module.
 *
 * Les écouteurs sont délégués sur le bloc, pas posés sur chaque bouton : la
 * fiche produit réécrit des pans entiers de son DOM au fil des onglets, et
 * tout écouteur attaché à un élément disparaîtrait avec lui, sans erreur.
 */
(function () {
    'use strict';

    function bloc(element) {
        return element.closest('.eko-gab');
    }

    function ligne(element) {
        return element.closest('.eko-gab__ligne');
    }

    function etat($ligne, texte, classe) {
        var $etat = $ligne.querySelector('.eko-gab__etat');

        if ($etat) {
            $etat.textContent = texte;
        }

        $ligne.classList.remove('eko-gab__ligne--occupe', 'eko-gab__ligne--erreur');

        if (classe) {
            $ligne.classList.add(classe);
        }
    }

    function envoyer($bloc, $ligne, donnees, enCours) {
        etat($ligne, enCours, 'eko-gab__ligne--occupe');

        return fetch($bloc.dataset.url, {
            method: 'POST',
            body: donnees,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (r) { return r.json(); })
            .then(function (r) {
                if (!r || !r.ok) {
                    etat($ligne, (r && r.message) || $bloc.dataset.echec, 'eko-gab__ligne--erreur');
                    return;
                }

                etat($ligne, r.message, null);

                // On recharge : l'état d'une ligne dépend d'une lecture en base
                // et sur disque. Le recalculer en JavaScript, c'est entretenir
                // une seconde vérité qui finira par diverger de la première.
                window.setTimeout(function () { window.location.reload(); }, 600);
            })
            .catch(function () {
                etat($ligne, $bloc.dataset.echec, 'eko-gab__ligne--erreur');
            });
    }

    document.addEventListener('change', function (evenement) {
        var $champ = evenement.target;

        if (!$champ.matches || !$champ.matches('.eko-gab__deposer input[type="file"]')) {
            return;
        }

        var $bloc = bloc($champ);
        var $ligne = ligne($champ);

        if (!$bloc || !$ligne || !$champ.files || !$champ.files.length) {
            return;
        }

        var donnees = new FormData();
        donnees.append('id_product', $bloc.dataset.produit);
        donnees.append('format', $ligne.dataset.format);
        donnees.append('gabarit', $champ.files[0]);

        envoyer($bloc, $ligne, donnees, 'Envoi…');

        // Le champ est vidé : sans cela, redéposer le MÊME fichier n'émettrait
        // aucun `change`, et le marchand croirait le bouton cassé.
        $champ.value = '';
    });

    document.addEventListener('click', function (evenement) {
        var $bouton = evenement.target.closest('.eko-gab__retirer');

        if (!$bouton) {
            return;
        }

        evenement.preventDefault();

        var $bloc = bloc($bouton);
        var $ligne = ligne($bouton);

        if (!$bloc || !$ligne) {
            return;
        }

        var donnees = new FormData();
        donnees.append('id_product', $bloc.dataset.produit);
        donnees.append('format', $ligne.dataset.format);
        donnees.append('retirer', '1');

        envoyer($bloc, $ligne, donnees, 'Retrait…');
    });
}());
