<?php

/**
 * EKO Sync — Imprimerie
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

/**
 * Le configurateur du second sous-traitant : un FORMULAIRE, pas un arbre.
 *
 * ─── EN QUOI IL DIFFÈRE DE SON AÎNÉ ────────────────────────────────────────
 *
 * `catalogue.php` descend un arbre : chaque choix restreint le suivant, et une
 * combinaison se désigne par un chemin. Ici, une vingtaine de champs sont
 * largement INDÉPENDANTS — matière, hauteur, largeur, œillets, finition — et le
 * client les remplit dans l'ordre qu'il veut. Il n'y a donc ni étape ni chemin :
 * le formulaire arrive entier, et la configuration est une table de valeurs.
 *
 * ─── POURQUOI HÉRITER PLUTÔT QUE RECOPIER ──────────────────────────────────
 *
 * La forme des données diffère ; tout le reste est commun. La TVA du visiteur,
 * la mise en forme d'un montant dans sa devise, les jours ouvrés, les
 * prestations du marchand, le garde qui transforme une fatale en refus lisible :
 * rien de cela ne dépend du fournisseur. Recopié, ce socle aurait divergé à la
 * première retouche de l'un des deux — et le visiteur aurait vu deux boutiques.
 *
 * ─── LA MATIÈRE EST IMPOSÉE PAR LA FICHE ───────────────────────────────────
 *
 * Une même déclinaison du fournisseur alimente plusieurs pages : Akylux, Dibond
 * et PVC expansé sont trois fiches et un seul produit chez lui. La fiche porte
 * donc sa matière dans sa référence, et le formulaire la VERROUILLE — sans quoi
 * on pourrait acheter du Dibond sur la page de l'Akylux, au prix du Dibond
 * certes, mais sous un titre et une description qui parlent d'autre chose.
 */

use Eko\SyncImprimerie\Configurateur\LiaisonProduit;
use Eko\SyncImprimerie\Configurateur\ReponseJson;

// ⚠️ Ce `require_once` n'est pas de la paresse : le module n'a pas
// d'autochargeur pour ses contrôleurs front, et PrestaShop n'inclut que le
// fichier du contrôleur demandé. Sans lui, la classe parente est introuvable et
// la requête part en fatale avant d'avoir écrit quoi que ce soit.
require_once _PS_MODULE_DIR_ . 'ekosyncimprimerie/controllers/front/catalogue.php';

class EkosyncimprimerieConfigrpModuleFrontController extends EkosyncimprimerieCatalogueModuleFrontController
{
    /**
     * Les clés de configuration acceptées.
     *
     * Le fournisseur les nomme `VARTICLE_16450_`. Tout ce qui n'a pas cette
     * forme vient d'ailleurs que de son formulaire, et n'a rien à faire dans
     * une empreinte de configuration.
     */
    private const CLE_VALIDE = '/^VARTICLE_\d{1,12}_$/';

    /**
     * @return array<string, mixed>
     */
    protected function repondre(): array
    {
        $idProduct = (int) Tools::getValue('id_product');
        $liaison = (new LiaisonProduit())->realisaprint($idProduct);

        if ($liaison === null) {
            return $this->refus('Cette fiche n\'est pas liée à ce catalogue de sous-traitance.');
        }

        $code = rawurlencode($liaison['code']);

        if (Tools::getValue('quoi') === 'formulaire') {
            return $this->formulaire($idProduct, $code, $liaison['support']);
        }

        if (Tools::getValue('quoi') === 'retenir') {
            return $this->retenirConfig($idProduct, $code, $liaison['support']);
        }

        if (Tools::getValue('quoi') === 'suivi') {
            return $this->suivi($idProduct);
        }

        return $this->grille($idProduct, $code, $liaison['support']);
    }

    /**
     * Le formulaire, tel que l'ERP le décrit.
     *
     * ─── CE QUI N'EST PAS UN CHAMP ─────────────────────────────────────────
     *
     * La QUANTITÉ et le DÉLAI sont les deux axes de la grille tarifaire : ils
     * ne se saisissent pas, ils se choisissent dans le tableau de prix. Les
     * poser en champs aurait demandé au visiteur de fixer d'abord ce que le
     * tableau lui montre ensuite.
     *
     * Les champs en lecture seule ne s'affichent pas non plus, mais leur valeur
     * par défaut VOYAGE : elle fait partie de la configuration que le
     * fournisseur a chiffrée, et l'omettre changerait le prix sans le dire.
     *
     * @return array<string, mixed>
     */
    private function formulaire(int $idProduct, string $code, string $support): array
    {
        /** @var Ekosyncimprimerie $module */
        $module = $this->module;

        $r = $module->client()->appeler('GET', '/api/v1/realisaprint/products/' . $code . '/tree');

        if (!$r['ok']) {
            return $this->refus(
                $r['erreur'] !== '' ? $r['erreur'] : 'Options indisponibles.',
                ReponseJson::statutAmont((int) $r['code'])
            );
        }

        // ⚠️ CE QUE LA BOUTIQUE A LE DROIT DE PROPOSER, et rien d'autre.
        //
        // Le fournisseur décrit une vingtaine de champs ; la grille tarifaire
        // n'en fait varier que trois — la matière et les deux cotes. Les autres
        // sont restés à leur valeur par défaut pendant sa construction.
        //
        // Présenter les vingt laisserait le client choisir des œillets, puis
        // lui servirait le prix SANS œillets : le tarif retrouvé est celui de
        // la même surface dans la même matière, et rien à l'écran ne dirait que
        // le reste a été ignoré. Le montant resterait plausible, et la
        // sous-traitance, elle, nous facturerait les œillets.
        //
        // La liste vient de l'ERP, jamais de la boutique : elle s'élargira
        // d'elle-même le jour où la grille sera construite plus profondément.
        $tarifes = [];

        foreach ((array) ($r['donnees']['pricing_variables'] ?? []) as $id) {
            if (is_string($id)) {
                $tarifes[$id] = true;
            }
        }

        $champs = [];
        $imposees = [];
        $cleHauteur = '';
        $cleLargeur = '';
        $cleSupport = '';

        foreach ((array) ($r['donnees']['variables'] ?? []) as $v) {
            if (!is_array($v)) {
                continue;
            }

            $id = (string) ($v['id'] ?? '');

            if ($id === '' || preg_match(self::CLE_VALIDE, $id) !== 1) {
                continue;
            }

            $defaut = is_scalar($v['default'] ?? null) ? (string) $v['default'] : '';

            // Les deux axes de la grille : ils sortent du formulaire, et ils
            // ne voyagent pas non plus dans la configuration — c'est la case
            // choisie au tableau qui les porte.
            if (($v['is_quantity'] ?? false) === true || ($v['is_delay'] ?? false) === true) {
                continue;
            }

            // Un champ que la grille ne fait pas varier ne s'affiche pas, et
            // sa valeur ne voyage pas non plus : le tarif est celui de sa
            // valeur par défaut, que le fournisseur applique de lui-même.
            if (!isset($tarifes[$id])) {
                continue;
            }

            $nom = (string) ($v['name'] ?? '');
            $estSupport = $nom === 'Support';

            // ⚠️ CES PRODUITS SE VENDENT À LA DIMENSION.
            //
            // J'avais d'abord remplacé les deux cotes par un choix de format,
            // parce que la grille ne portait que les cotes déjà mesurées. Mais
            // c'est imposer au client une contrainte que le produit n'a pas :
            // un panneau se commande au centimètre près.
            //
            // Les deux champs sont donc libres. Une cote inédite ne se déduit
            // pas pour autant — la loi de prix n'est pas monotone en surface,
            // 9 920 cm² coûte moins cher que 9 600 — elle se fait CALCULER chez
            // le fournisseur, en une vingtaine de secondes. Les formats
            // construits restent proposés comme raccourcis, parce qu'ils sont
            // instantanés.
            // ⚠️ ON NOTE LA CLÉ **ET** ON SORT DE LA BOUCLE.
            //
            // Ces deux champs sont reposés plus bas, à leur place — juste
            // après la matière — avec le type qui va bien et une valeur par
            // défaut prise sur le plus petit format construit. Sans ce
            // `continue`, la boucle générale les ajoutait EN PLUS : le
            // formulaire montrait « Hauteur (cm) » deux fois, l'une remplie
            // et l'autre vide, et la seconde écrasait la première dans la
            // configuration envoyée.
            if (str_starts_with($nom, 'Hauteur')) {
                $cleHauteur = $id;

                continue;
            }

            if (str_starts_with($nom, 'Largeur')) {
                $cleLargeur = $id;

                continue;
            }

            // La matière de la fiche l'emporte sur celle du fournisseur : c'est
            // le titre de la page qui a promis l'Akylux.
            if ($estSupport) {
                $cleSupport = $id;

                if ($support !== '') {
                    $defaut = $support;
                    $imposees[$id] = $support;
                }
            }

            // Tarifé MAIS non modifiable : il ne s'affiche pas, et pourtant sa
            // valeur doit voyager — c'est sur elle que la ligne de grille est
            // rangée. L'omettre chercherait un tarif sans matière, et deux
            // matières de même surface n'ont pas le même prix : l'écart mesuré
            // va de 37 à 92 €.
            if (($v['readonly'] ?? false) === true) {
                if ($defaut !== '') {
                    $imposees[$id] = $defaut;
                }

                continue;
            }

            $valeurs = [];

            foreach ((array) ($v['values'] ?? []) as $o) {
                if (!is_array($o) || ($o['available'] ?? true) !== true) {
                    continue;
                }

                $vid = (string) ($o['id'] ?? '');

                if ($vid === '') {
                    continue;
                }

                $valeurs[] = ['id' => $vid, 'label' => (string) ($o['label'] ?? $vid)];
            }

            // ⚠️ LA MATIÈRE IMPOSÉE DOIT EXISTER DANS LA LISTE.
            //
            // Sinon le menu déroulant verrouillé s'ouvre sur sa PREMIÈRE
            // entrée — une autre matière — pendant que le prix, lui, est celui
            // de la matière imposée. Le client lit « Dibond » et paie de
            // l'Akylux, ou l'inverse. Rien à l'écran ne peut le dire.
            //
            // Ça n'arrive que si le fournisseur a retiré une matière depuis
            // que la fiche a été liée. C'est donc une liaison à refaire, et le
            // dire vaut mieux que de vendre à côté.
            if ($estSupport && $support !== '' && $valeurs !== []
                && !in_array($support, array_column($valeurs, 'id'), true)) {
                return $this->refus(
                    'Cette fiche est liée à une matière que le fournisseur ne propose plus. '
                    . 'Demandez-nous un devis, nous la rétablirons.',
                    ReponseJson::PANNE
                );
            }

            $champs[] = [
                'id' => $id,
                'nom' => $nom !== '' ? $nom : $id,
                'type' => $valeurs !== [] ? 'liste' : $this->typeDeSaisie((string) ($v['type'] ?? '')),
                'defaut' => $defaut,
                // Un champ verrouillé reste VISIBLE : le client doit lire la
                // matière qu'il achète, même s'il ne peut pas en changer.
                'verrouille' => $estSupport && $support !== '',
                'secondaire' => (int) ($v['area'] ?? 1) > 1,
                'valeurs' => $valeurs,
            ];
        }

        $formats = $this->formats($r['donnees']['formats'] ?? [], $cleHauteur, $cleLargeur);

        if ($cleHauteur !== '' && $cleLargeur !== '') {
            // Juste après la matière : on choisit sa matière, puis sa taille.
            // Les raccourcis d'abord, les deux cotes libres ensuite — un
            // visiteur qui a son format en tête le trouve sans taper, et
            // celui qui a une contrainte précise la saisit.
            $rang = $cleSupport !== '' ? 1 : 0;
            $ajouts = [];

            if ($formats !== []) {
                $ajouts[] = $formats;
            }

            $ajouts[] = $this->champCote(
                $cleHauteur,
                $this->trans('Hauteur (cm)', [], 'Modules.Ekosyncimprimerie.Shop'),
                $formats
            );
            $ajouts[] = $this->champCote(
                $cleLargeur,
                $this->trans('Largeur (cm)', [], 'Modules.Ekosyncimprimerie.Shop'),
                $formats,
                false
            );

            array_splice($champs, $rang, 0, $ajouts);
        }

        if ($champs === []) {
            // ⚠️ « Pas de configurateur » serait FAUX ici : le fournisseur en a
            // un, avec une vingtaine de champs. Ce qui manque, c'est une grille
            // de tarifs construite sur ce produit. Le dire autrement enverrait
            // chercher un défaut dans le mauvais endroit.
            return $this->refus(
                'Ce produit se chiffre sur devis pour le moment. Écrivez-nous, nous répondons vite.'
            );
        }

        return [
            'ok' => true,
            'champs' => $champs,
            'imposees' => $imposees,
            'cle_support' => $cleSupport,
            'services' => $this->prestations($idProduct),
            // ⚠️ PAS DE VENTES PHARES ICI, et c'est délibéré. Ce raccourci
            // désigne un FORMAT du premier sous-traitant par son code ; il n'a
            // pas d'équivalent dans un formulaire. Les transmettre aurait
            // rempli l'onglet de rien : un réglage qui voyage sans jamais
            // s'afficher est pire qu'un réglage absent, parce qu'il se règle.
            'reassurances' => \Eko\SyncImprimerie\Configurateur\ServicesProduit::reassurances($idProduct),
        ];
    }

    /**
     * Une cote libre, en centimètres.
     *
     * Sa valeur par défaut est celle du plus petit format construit : la fiche
     * s'ouvre ainsi sur un tarif immédiat plutôt que sur deux champs vides et
     * un message. Sans format construit, elle s'ouvre vide, et le visiteur
     * saisit ce qu'il veut — le calcul se fera chez le fournisseur.
     *
     * @param  array<string, mixed>  $formats
     * @return array<string, mixed>
     */
    private function champCote(string $id, string $nom, array $formats, bool $hauteur = true): array
    {
        $premier = $formats['valeurs'][0] ?? null;

        return [
            'id' => $id,
            'nom' => $nom,
            'type' => 'cote',
            'defaut' => is_array($premier) ? (string) ($premier[$hauteur ? 'hauteur' : 'largeur'] ?? '') : '',
            'verrouille' => false,
            'secondaire' => false,
            'valeurs' => [],
        ];
    }

    /**
     * Les formats construits, en un seul champ de choix.
     *
     * Chaque valeur porte SA matière : sur une fiche qui n'en impose aucune,
     * la liste se restreint dans le navigateur au fur et à mesure que le
     * visiteur choisit — deux matières n'ont pas forcément été construites sur
     * les mêmes cotes.
     *
     * @param  mixed  $brut
     * @return array<string, mixed>
     */
    private function formats($brut, string $cleHauteur, string $cleLargeur): array
    {
        if (!is_array($brut) || $brut === [] || $cleHauteur === '' || $cleLargeur === '') {
            return [];
        }

        $valeurs = [];

        foreach ($brut as $f) {
            if (!is_array($f)) {
                continue;
            }

            $h = (string) ($f['hauteur'] ?? '');
            $l = (string) ($f['largeur'] ?? '');

            if ($h === '' || $l === '') {
                continue;
            }

            $valeurs[] = [
                'id' => $h . 'x' . $l,
                // « × » et non « x » : c'est le signe multiplié, et il se lit
                // comme une dimension partout où cette boutique est traduite.
                'label' => $h . ' × ' . $l . ' cm',
                'support' => (string) ($f['support'] ?? ''),
                'hauteur' => $h,
                'largeur' => $l,
            ];
        }

        if ($valeurs === []) {
            return [];
        }

        return [
            // ⚠️ Cet identifiant n'a PAS la forme d'une clé du fournisseur, et
            // c'est voulu : le nettoyage de la configuration le rejetterait.
            // Ce champ ne voyage pas — il pose les deux cotes, qui voyagent.
            'id' => 'FORMAT',
            'nom' => $this->trans('Formats courants', [], 'Modules.Ekosyncimprimerie.Shop'),
            'type' => 'format',
            'cle_hauteur' => $cleHauteur,
            'cle_largeur' => $cleLargeur,
            'defaut' => '',
            'verrouille' => false,
            'secondaire' => false,
            'valeurs' => $valeurs,
        ];
    }

    /**
     * La grille tarifaire d'une configuration.
     *
     * @return array<string, mixed>
     */
    private function grille(int $idProduct, string $code, string $support): array
    {
        $config = $this->config($code, $support);

        if ($config === []) {
            return $this->refus('Configuration incomplète.');
        }

        /** @var Ekosyncimprimerie $module */
        $module = $this->module;

        // ⚠️ LA BORNE DE DÉBIT VIENT AVANT L'APPEL, PAS APRÈS.
        //
        // Cette adresse est PUBLIQUE et ne porte aucun jeton. Un calcul coûte
        // dix-sept secondes de machine sur le serveur qui héberge aussi l'ERP
        // et sa file, et chaque cote fantaisiste écrit durablement dans la
        // table des tarifs. Sans borne, une boucle depuis une seule adresse
        // occupait la file en permanence.
        //
        // On ne compte que les calculs RÉELLEMENT déclenchés : une cote déjà
        // mesurée répond sans rien coûter, et la compter punirait le visiteur
        // qui explore les formats — c'est-à-dire l'usage normal.
        $debit = new \Eko\SyncImprimerie\Configurateur\DebitDevis();
        $empreinte = $debit->empreinte();

        if (!$debit->autorise($empreinte)) {
            // Un devis reste ouvert : on ferme le calcul automatique, pas la
            // vente.
            return $this->refus(
                'Vous avez demandé beaucoup de dimensions sur mesure. '
                . 'Écrivez-nous pour un devis, nous répondons vite.',
                ReponseJson::REFUS
            );
        }

        // ⚠️ ON DEMANDE UN DEVIS, PAS UNE LECTURE.
        //
        // Ces produits se vendent à la dimension : la cote que le visiteur
        // vient de taper n'a le plus souvent jamais été mesurée. Cette adresse
        // rend le tarif SUR-LE-CHAMP quand il existe déjà, et le fait CALCULER
        // sinon — une vingtaine de secondes chez le fournisseur, pendant
        // lesquelles la fiche patiente.
        //
        // Déduire est exclu : la loi de prix n'est pas monotone en surface,
        // 9 920 cm² coûte moins cher que 9 600. Un montant déduit serait
        // crédible et faux.
        $r = $module->client()->appeler(
            'POST',
            '/api/v1/realisaprint/products/' . $code . '/quote',
            ['config' => $config]
        );

        if (!$r['ok']) {
            return $this->refus(
                $this->motDuRefus($r),
                ReponseJson::statutAmont((int) $r['code'])
            );
        }

        $d = $r['donnees'] ?? [];

        if (($d['status'] ?? '') === 'pending') {
            // Un calcul a été mis en file : c'est LUI qui coûte, et lui seul
            // qu'on compte.
            $debit->compter($empreinte);

            return $this->patienter($d);
        }

        return $this->formaterGrille($idProduct, $d);
    }

    /**
     * La grille de l'ERP, mise en forme pour cette boutique et ce visiteur.
     *
     * ⚠️ EXTRAITE POUR ÊTRE APPELÉE DEUX FOIS : par le devis, qui rend le
     * tarif sur-le-champ quand la cote est déjà mesurée, et par le suivi,
     * quand elle vient d'être calculée. Deux mises en forme auraient divergé —
     * et c'est là que vivent la TVA du visiteur, les arrondis et le supplément
     * de délai.
     *
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private function formaterGrille(int $idProduct, array $d): array
    {
        $precision = \Eko\SyncImprimerie\Configurateur\ReglesBoutique::precision();
        $horsTaxe = \Eko\SyncImprimerie\Configurateur\ReglesBoutique::afficheHorsTaxe();

        $supplementHt = \Eko\SyncImprimerie\Configurateur\ServicesProduit::supplement(
            $idProduct,
            $this->choixServices()
        ) / 100;

        $cases = [];

        foreach ((array) ($d['grid'] ?? []) as $c) {
            if (!is_array($c)) {
                continue;
            }

            $q = (int) ($c['quantity'] ?? 0);
            $lotHt = (int) ($c['lot_price_cents'] ?? 0) / 100;

            if ($q <= 0 || $lotHt <= 0) {
                continue;
            }

            $lotHt += $supplementHt;
            $lot = $horsTaxe ? $lotHt : $this->avecTaxe($lotHt, $idProduct);
            $delai = (string) ($c['delay'] ?? '');

            $cases[] = [
                'quantity' => $q,
                'delay' => $delai,
                // ⚠️ J'AVAIS ÉCRIT ICI QUE CE FOURNISSEUR NE DONNAIT PAS DE
                // DÉLAI EN JOURS. C'ÉTAIT FAUX.
                //
                // Son tableau de prix porte une colonne « Date exp. » à côté
                // de chaque prix, et elle contient « J+1 », « J+5 », « J+7 ».
                // Le harnais ne lisait que les cellules de prix et jetait
                // celles-là. J'ai donc conclu de l'absence de donnée dans MA
                // lecture à son absence chez le fournisseur, et bâti sur cette
                // conclusion : la fiche n'annonçait que le mot « Express », un
                // mot qui n'engage à rien.
                //
                // L'ERP rend maintenant le nombre de jours ouvrés, et la date
                // se calcule ICI — avec le même compteur que pour l'autre
                // sous-traitant. Deux règles qui divergeraient annonceraient
                // deux dates pour la même commande.
                'date_texte' => $this->dateDepuisJours($c['delay_days'] ?? null),
                'lot' => \Eko\SyncImprimerie\Configurateur\ReglesBoutique::montant($lot, $precision),
                'lot_texte' => $this->enLettres($lot),
                'unite_texte' => $this->enLettres($lot / $q),
                'public_texte' => $horsTaxe ? $this->enLettres($this->avecTaxe($lotHt, $idProduct)) : '',
            ];
        }

        if ($cases === []) {
            return $this->refus('Ce format n\'a pas encore de tarif en ligne. Demandez-nous un devis.');
        }

        $cases = $this->poserSupplements($cases);

        return [
            'ok' => true,
            'ht' => $horsTaxe,
            'quantities' => array_values(array_unique(array_map(
                static fn (array $c): int => $c['quantity'],
                $cases
            ))),
            'grid' => $cases,
            // ⚠️ CE DRAPEAU FERME LE BOUTON D'ACHAT. L'ERP le calcule, le
            // gabarit porte déjà son message — mais le relais ne le
            // transmettait pas, et `undefined` se lit comme « frais ». Un
            // signal produit, traduit, affichable, et jamais affiché : le
            // genre de défaut qui ne se voit qu'au jour où il devait servir.
            'stale' => (bool) ($d['price_stale'] ?? false),
            // ⚠️ RENDU AU NAVIGATEUR, mais jamais affiché tel quel : `surface`
            // dit que le tarif vient d'une cote de MÊME SURFACE et non de la
            // cote demandée. Le prix est le même — mesuré, pas supposé — mais
            // la distinction doit rester lisible dans l'onglet réseau le jour
            // où un écart apparaîtra.
            'matched' => (string) ($d['matched'] ?? ''),
            'note_delai' => \Eko\SyncImprimerie\Configurateur\ServicesProduit::reglage('delai_note', $idProduct),
            'note_delai_rapide' => \Eko\SyncImprimerie\Configurateur\ServicesProduit::reglage('delai_note_rapide', $idProduct),
            'mention_prix' => \Eko\SyncImprimerie\Configurateur\ServicesProduit::reglage('mention_prix', $idProduct),
            'supplement' => $supplementHt > 0 ? $this->enLettres(
                $horsTaxe ? $supplementHt : $this->avecTaxe($supplementHt, $idProduct)
            ) : '',
        ];
    }

    /**
     * Où en est un calcul lancé pour une cote sur mesure.
     *
     * ⚠️ LE JETON VIENT DU NAVIGATEUR, et il n'est pas une autorisation : il
     * ne fait que désigner une demande. C'est l'ERP qui décide s'il la sert,
     * et la boutique n'expose ici que ce qu'il rend. Un jeton fabriqué obtient
     * un « introuvable », rien de plus.
     *
     * @return array<string, mixed>
     */
    private function suivi(int $idProduct): array
    {
        $ticket = trim((string) Tools::getValue('ticket'));

        // La forme d'un identifiant universel, et rien d'autre : sans ce
        // filtre, n'importe quelle chaîne partirait dans une adresse.
        if (preg_match('/^[0-9a-f-]{36}$/i', $ticket) !== 1) {
            return $this->refus('Demande de tarif inconnue.');
        }

        /** @var Ekosyncimprimerie $module */
        $module = $this->module;

        // ⚠️ SANS CACHE. C'est un ÉTAT qu'on relit toutes les trois secondes :
        // mis en cache, il rendrait éternellement la première réponse — « en
        // attente » — pendant que le calcul est fini depuis longtemps. Vécu le
        // 2026-08-26 : vingt-deux prix rangés, et un sablier qui tournait.
        $r = $module->client()->appeler(
            'GET',
            '/api/v1/realisaprint/quotes/' . rawurlencode($ticket),
            null,
            true
        );

        if (!$r['ok']) {
            return $this->refus($this->motDuRefus($r), ReponseJson::statutAmont((int) $r['code']));
        }

        $d = $r['donnees'] ?? [];
        $statut = (string) ($d['status'] ?? '');

        if ($statut === 'pending') {
            return $this->patienter($d);
        }

        if ($statut !== 'ready') {
            // Le fournisseur a refusé la cote, ou le calcul s'est interrompu.
            // Dans les deux cas c'est une information pour le visiteur, pas
            // une panne de la boutique : il peut changer un chiffre.
            return $this->refus(
                (string) ($d['message'] ?? "Cette dimension n'a pas pu être chiffrée.")
            );
        }

        return $this->formaterGrille($idProduct, $d);
    }

    /**
     * La réponse d'attente, telle que le navigateur la relit.
     *
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private function patienter(array $d): array
    {
        return [
            'ok' => true,
            'attente' => true,
            'ticket' => (string) ($d['ticket'] ?? ''),
            // Une estimation, jamais une promesse : la file ne calcule qu'un
            // tarif à la fois.
            'secondes' => (int) ($d['eta_seconds'] ?? 25),
        ];
    }

    /**
     * Ce qu'on dit au visiteur quand l'ERP refuse.
     *
     * ⚠️ Le message de l'ERP passe TEL QUEL quand il en donne un : c'est lui
     * qui sait pourquoi — dimension non fabriquée dans cette matière, file
     * saturée. Le remplacer par une formule générique ferait relire au
     * visiteur des choix qui étaient bons.
     *
     * @param  array<string, mixed>  $r
     */
    private function motDuRefus(array $r): string
    {
        $message = (string) ($r['donnees']['message'] ?? '');

        if ($message !== '') {
            return $message;
        }

        return ((string) $r['erreur']) !== ''
            ? (string) $r['erreur']
            : 'Tarifs indisponibles pour le moment.';
    }

    /**
     * Retient la case choisie, et son prix relu chez l'ERP.
     *
     * Même règle que pour l'aîné : le panier reçoit UN lot en quantité 1, et le
     * nombre d'exemplaires vit dans la configuration. Diviser pour reconstituer
     * un prix unitaire ferait dériver l'arrondi, et le panier afficherait un
     * autre montant que la fiche.
     *
     * @return array<string, mixed>
     */
    private function retenirConfig(int $idProduct, string $code, string $support): array
    {
        $config = $this->config($code, $support);
        $quantite = (int) Tools::getValue('quantite');
        $delai = trim((string) Tools::getValue('delai'));

        if ($config === [] || $quantite <= 0) {
            return $this->refus('Configuration incomplète.');
        }

        $panier = \Eko\SyncImprimerie\Configurateur\Personnalisation::panier($this->context);

        if ($panier === null) {
            return $this->refus('Panier indisponible.', ReponseJson::PANNE);
        }

        /** @var Ekosyncimprimerie $module */
        $module = $this->module;

        $r = $module->client()->appeler('GET', $this->requetePrix($code, $config));

        if (!$r['ok']) {
            return $this->refus(
                'Ce tarif n\'est plus disponible. Reprenez votre configuration.',
                ReponseJson::statutAmont((int) $r['code'])
            );
        }

        // On RELIT le prix chez l'ERP plutôt que de croire le navigateur : un
        // montant venu du client est un montant que la boutique n'a pas vérifié.
        $case = null;

        foreach ((array) ($r['donnees']['grid'] ?? []) as $c) {
            if (is_array($c) && (int) ($c['quantity'] ?? 0) === $quantite
                && (string) ($c['delay'] ?? '') === $delai) {
                $case = $c;
                break;
            }
        }

        if ($case === null) {
            return $this->refus('Cette quantité n\'est plus proposée pour ce délai.');
        }

        // ⚠️ LE GARDE DE FRAÎCHEUR VIT AUSSI ICI, et pas seulement à l'écran.
        // Le navigateur ferme son bouton sur une grille périmée ; une requête
        // fabriquée, elle, ne le ferme pas. Une commande passée sur un tarif
        // de six mois se règle ensuite à la main, avec le client au bout.
        if (($r['donnees']['price_stale'] ?? false) === true) {
            return $this->refus(
                'Ce tarif doit être revérifié avant commande. Demandez-nous un devis, nous répondons vite.'
            );
        }

        $lotCents = (int) ($case['lot_price_cents'] ?? 0);

        if ($lotCents <= 0) {
            return $this->refus('L\'ERP n\'a pas rendu de prix pour cette configuration.');
        }

        $choixServices = $this->choixServices();
        $lotCents += \Eko\SyncImprimerie\Configurateur\ServicesProduit::supplement($idProduct, $choixServices);

        $idCustomization = \Eko\SyncImprimerie\Configurateur\Personnalisation::retenir(
            $panier,
            $idProduct,
            $this->lisible($idProduct, $code, $config, $quantite, $delai, $choixServices,
                isset($case['delay_days']) ? (int) $case['delay_days'] : null)
        );

        if ($idCustomization <= 0) {
            return $this->refus('La configuration n\'a pas pu être retenue.');
        }

        (new \Eko\SyncImprimerie\Configurateur\PrixConfigure())->memoriser(
            $idCustomization,
            $idProduct,
            1,
            $lotCents,
            ['config' => $config, 'quantity' => $quantite, 'delay' => $delai, 'services' => $choixServices],
            // ⚠️ LE NOMBRE DE JOURS VIENT DE LA LIGNE DE TARIF, PAS DU MOT.
            //
            // `joursDe()` extrait un nombre du code de délai — « J+5 » → 5. Ce
            // code appartient à l'AUTRE sous-traitant. Celui-ci nomme ses
            // délais « urgence », « express », « standard » : aucun chiffre à
            // extraire, donc `null` rangé pour chaque commande, alors que le
            // nombre de jours était disponible juste à côté et figurait déjà
            // dans le texte lu par le client. Le récapitulatif annonçait une
            // date, la commande n'en gardait aucune.
            isset($case['delay_days']) ? (int) $case['delay_days'] : $this->joursDe($delai)
        );

        return ['ok' => true, 'id_customization' => $idCustomization, 'quantite_panier' => 1];
    }

    /**
     * La configuration transmise par le navigateur, nettoyée.
     *
     * ⚠️ La matière de la fiche est REPOSÉE ici, après le nettoyage, et la clé
     * qui la porte est retrouvée DANS LE FORMULAIRE — jamais lue dans la
     * requête. Un navigateur qui enverrait une autre valeur, ou qui omettrait
     * simplement de dire quel champ est la matière, obtiendrait sinon le prix
     * d'une matière que cette page ne vend pas.
     *
     * @return array<string, string>
     */
    private function config(string $code, string $support): array
    {
        $brut = Tools::getValue('config');

        if (!is_array($brut)) {
            return [];
        }

        $autorises = $this->variablesTarifees($code);

        if ($autorises === null) {
            // ⚠️ L'ERP est MUET, ce n'est pas le visiteur qui a mal rempli.
            // Rendre un tableau vide ici faisait dire « Configuration
            // incomplète » à une panne de serveur : le visiteur relisait ses
            // choix, qui étaient bons, et n'avait aucun moyen de comprendre.
            $this->refus(
                'Le service de tarification est momentanément indisponible. Réessayez dans un instant.',
                ReponseJson::PANNE
            );
        }

        if ($autorises === []) {
            return [];
        }

        $out = [];

        foreach ($brut as $cle => $valeur) {
            if (!is_string($cle) || preg_match(self::CLE_VALIDE, $cle) !== 1 || !is_scalar($valeur)) {
                continue;
            }

            // Ce que l'ERP ne tarife pas n'entre pas dans la demande, même
            // envoyé à la main : accepté, ce champ ne changerait pas le prix
            // mais laisserait croire qu'il l'a fait.
            if (!isset($autorises[$cle])) {
                continue;
            }

            $v = trim((string) $valeur);

            // 64 caractères : les valeurs du fournisseur sont des identifiants
            // ou des nombres. Au-delà, ce n'est plus une configuration.
            if ($v === '' || Tools::strlen($v) > 64) {
                continue;
            }

            $out[$cle] = $v;
        }

        if ($support !== '' && $out !== []) {
            $cleSupport = $this->cleSupport($code);

            if ($cleSupport !== '') {
                $out[$cleSupport] = $support;
            }
        }

        return $out;
    }

    /**
     * Les champs que l'ERP accepte de tarifer, en table.
     *
     * L'appel est servi par le cache du client HTTP du module : le formulaire
     * vient d'être demandé par la même page.
     *
     * `null` quand l'ERP n'a pas répondu — ce qui n'est PAS la même chose
     * qu'une liste vide, et ne se dit pas de la même façon au visiteur.
     *
     * @return array<string, true>|null
     */
    private function variablesTarifees(string $code): ?array
    {
        /** @var Ekosyncimprimerie $module */
        $module = $this->module;

        $r = $module->client()->appeler('GET', '/api/v1/realisaprint/products/' . $code . '/tree');

        if (!$r['ok']) {
            return null;
        }

        $out = [];

        foreach ((array) ($r['donnees']['pricing_variables'] ?? []) as $id) {
            if (is_string($id) && preg_match(self::CLE_VALIDE, $id) === 1) {
                $out[$id] = true;
            }
        }

        return $out;
    }

    /**
     * Le champ qui porte la matière, ou `''` si ce produit n'en a pas.
     *
     * Le fournisseur le nomme « Support » — c'est SA donnée, pas une convention
     * de la boutique.
     */
    private function cleSupport(string $code): string
    {
        /** @var Ekosyncimprimerie $module */
        $module = $this->module;

        $r = $module->client()->appeler('GET', '/api/v1/realisaprint/products/' . $code . '/tree');

        foreach ((array) ($r['donnees']['variables'] ?? []) as $v) {
            if (is_array($v) && ((string) ($v['name'] ?? '')) === 'Support') {
                $id = (string) ($v['id'] ?? '');

                return preg_match(self::CLE_VALIDE, $id) === 1 ? $id : '';
            }
        }

        return '';
    }

    /**
     * L'adresse de la grille, configuration comprise.
     *
     * @param  array<string, string>  $config
     */
    private function requetePrix(string $code, array $config): string
    {
        $requete = '/api/v1/realisaprint/products/' . $code . '/price';
        $premier = true;

        foreach ($config as $cle => $valeur) {
            $requete .= ($premier ? '?' : '&')
                . 'config[' . rawurlencode($cle) . ']=' . rawurlencode($valeur);
            $premier = false;
        }

        return $requete;
    }

    /**
     * La configuration en toutes lettres, pour le panier et la facture.
     *
     * ⚠️ CE TEXTE FINIT SUR UNE FACTURE. Écrit en `VARTICLE_16450_ : 2`, il ne
     * dit rien à personne. On redescend donc le formulaire pour retrouver le nom
     * de chaque champ et le libellé de chaque valeur choisie. L'appel est mis en
     * cache par le client HTTP du module, et le formulaire vient d'être servi au
     * navigateur : en pratique il ne coûte rien.
     *
     * @param  array<string, string>  $config
     * @param  array<string, string>  $services
     */
    private function lisible(
        int $idProduct,
        string $code,
        array $config,
        int $quantite,
        string $delai,
        array $services,
        ?int $jours = null
    ): string {
        /** @var Ekosyncimprimerie $module */
        $module = $this->module;

        $r = $module->client()->appeler('GET', '/api/v1/realisaprint/products/' . $code . '/tree');
        $noms = [];
        $libelles = [];

        foreach ((array) ($r['donnees']['variables'] ?? []) as $v) {
            if (!is_array($v)) {
                continue;
            }

            $id = (string) ($v['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $noms[$id] = (string) ($v['name'] ?? $id);

            foreach ((array) ($v['values'] ?? []) as $o) {
                if (is_array($o)) {
                    $libelles[$id . '=' . (string) ($o['id'] ?? '')] = (string) ($o['label'] ?? '');
                }
            }
        }

        $morceaux = [];

        foreach ($config as $cle => $valeur) {
            $nom = $noms[$cle] ?? $cle;
            $texte = $libelles[$cle . '=' . $valeur] ?? $valeur;

            // Mieux vaut un champ en clair et sa valeur brute qu'une ligne
            // absente : une configuration incomplète sur une facture se
            // remarque moins qu'un code, et se corrige moins bien.
            $morceaux[] = $nom . ' : ' . $texte;
        }

        $morceaux[] = $this->trans('Quantité', [], 'Modules.Ekosyncimprimerie.Shop') . ' : ' . $quantite;

        if ($delai !== '') {
            $morceaux[] = $this->trans('Délai', [], 'Modules.Ekosyncimprimerie.Shop')
                . ' : ' . $this->delaiEnClair($delai);
        }

        // ⚠️ LA DATE FINIT SUR LA FACTURE, et c'est voulu : « Express » ne dit
        // pas quand, et c'est justement ce que le client a acheté.
        if ($jours !== null && $jours > 0) {
            $date = $this->dateDepuisJours($jours);

            if ($date !== '') {
                $morceaux[] = $this->trans('Expédition estimée', [], 'Modules.Ekosyncimprimerie.Shop')
                    . ' : ' . $date;
            }
        }

        foreach (\Eko\SyncImprimerie\Configurateur\ServicesProduit::services($idProduct) as $sv) {
            $choisi = (string) ($services[$sv['cle']] ?? '');

            if ($choisi !== '') {
                $morceaux[] = $sv['label'] . ' : ' . $choisi;
            }
        }

        // Un retour à la ligne, comme pour l'aîné : le gabarit du panier rend
        // cette valeur en `nofilter`, et `white-space: pre-line` en fait une
        // vraie rupture. C'est aussi plus court qu'un séparateur — le champ est
        // tronqué à 255 caractères.
        return implode("\n", $morceaux);
    }

    /**
     * La date d'expédition, à partir d'un nombre de jours ouvrés.
     *
     * ⚠️ Rien quand l'ERP n'en donne pas : les lignes de grille construites
     * avant que le harnais ne lise cette colonne n'ont pas de jours. Inventer
     * une date sur une ligne qui n'en porte pas serait exactement l'erreur
     * qu'on vient de réparer, à l'envers.
     */
    private function dateDepuisJours(?int $jours): string
    {
        if ($jours === null || $jours <= 0) {
            return '';
        }

        // Le calcul vit dans `DateLivraison` : le panier en a besoin aussi, et
        // deux règles de jours ouvrés qui divergent annonceraient deux dates
        // pour la même commande.
        return \Eko\SyncImprimerie\Configurateur\DateLivraison::dans(
            $jours,
            (string) ($this->context->language->locale ?? 'fr-FR')
        );
    }

    /**
     * L'intitulé d'un délai, tel qu'un client doit le lire.
     *
     * ⚠️ CE TEXTE FINIT SUR UNE FACTURE. Ce fournisseur ne nomme pas ses
     * délais « J+3 » mais « urgence », « express », « standard » — en
     * minuscules, et en français. Recopié tel quel, il donnait « Délai :
     * standard » au panier d'une boutique anglaise, sur un document
     * comptable.
     *
     * Un libellé inconnu est rendu tel quel : le fournisseur en emploie
     * d'autres formes, et taire un délai qu'on ne sait pas nommer serait pire
     * que l'écrire brut.
     */
    private function delaiEnClair(string $delai): string
    {
        switch (Tools::strtolower($delai)) {
            case 'urgence':
                return $this->trans('Urgence', [], 'Modules.Ekosyncimprimerie.Shop');
            case 'express':
                return $this->trans('Express', [], 'Modules.Ekosyncimprimerie.Shop');
            case 'standard':
                return $this->trans('Standard', [], 'Modules.Ekosyncimprimerie.Shop');
            default:
                return $delai;
        }
    }

    /**
     * Le type de saisie d'un champ sans liste.
     *
     * Le fournisseur nomme ses types à sa façon ; on ne retient que ce qui
     * change l'écran : un nombre, une case à cocher, ou du texte.
     */
    private function typeDeSaisie(string $brut): string
    {
        $t = Tools::strtolower($brut);

        if (str_contains($t, 'check') || str_contains($t, 'bool')) {
            return 'case';
        }

        if (str_contains($t, 'num') || str_contains($t, 'int') || str_contains($t, 'float')) {
            return 'nombre';
        }

        return 'texte';
    }
}
