<?php

/**
 * EKO Sync — Imprimerie
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

/**
 * L'arbre d'options et la grille tarifaire d'un produit de sous-traitance.
 *
 * ─── POURQUOI CE RELAIS EXISTE ─────────────────────────────────────────────
 *
 * Le navigateur ne peut pas interroger l'ERP directement, pour deux raisons
 * qui suffisent chacune :
 *
 *   — l'appel porterait le JETON de la boutique, qui deviendrait lisible dans
 *     l'onglet réseau du premier visiteur venu ;
 *   — l'ERP ne répond pas aux requêtes d'une autre origine.
 *
 * Le module relaie donc : le navigateur parle à sa propre boutique, la
 * boutique parle à l'ERP avec son jeton, et le jeton ne sort jamais du serveur.
 *
 * ─── LA RÈGLE DE SORTIE ────────────────────────────────────────────────────
 *
 * Comme partout ailleurs dans ce module : LISTE BLANCHE. On nomme un par un les
 * champs qui sortent. L'API de l'ERP applique déjà la sienne — elle ne rend ni
 * prix d'achat ni coefficient de marge — mais un relais qui recopierait sa
 * réponse en bloc hériterait du premier champ qu'elle ajouterait plus tard.
 */

use Eko\SyncImprimerie\Configurateur\LiaisonProduit;

class EkosyncimprimerieCatalogueModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ajax = true;

    /**
     * Comme pour le chiffrage d'atelier : tout se joue dans `postProcess()`.
     *
     * `Controller::run()` appelle `initContent()` ensuite, qui meurt sans
     * gabarit — 500 sans corps ni ligne de journal.
     */
    public function postProcess()
    {
        $this->ajaxRender((string) json_encode($this->repondre(), JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string, mixed>
     */
    private function repondre(): array
    {
        $idProduct = (int) Tools::getValue('id_product');
        $liaison = (new LiaisonProduit())->pour($idProduct);

        if ($liaison === null || $liaison['source'] !== LiaisonProduit::SOURCE_PRINTOCLOCK) {
            return $this->refus('Cette fiche n\'est pas liée au catalogue de sous-traitance.');
        }

        $code = rawurlencode($liaison['reference']);

        /** @var Ekosyncimprimerie $module */
        $module = $this->module;

        // ─── L'arbre ───────────────────────────────────────────────────────

        if (Tools::getValue('quoi') === 'arbre') {
            $selection = $this->selection();
            $requete = '/api/v1/printoclock/products/' . $code . '/tree';

            foreach ($selection as $valeur) {
                $requete .= (str_contains($requete, '?') ? '&' : '?') . 'selection[]=' . rawurlencode($valeur);
            }

            $r = $module->client()->appeler('GET', $requete);

            if (!$r['ok']) {
                return $this->refus($r['erreur'] !== '' ? $r['erreur'] : 'Options indisponibles.');
            }

            $d = $r['donnees'] ?? [];

            return [
                'ok' => true,
                'name' => (string) ($d['name'] ?? ''),
                'steps' => $this->etapes($d['steps'] ?? []),
                'rank' => (int) ($d['rank'] ?? 0),
                'options' => array_values(array_filter(
                    (array) ($d['options'] ?? []),
                    static fn ($o): bool => is_string($o) && $o !== ''
                )),
                'labels' => $this->libelles($d['labels'] ?? []),
                'complete' => (bool) ($d['complete'] ?? false),
            ];
        }

        // ─── La grille ─────────────────────────────────────────────────────

        $chemin = implode('/', $this->selection());

        if ($chemin === '') {
            return $this->refus('Configuration incomplète.');
        }

        $r = $module->client()->appeler(
            'GET',
            '/api/v1/printoclock/products/' . $code . '/price?path=' . rawurlencode($chemin)
        );

        if (!$r['ok']) {
            // Un chemin que le fournisseur ne produit pas est une information
            // utile, pas une panne : le visiteur doit pouvoir revenir en
            // arrière plutôt que rester devant un écran muet.
            return $this->refus(
                $r['code'] === 404
                    ? 'Cette combinaison n\'est pas fabriquée. Revenez sur un choix précédent.'
                    : ($r['erreur'] !== '' ? $r['erreur'] : 'Tarifs indisponibles.')
            );
        }

        $d = $r['donnees'] ?? [];
        $precision = \Eko\SyncImprimerie\Configurateur\ReglesBoutique::precision();
        $horsTaxe = \Eko\SyncImprimerie\Configurateur\ReglesBoutique::afficheHorsTaxe();

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

            $lot = $horsTaxe ? $lotHt : $this->avecTaxe($lotHt, $idProduct);

            $delai = (string) ($c['delay'] ?? '');

            $cases[] = [
                'quantity' => $q,
                'delay' => $delai,
                // La date est calculée ICI et non dans le navigateur : « J+3 »
                // ne veut rien dire tant qu'on n'a pas compté les jours ouvrés,
                // et le nom du jour dépend de la langue du visiteur. Même
                // principe que pour les montants — on ne refait pas côté client
                // ce que le serveur sait faire juste.
                'date_texte' => $this->dateDeLivraison($delai),
                // Le montant nu sert aux comparaisons du navigateur ; le texte
                // sert à l'affichage. Le navigateur ne met JAMAIS en forme un
                // prix : il ne sait ni la langue ni la devise qu'il sert.
                'lot' => \Eko\SyncImprimerie\Configurateur\ReglesBoutique::montant($lot, $precision),
                'lot_texte' => $this->enLettres($lot),
                'unite_texte' => $this->enLettres($lot / $q),
            ];
        }

        return [
            'ok' => true,
            'path' => $chemin,
            'ht' => $horsTaxe,
            'quantities' => array_values(array_unique(array_map(
                static fn (array $c): int => $c['quantity'],
                $cases
            ))),
            'grid' => $cases,
            'stale' => (bool) ($d['price_stale'] ?? false),
        ];
    }

    /**
     * La sélection du visiteur, nettoyée.
     *
     * @return list<string>
     */
    private function selection(): array
    {
        $brut = Tools::getValue('selection');

        if (!is_array($brut)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($v): string => is_scalar($v) ? trim((string) $v) : '', $brut),
            // Le chemin est assemblé avec des `/` : une valeur qui en contient
            // fabriquerait un segment de plus, donc une configuration qui n'est
            // pas celle du visiteur.
            static fn (string $v): bool => $v !== '' && !str_contains($v, '/')
        ));
    }

    /**
     * Les libellés d'options, réduits à ce qu'un écran affiche.
     *
     * Liste blanche, comme partout : le nom, la description courte, le dessin
     * et les dimensions. Un `image_url` pointerait vers l'ERP, donc une requête
     * du navigateur du client vers un serveur qui n'est pas la boutique — on ne
     * le transmet pas.
     *
     * @param  array<string, mixed>  $brut
     * @return array<string, array<string, mixed>>
     */
    private function libelles(array $brut): array
    {
        $sortie = [];

        foreach ($brut as $code => $l) {
            if (!is_array($l) || !is_string($code)) {
                continue;
            }

            $entree = ['name' => (string) ($l['name'] ?? '')];

            if (isset($l['description']) && $l['description'] !== '') {
                $entree['description'] = (string) $l['description'];
            }

            // Le SVG de l'ERP est rendu tel quel dans la page : on ne garde que
            // ce qui ressemble à un dessin, et le navigateur l'insère comme du
            // texte échappé faute de quoi ce serait une porte ouverte.
            if (isset($l['svg']) && is_string($l['svg']) && str_starts_with(trim($l['svg']), '<svg')) {
                $entree['svg'] = $l['svg'];
            }

            if (isset($l['width'], $l['height'])) {
                $entree['width'] = (float) $l['width'];
                $entree['height'] = (float) $l['height'];
            }

            $sortie[$code] = $entree;
        }

        return $sortie;
    }

    /**
     * Les étapes, réduites à ce qu'un écran affiche.
     *
     * @param  array<int, mixed>  $brut
     * @return list<array{code: string, label: string}>
     */
    private function etapes(array $brut): array
    {
        $sortie = [];

        foreach ($brut as $e) {
            if (!is_array($e)) {
                continue;
            }

            $code = (string) ($e['code'] ?? '');

            if ($code === '') {
                continue;
            }

            $sortie[] = ['code' => $code, 'label' => (string) ($e['label'] ?? $code)];
        }

        return $sortie;
    }

    /** Le prix tel que ce visiteur doit le voir. */
    private function avecTaxe(float $ht, int $idProduct): float
    {
        return \TaxManagerFactory::getManager(
            \Eko\SyncImprimerie\Configurateur\ReglesBoutique::adresseFiscale($this->context->cart),
            (int) Product::getIdTaxRulesGroupByIdProduct($idProduct)
        )->getTaxCalculator()->addTaxes($ht);
    }

    /**
     * La date de livraison estimée pour un délai « J+N ».
     *
     * Comptée en jours OUVRÉS : un « J+3 » lancé un jeudi arrive le mardi, pas
     * le dimanche. Le samedi et le dimanche sont sautés — les jours fériés ne
     * le sont pas, faute d'un calendrier fiable ici, et c'est dit au visiteur
     * par le mot « estimée ».
     *
     * Un délai qui ne ressemble pas à « J+N » est rendu tel quel : le
     * fournisseur en emploie d'autres formes, et inventer une date sur un
     * libellé qu'on ne sait pas lire serait pire que de n'en donner aucune.
     */
    private function dateDeLivraison(string $delai): string
    {
        if (!preg_match('/^[A-Za-z]\+(\d{1,3})$/', $delai, $m)) {
            return '';
        }

        $jours = (int) $m[1];
        $date = new \DateTimeImmutable('today');

        while ($jours > 0) {
            $date = $date->modify('+1 day');

            if ((int) $date->format('N') <= 5) {
                --$jours;
            }
        }

        try {
            return (string) $this->context->getCurrentLocale()
                ->getDateTimeFormatter()
                ->format($date, 'EEEE d MMMM');
        } catch (\Throwable $e) {
            // Sans formateur de dates, on rend une forme neutre plutôt que
            // rien : la date reste juste, seule sa mise en forme est pauvre.
            return $date->format('d/m/Y');
        }
    }

    /** Un montant écrit comme la boutique l'écrit. */
    private function enLettres(float $montant): string
    {
        try {
            return (string) $this->context->getCurrentLocale()->formatPrice(
                $montant,
                (string) ($this->context->currency->iso_code ?? 'EUR')
            );
        } catch (\Throwable $e) {
            return (string) round($montant, 2);
        }
    }

    /** @return array{ok: false, message: string} */
    private function refus(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }
}
