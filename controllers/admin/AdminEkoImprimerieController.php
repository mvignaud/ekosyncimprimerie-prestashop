<?php

/**
 * EKO Sync — Imprimerie
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

/**
 * Les réglages qui valent pour TOUTE la boutique.
 *
 * ─── POURQUOI CET ÉCRAN EXISTE ─────────────────────────────────────────────
 *
 * Fiche technique, prestations, réassurances, heures limites : ces réglages se
 * posent déjà produit par produit, sur la fiche. Mais un imprimeur a les mêmes
 * pour tout son catalogue — les mêmes 300 DPI, le même bon à tirer, la même
 * heure limite. Les ressaisir sur quatre-vingt-quatre fiches serait absurde, et
 * la première modification en oublierait la moitié.
 *
 * `ServicesProduit::reglage()` cascade déjà PRODUIT → BOUTIQUE → défaut. Cet
 * écran ne fait qu'ouvrir l'étage du milieu, qui n'était accessible qu'en base.
 * Ce qui est saisi ici vaut partout ; ce qui est saisi sur une fiche l'emporte
 * pour elle seule.
 *
 * ─── OÙ IL SE RANGE ────────────────────────────────────────────────────────
 *
 * Sous « Catalogue », à côté des produits qu'il concerne — et non dans la
 * configuration du module, où l'on ne va qu'une fois pour brancher l'API.
 */

declare(strict_types=1);

class AdminEkoImprimerieController extends ModuleAdminController
{
    /**
     * Les champs de l'écran : clé de réglage => intitulé, aide, forme.
     *
     * Une table plutôt qu'un formulaire écrit à la main : les mêmes clés
     * servent à l'affichage, à l'enregistrement et à la liste blanche de ce
     * qu'on accepte de la requête. Trois listes à tenir d'accord deviendraient
     * fausses au premier ajout.
     *
     * @var array<string, array{titre: string, aide: string, lignes: int}>
     */
    private array $champs = [];

    public function __construct()
    {
        $this->bootstrap = true;
        $this->display = 'edit';

        parent::__construct();

        $this->champs = [
            'tech_resolution' => [
                'titre' => $this->trans('Résolution', [], 'Modules.Ekosyncimprimerie.Admin'),
                'aide' => '',
                'lignes' => 0,
            ],
            'tech_couleurs' => [
                'titre' => $this->trans('Couleurs', [], 'Modules.Ekosyncimprimerie.Admin'),
                'aide' => '',
                'lignes' => 0,
            ],
            'tech_fonds_perdus' => [
                'titre' => $this->trans('Fonds perdus', [], 'Modules.Ekosyncimprimerie.Admin'),
                'aide' => '',
                'lignes' => 0,
            ],
            'tech_marge' => [
                'titre' => $this->trans('Marge de sécurité', [], 'Modules.Ekosyncimprimerie.Admin'),
                'aide' => '',
                'lignes' => 0,
            ],
            'svc_bat' => [
                'titre' => $this->trans('BAT numérique', [], 'Modules.Ekosyncimprimerie.Admin'),
                'aide' => $this->trans(
                    'Une ligne par option : « Libellé|supplément en euros ». La première ligne est le choix par défaut — elle doit être gratuite.',
                    [],
                    'Modules.Ekosyncimprimerie.Admin'
                ),
                'lignes' => 3,
            ],
            'svc_creation' => [
                'titre' => $this->trans('Ma création graphique', [], 'Modules.Ekosyncimprimerie.Admin'),
                'aide' => '',
                'lignes' => 4,
            ],
            'mention_prix' => [
                'titre' => $this->trans('Mention sous le prix', [], 'Modules.Ekosyncimprimerie.Admin'),
                'aide' => $this->trans(
                    'Affichée dans le récapitulatif, sous le montant. Laisser vide si la livraison n\'est pas offerte : la mention serait alors fausse.',
                    [],
                    'Modules.Ekosyncimprimerie.Admin'
                ),
                'lignes' => 0,
            ],
            'delai_note' => [
                'titre' => $this->trans('Heure limite — offre incluse', [], 'Modules.Ekosyncimprimerie.Admin'),
                'aide' => $this->trans(
                    'Affichée sous le délai inclus. Laisser vide pour ne rien annoncer : c\'est un engagement pris devant le client.',
                    [],
                    'Modules.Ekosyncimprimerie.Admin'
                ),
                'lignes' => 0,
            ],
            'delai_note_rapide' => [
                'titre' => $this->trans('Heure limite — livraison accélérée', [], 'Modules.Ekosyncimprimerie.Admin'),
                'aide' => '',
                'lignes' => 0,
            ],
            'reassurances' => [
                'titre' => $this->trans('Réassurances', [], 'Modules.Ekosyncimprimerie.Admin'),
                'aide' => $this->trans(
                    'Une ligne par argument : « Libellé|icône ». L\'icône est origine, livraison, fichier ou paiement — ou le chemin d\'une image déposée sur la boutique, pour un logo qui vous appartient.',
                    [],
                    'Modules.Ekosyncimprimerie.Admin'
                ),
                'lignes' => 4,
            ],
        ];
    }

    public function postProcess()
    {
        if (!Tools::isSubmit('ekosync_enregistrer')) {
            return parent::postProcess();
        }

        // Liste blanche : seules les clés de `$this->champs` sont lues. Une clé
        // inventée dans la requête ne doit pas pouvoir écrire un réglage.
        foreach (array_keys($this->champs) as $cle) {
            if (Tools::getIsset('ekosync_' . $cle)) {
                \Eko\SyncImprimerie\Configurateur\ServicesProduit::poser(
                    $cle,
                    0,
                    (string) Tools::getValue('ekosync_' . $cle)
                );
            }
        }

        $this->confirmations[] = $this->trans('Réglages enregistrés.', [], 'Modules.Ekosyncimprimerie.Admin');

        return true;
    }

    public function renderForm()
    {
        $corps = '<p class="alert alert-info">'
            . htmlspecialchars($this->trans(
                'Ces réglages valent pour toutes les fiches liées à l\'ERP. Une fiche qui porte sa propre valeur garde la sienne.',
                [],
                'Modules.Ekosyncimprimerie.Admin'
            ))
            . '</p>';

        foreach ($this->champs as $cle => $def) {
            // La valeur AFFICHÉE est celle de la boutique, pas celle qui
            // s'applique en cascade : montrer un défaut dans un champ vide
            // ferait croire qu'il est enregistré, et le premier enregistrement
            // le figerait sans que personne ne l'ait voulu.
            $valeur = (string) (Configuration::get('EKOSYNC_' . strtoupper($cle)) ?: '');

            $corps .= '<div class="form-group"><label class="form-control-label">'
                . htmlspecialchars($def['titre']) . '</label>';

            $corps .= $def['lignes'] > 0
                ? sprintf(
                    '<textarea name="ekosync_%s" class="form-control" rows="%d">%s</textarea>',
                    htmlspecialchars($cle),
                    $def['lignes'],
                    htmlspecialchars($valeur)
                )
                : sprintf(
                    '<input type="text" name="ekosync_%s" class="form-control" value="%s">',
                    htmlspecialchars($cle),
                    htmlspecialchars($valeur)
                );

            if ($def['aide'] !== '') {
                $corps .= '<p class="help-block">' . htmlspecialchars($def['aide']) . '</p>';
            }

            $corps .= '</div>';
        }

        return '<form method="post" class="panel"><h3>'
            . htmlspecialchars($this->trans('Réglages de l\'imprimerie', [], 'Modules.Ekosyncimprimerie.Admin'))
            . '</h3>' . $corps
            . '<div class="panel-footer"><button type="submit" name="ekosync_enregistrer"'
            . ' class="btn btn-default pull-right"><i class="process-icon-save"></i> '
            . htmlspecialchars($this->trans('Enregistrer', [], 'Modules.Ekosyncimprimerie.Admin'))
            . '</button></div></form>';
    }
}
