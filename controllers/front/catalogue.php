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
     * Ce que l'ensemble des dessins d'une réponse a le droit de peser.
     *
     * 24 000 caractères, soit l'ordre de grandeur d'une petite image — pour
     * une ligne entière de vignettes. Les dessins servis sont ceux que l'ERP
     * annonce en premier, et les suivants retombent sur le rectangle du
     * module ; l'ordre étant celui de l'arbre, il ne change pas d'un appel à
     * l'autre.
     */
    private const BUDGET_DESSINS = 24000;

    /**
     * Comme pour le chiffrage d'atelier : tout se joue dans `postProcess()`.
     *
     * `Controller::run()` appelle `initContent()` ensuite, qui meurt sans
     * gabarit — 500 sans corps ni ligne de journal.
     */
    public function postProcess()
    {
        // Une fatale dans un contrôleur AJAX de PrestaShop part en 500 SANS
        // corps ni ligne de journal : ni stderr, ni `var/logs`, rien. Ce garde
        // écrit ce que PHP a retenu en dernier, dans le seul endroit joignable
        // depuis une requête web — et il rend au navigateur un JSON honnête au
        // lieu d'un silence.
        register_shutdown_function(static function (): void {
            $e = error_get_last();

            if ($e === null || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            @file_put_contents(
                _PS_ROOT_DIR_ . '/var/logs/ekosync-fatale.log',
                sprintf("[%s] %s — %s:%d\n", date('c'), $e['message'], $e['file'], $e['line']),
                FILE_APPEND
            );
        });

        // Une exception non rattrapée part en 500 vide : le navigateur n'a
        // alors ni prix ni raison, et le visiteur voit un configurateur muet.
        // On la transforme en refus lisible — la boutique dit ce qui ne va pas
        // plutôt que de se taire.
        try {
            $reponse = $this->repondre();
        } catch (\Throwable $e) {
            @file_put_contents(
                _PS_ROOT_DIR_ . '/var/logs/ekosync-fatale.log',
                sprintf("[%s] %s — %s:%d\n", date('c'), $e->getMessage(), $e->getFile(), $e->getLine()),
                FILE_APPEND
            );

            $reponse = $this->refus('Une erreur est survenue pendant le chiffrage.');
        }

        $this->ajaxRender((string) json_encode($reponse, JSON_UNESCAPED_UNICODE));
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
                // Les prestations de l'imprimeur voyagent avec l'arbre : elles
                // ne changent pas d'une étape à l'autre, et un second appel
                // pour les obtenir doublerait les allers-retours.
                'services' => \Eko\SyncImprimerie\Configurateur\ServicesProduit::services($idProduct),
                // Les raccourcis « vente phare » du marchand. Ils voyagent avec
                // l'arbre : ils ne changent pas d'une étape à l'autre.
                'ventes' => \Eko\SyncImprimerie\Configurateur\ServicesProduit::ventesPhares($idProduct),
                // Les réassurances : elles ne changent pas d'une étape à
                // l'autre non plus, elles voyagent avec l'arbre.
                'reassurances' => \Eko\SyncImprimerie\Configurateur\ServicesProduit::reassurances($idProduct),
            ];
        }

        // ─── Retenir la configuration, juste avant l'ajout au panier ───────

        if (Tools::getValue('quoi') === 'retenir') {
            return $this->retenir($idProduct, $code);
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

            // Le supplément des prestations s'AJOUTE, il ne recalcule rien :
            // le montant du sous-traitant reste ce qu'il a rendu, au centime.
            // Et il s'ajoute ICI, côté serveur — additionné dans le navigateur,
            // ce serait un montant que la boutique n'a pas vérifié.
            $lotHt += $supplementHt;

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
                // Le prix public, POUR CEUX QUI VOIENT DU HT — revendeurs et
                // comptes B2B. Eux achètent hors taxes ; le montant qu'un
                // particulier paierait sur cette même boutique leur dit où ils
                // se situent. Un client en TTC voit déjà ce montant comme prix
                // principal : le répéter n'apprendrait rien.
                'public_texte' => $horsTaxe ? $this->enLettres($this->avecTaxe($lotHt, $idProduct)) : '',
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
            // L'heure limite de commande, si l'imprimeur en a déclaré une.
            // Aucune valeur par défaut : « commandé avant 18h » est un
            // engagement, pas une décoration.
            'note_delai' => \Eko\SyncImprimerie\Configurateur\ServicesProduit::reglage('delai_note', $idProduct),
            'note_delai_rapide' => \Eko\SyncImprimerie\Configurateur\ServicesProduit::reglage('delai_note_rapide', $idProduct),
            // La mention sous le prix — « Tout inclus, livraison offerte ».
            // Réglée par le marchand, vide par défaut : c'est une promesse
            // commerciale, elle n'a pas à être écrite à sa place.
            'mention_prix' => \Eko\SyncImprimerie\Configurateur\ServicesProduit::reglage('mention_prix', $idProduct),
            // Le supplément est rendu SÉPARÉMENT en plus d'être inclus : le
            // récapitulatif doit pouvoir montrer les deux lignes, pour qu'un
            // client rapproche son devis du prix affiché sans deviner ce qui a
            // été ajouté.
            'supplement' => $supplementHt > 0 ? $this->enLettres(
                $horsTaxe ? $supplementHt : $this->avecTaxe($supplementHt, $idProduct)
            ) : '',
        ];
    }

    /**
     * Retient la configuration choisie, et enregistre son prix.
     *
     * ─── POURQUOI CETTE ÉTAPE EXISTE ───────────────────────────────────────
     *
     * Sans elle, le bouton « Ajouter au panier » ajoutait le produit au prix
     * catalogue de PrestaShop. Mesuré en production : le configurateur
     * affichait 68,04 €, la ligne au panier valait **0,00 €**, en quantité 1,
     * sans trace de la configuration. Le chemin d'atelier faisait ce travail
     * depuis toujours ; celui de sous-traitance ne l'avait jamais fait.
     *
     * ─── POURQUOI AU MOMENT DU GESTE, ET PAS À CHAQUE GRILLE ───────────────
     *
     * Une grille rend vingt quantités par trois délais. Retenir chaque case
     * écrirait soixante lignes dont cinquante-neuf ne serviront jamais, et il
     * faudrait ensuite décider laquelle vaut. On retient la seule case que le
     * visiteur a choisie, au moment où il l'ajoute — c'est aussi le moment où
     * un prix périmé se verrait, puisque l'ERP est réinterrogé.
     *
     * ─── LA QUANTITÉ ───────────────────────────────────────────────────────
     *
     * Le panier reçoit **un lot**, pas N exemplaires. Le sous-traitant tarife
     * le lot : 50 exemplaires valent 68,04 €, et non cinquante fois un prix
     * unitaire. Diviser pour reconstituer un prix unitaire ferait dériver
     * l'arrondi — 6804 / 50 = 136,08 centimes, arrondi à 137, soit 68,50 € au
     * panier pour 68,04 € affichés. Le nombre d'exemplaires est donc porté par
     * la configuration, jamais par la quantité du panier.
     *
     * @return array<string, mixed>
     */
    private function retenir(int $idProduct, string $code): array
    {
        $chemin = implode('/', $this->selection());
        $quantite = (int) Tools::getValue('quantite');
        $delai = trim((string) Tools::getValue('delai'));

        if ($chemin === '' || $quantite <= 0) {
            return $this->refus('Configuration incomplète.');
        }

        $panier = \Eko\SyncImprimerie\Configurateur\Personnalisation::panier($this->context);

        if ($panier === null) {
            return $this->refus('Panier indisponible.');
        }

        /** @var Ekosyncimprimerie $module */
        $module = $this->module;

        $r = $module->client()->appeler(
            'GET',
            '/api/v1/printoclock/products/' . $code . '/price?path=' . rawurlencode($chemin)
        );

        if (!$r['ok']) {
            return $this->refus('Ce tarif n\'est plus disponible. Reprenez votre configuration.');
        }

        // On RELIT le prix du fournisseur au lieu de croire le navigateur. Un
        // montant qui arriverait du client serait un montant que la boutique
        // n'a pas vérifié — donc n'importe lequel.
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

        $lotCents = (int) ($case['lot_price_cents'] ?? 0);

        if ($lotCents <= 0) {
            return $this->refus('L\'ERP n\'a pas rendu de prix pour cette configuration.');
        }

        // Les prestations de l'imprimeur s'AJOUTENT, côté serveur, exactement
        // comme dans la grille affichée. Le prix du sous-traitant reste ce
        // qu'il a rendu, au centime.
        $choixServices = $this->choixServices();
        $lotCents += \Eko\SyncImprimerie\Configurateur\ServicesProduit::supplement($idProduct, $choixServices);

        $variables = [
            'path' => $chemin,
            'quantity' => $quantite,
            'delay' => $delai,
            'services' => $choixServices,
        ];

        $idCustomization = \Eko\SyncImprimerie\Configurateur\Personnalisation::retenir(
            $panier,
            $idProduct,
            $this->configurationLisible($code, $quantite, $delai, $choixServices)
        );

        if ($idCustomization <= 0) {
            return $this->refus('La configuration n\'a pas pu être retenue.');
        }

        // Quantité 1 : le panier reçoit UN lot. Le hook de prix relira
        // exactement ce couple, et PrestaShop multipliera par la quantité de
        // la ligne — deux lots feront donc deux fois le prix du lot, ce qui
        // est juste.
        (new \Eko\SyncImprimerie\Configurateur\PrixConfigure())->memoriser(
            $idCustomization,
            $idProduct,
            1,
            $lotCents,
            $variables,
            $this->joursDe($delai)
        );

        return [
            'ok' => true,
            'id_customization' => $idCustomization,
            'quantite_panier' => 1,
        ];
    }

    /**
     * La configuration en toutes lettres, pour le panier et la facture.
     *
     * ⚠️ CE TEXTE EST VU PAR LE CLIENT. Il s'affiche sous « Votre
     * personnalisation » au panier, sur la confirmation de commande, et il
     * finit sur la FACTURE. Écrit en codes de fournisseur — « 115CP · R · NOF »
     * — il ne dit rien à personne et fait mauvais effet sur un document
     * comptable. Mesuré : c'est exactement ce qu'il affichait.
     *
     * On redescend donc l'arbre pour retrouver le nom de chaque critère et de
     * chaque option choisie. Les appels sont mis en cache par le client HTTP du
     * module, et l'arbre vient d'être parcouru par le navigateur : en pratique
     * ils ne coûtent rien.
     *
     * @param  array<string, string>  $services
     */
    private function configurationLisible(string $code, int $quantite, string $delai, array $services): string
    {
        [$etapes, $libelles] = $this->nomsDuChemin($code);

        $morceaux = [];

        foreach ($this->selection() as $rang => $valeur) {
            $nom = (string) ($libelles[$valeur]['name'] ?? $valeur);
            $critere = (string) ($etapes[$rang] ?? '');

            $morceaux[] = $critere === '' ? $nom : $critere . ' : ' . $nom;
        }

        $morceaux[] = $this->trans('Quantité', [], 'Modules.Ekosyncimprimerie.Shop') . ' : ' . $quantite;

        if ($delai !== '') {
            $morceaux[] = $this->trans('Délai', [], 'Modules.Ekosyncimprimerie.Shop') . ' : ' . $delai;
        }

        // Les prestations portent leur intitulé : « Non » seul, sur une facture,
        // ne dit pas à quoi le client a répondu non.
        $catalogue = \Eko\SyncImprimerie\Configurateur\ServicesProduit::services(
            (int) Tools::getValue('id_product')
        );

        foreach ($catalogue as $sv) {
            $choisi = (string) ($services[$sv['cle']] ?? '');

            if ($choisi !== '') {
                $morceaux[] = $sv['label'] . ' : ' . $choisi;
            }
        }

        return implode(' · ', $morceaux);
    }

    /**
     * Les noms des critères et des options, en redescendant l'arbre.
     *
     * L'arbre est ÉLAGUÉ : les options d'un rang dépendent des choix
     * précédents, et le dictionnaire rendu ne couvre que le rang courant. Il
     * faut donc le parcourir en suivant la sélection du visiteur, exactement
     * comme le navigateur l'a fait.
     *
     * @return array{0: array<int, string>, 1: array<string, array<string, mixed>>}
     */
    private function nomsDuChemin(string $code): array
    {
        /** @var Ekosyncimprimerie $module */
        $module = $this->module;

        $selection = $this->selection();
        $etapes = [];
        $libelles = [];

        for ($i = 0; $i <= count($selection); $i++) {
            $requete = '/api/v1/printoclock/products/' . $code . '/tree';

            foreach (array_slice($selection, 0, $i) as $v) {
                $requete .= (str_contains($requete, '?') ? '&' : '?') . 'selection[]=' . rawurlencode($v);
            }

            $r = $module->client()->appeler('GET', $requete);

            if (!$r['ok']) {
                // Mieux vaut une configuration en codes bruts qu'une commande
                // sans configuration : on garde ce qu'on a pu résoudre.
                break;
            }

            $d = $r['donnees'] ?? [];

            foreach ((array) ($d['labels'] ?? []) as $cle => $l) {
                if (is_string($cle) && is_array($l)) {
                    $libelles[$cle] = $l;
                }
            }

            $rang = (int) ($d['rank'] ?? $i);
            $etape = ((array) ($d['steps'] ?? []))[$rang] ?? null;

            if (is_array($etape) && isset($etape['label'])) {
                $etapes[$i] = (string) $etape['label'];
            }
        }

        return [$etapes, $libelles];
    }

    /**
     * Le nombre de jours ouvrés porté par un code de délai — « J+5 » → 5.
     *
     * Rend `null` quand le code ne dit pas de nombre : mieux vaut pas de délai
     * qu'un délai inventé, qui finirait sur une confirmation de commande.
     */
    private function joursDe(string $delai): ?int
    {
        return preg_match('/(\d+)/', $delai, $m) === 1 ? (int) $m[1] : null;
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
        $depense = 0;

        foreach ($brut as $code => $l) {
            if (!is_array($l) || !is_string($code)) {
                continue;
            }

            $entree = ['name' => (string) ($l['name'] ?? '')];

            if (isset($l['description']) && $l['description'] !== '') {
                $entree['description'] = (string) $l['description'];
            }

            $svg = $this->dessin($l['svg'] ?? null, self::BUDGET_DESSINS - $depense);

            if ($svg !== null) {
                $entree['svg'] = $svg;
                $depense += mb_strlen($svg);
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
     * Un dessin d'option, si on peut raisonnablement l'afficher.
     *
     * ─── LE POIDS ──────────────────────────────────────────────────────────
     *
     * Ces dessins viennent du fournisseur et pèsent 7 Ko en moyenne, jusqu'à
     * 65 Ko. Quatorze formats sur une ligne, c'est cent kilo-octets de balisage
     * pour des vignettes de cinquante pixels — payés par le visiteur, sur son
     * mobile, avant de voir un prix.
     *
     * DEUX plafonds, parce qu'un seul ne tenait pas : un dessin de 5 Ko passe
     * la limite individuelle sans rien signaler, et quatorze dessins de 5 Ko
     * font 75 Ko. Le plafond qui compte est donc le CUMUL — c'est lui que la
     * page paye. Le plafond par dessin reste, il écarte l'aberration isolée.
     *
     * Au-delà, on rend `null` et le module retombe sur le rectangle qu'il
     * dessine lui-même : à cette taille, un tracé détaillé et un rectangle
     * proportionnel se ressemblent, mais l'un coûte cent fois moins.
     *
     * ─── CE QU'ON RETIRE ───────────────────────────────────────────────────
     *
     * Un SVG inséré dans une page est du BALISAGE, pas une image : il peut
     * porter du script et des gestionnaires d'événements. Aucun de ceux du
     * catalogue n'en contient aujourd'hui — vérifié sur les 321 entrées — mais
     * le dictionnaire se remplit depuis le site du fournisseur, et « aujourd'hui
     * il n'y en a pas » n'est pas une garantie sur ce qu'il contiendra demain.
     *
     * On retire donc, plutôt que de faire confiance.
     */
    private function dessin(mixed $brut, int $reste): ?string
    {
        if (!is_string($brut)) {
            return null;
        }

        // Un fichier SVG commence rarement par `<svg` : il porte d'abord son
        // prologue XML, parfois un DOCTYPE, souvent la signature du logiciel qui
        // l'a produit. Exiger `<svg` en tête revient à tout refuser sans le dire
        // — c'est ce qui écartait les quatorze dessins d'un coup.
        $svg = preg_replace('#^(?:\s|<\?xml\b[^>]*\?>|<!DOCTYPE[^>]*>|<!--.*?-->)+#is', '', trim($brut)) ?? '';

        if ($svg === '' || !str_starts_with($svg, '<svg') || mb_strlen($svg) > min(12000, $reste)) {
            return null;
        }

        // Scripts, gestionnaires d'événements, ressources externes et liens :
        // rien de tout cela n'a sa place dans une vignette de format.
        $svg = preg_replace('#<script\b.*?</script>#is', '', $svg) ?? '';
        $svg = preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $svg) ?? '';
        $svg = preg_replace('#<(foreignObject|image|use|a)\b#i', '<!--$1', $svg) ?? '';

        return str_starts_with(trim($svg), '<svg') ? $svg : null;
    }

    /**
     * Les prestations choisies par le visiteur.
     *
     * On ne retient que les clés de prestations connues : une clé inventée
     * dans la requête ne doit pas pouvoir désigner un supplément, et le calcul
     * lui-même ne lit de toute façon que les options réellement déclarées.
     *
     * @return array<string, string>
     */
    private function choixServices(): array
    {
        $brut = Tools::getValue('services');

        if (!is_array($brut)) {
            return [];
        }

        $sortie = [];

        foreach (\Eko\SyncImprimerie\Configurateur\ServicesProduit::SERVICES as $cle) {
            if (isset($brut[$cle]) && is_scalar($brut[$cle])) {
                $sortie[$cle] = (string) $brut[$cle];
            }
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

        // ⚠️ `getCurrentLocale()` de PrestaShop met en forme des NOMBRES et des
        // prix, pas des dates : il n'a pas de `getDateTimeFormatter()`. L'appel
        // levait donc systématiquement, et le repli « 19/08/2026 » s'affichait
        // toujours — un repli qui marche trop bien cache le défaut qu'il couvre.
        if (class_exists('\IntlDateFormatter')) {
            $formateur = new \IntlDateFormatter(
                (string) ($this->context->language->locale ?? 'fr-FR'),
                \IntlDateFormatter::FULL,
                \IntlDateFormatter::NONE,
                null,
                null,
                'EEEE d MMMM'
            );

            $texte = $formateur->format($date);

            if (is_string($texte) && $texte !== '') {
                return $texte;
            }
        }

        // Sans `intl`, une forme neutre plutôt que rien : la date reste juste,
        // seule sa mise en forme est pauvre.
        return $date->format('d/m/Y');
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
