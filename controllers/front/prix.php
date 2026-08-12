<?php

/**
 * EKO Sync — Imprimerie
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

/**
 * Chiffre une configuration et mémorise le prix. Appelé par la fiche produit.
 *
 * ─── Pourquoi ce contrôleur existe ─────────────────────────────────────────
 *
 * Le hook de prix ne doit jamais appeler l'ERP : PrestaShop l'exécute jusqu'à
 * huit fois pour une seule fiche. L'appel se fait donc ici, une fois, quand le
 * visiteur change une option — puis le hook se contente de lire.
 *
 * ─── LA RÈGLE DE SORTIE ────────────────────────────────────────────────────
 *
 * La réponse de l'ERP contient le prix de revient, le détail des coûts et la
 * marge. Rien de tout cela ne doit atteindre un navigateur.
 *
 * La réponse renvoyée ici est donc construite par LISTE BLANCHE : on nomme un
 * par un les champs qui sortent. Filtrer les champs interdits serait l'inverse,
 * et laisserait passer le premier champ que l'ERP ajouterait plus tard — une
 * fuite qu'aucun test existant ne verrait venir.
 */

use Eko\SyncImprimerie\Configurateur\LiaisonProduit;
use Eko\SyncImprimerie\Configurateur\PrixConfigure;
use Eko\SyncImprimerie\Configurateur\ReglesBoutique;

class EkosyncimprimeriePrixModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ajax = true;

    /**
     * Tout se joue ici, et pas dans `displayAjax()`.
     *
     * `Controller::run()` appelle `postProcess()` PUIS `initContent()`. Un
     * contrôleur de module qui ne rend aucun gabarit meurt dans le second : il
     * cherche un fichier de template qui n'existe pas, et la requête part en
     * 500 sans rien écrire — ni corps, ni ligne de journal. C'est pourquoi les
     * contrôleurs AJAX de PrestaShop répondent depuis `postProcess()` et
     * sortent par `ajaxRender()`, qui pose l'en-tête et termine.
     */
    public function postProcess()
    {
        $this->ajaxRender((string) json_encode($this->chiffrer(), JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array{ok: bool, ...}
     */
    private function chiffrer(): array
    {
        $idProduct = (int) Tools::getValue('id_product');
        $quantite = (int) Tools::getValue('quantity', 1);
        $variables = Tools::getValue('variables');

        if ($idProduct <= 0) {
            return $this->refus('Produit non désigné.');
        }

        if ($quantite < 1) {
            return $this->refus('La quantité doit valoir au moins 1.');
        }

        if (!is_array($variables)) {
            // Le formulaire peut envoyer du JSON plutôt qu'un tableau.
            $decode = json_decode((string) $variables, true);
            $variables = is_array($decode) ? $decode : [];
        }

        $ekoProductId = (new LiaisonProduit())->produitAtelier($idProduct);

        if ($ekoProductId === null) {
            return $this->refus('Cette fiche n\'est pas liée à un produit d\'atelier.');
        }

        $panier = $this->panier();

        if ($panier === null) {
            // Sans panier, aucune configuration ne peut être identifiée, donc
            // aucun prix ne pourrait survivre jusqu'à la commande.
            return $this->refus('Panier indisponible.');
        }

        $idCustomization = $this->configurationEnCours($panier, $idProduct, $variables, $ekoProductId);

        if ($idCustomization <= 0) {
            return $this->refus(
                'Ce produit n\'accepte pas de personnalisation : le configurateur ne peut pas retenir le choix.'
            );
        }

        /** @var Ekosyncimprimerie $module */
        $module = $this->module;

        $charge = [
            'product_id' => $ekoProductId,
            'quantity' => $quantite,
            'variables' => $variables,
        ];

        // Le tiers suffit : l'ERP lit LUI-MÊME la remise permanente du client.
        // Transmettre un taux calculé ici recréerait un second barème.
        $tierId = $this->tierDuClient();

        if ($tierId !== null) {
            $charge['client_tier_id'] = $tierId;
        }

        $r = $module->client()->appeler('POST', '/api/v1/printing/pricing/calculate', $charge);

        if (!$r['ok']) {
            // Le refus de l'ERP est une réponse, pas une panne : une
            // configuration impossible à chiffrer doit se dire au visiteur.
            return $this->refus($r['erreur'] !== '' ? $r['erreur'] : 'Configuration impossible à chiffrer.');
        }

        $donnees = $r['donnees']['data'] ?? [];
        $centimes = (int) ($donnees['unit_price_ht_cents'] ?? 0);

        if ($centimes <= 0) {
            return $this->refus('L\'ERP n\'a pas rendu de prix pour cette configuration.');
        }

        $delai = isset($donnees['estimated_lead_days']) ? (int) $donnees['estimated_lead_days'] : null;

        (new PrixConfigure())->memoriser($idCustomization, $idProduct, $quantite, $centimes, $variables, $delai);

        $unitaire = $this->avecTaxe($centimes / 100, $idProduct);
        $precision = ReglesBoutique::precision();

        // ⚠️ LISTE BLANCHE. On nomme ce qui sort ; tout le reste — coûts,
        // marge, détail des matières — ne sort pas, aujourd'hui ni après un
        // ajout de champ côté ERP.
        //
        // Les montants passent tous par ReglesBoutique : le configurateur
        // doit compter exactement comme le panier comptera, sans quoi le
        // client voit un prix sur la fiche et en paie un autre.
        return [
            'ok' => true,
            'id_customization' => $idCustomization,
            'quantity' => $quantite,
            'unit_price_ht' => ReglesBoutique::montant($centimes / 100, $precision),
            'unit_price' => ReglesBoutique::montant($unitaire, $precision),
            'total_price' => ReglesBoutique::total($unitaire, $quantite, $precision),
            // Les mêmes montants, écrits comme la boutique les écrit : séparateur
            // décimal, symbole, et sa place. Le navigateur affichait « 36.13 € »
            // sur une boutique française — le JavaScript ne sait pas quelle
            // langue ni quelle devise il sert, PrestaShop si.
            'unit_price_texte' => $this->enLettres(ReglesBoutique::montant($unitaire, $precision)),
            'total_price_texte' => $this->enLettres(ReglesBoutique::total($unitaire, $quantite, $precision)),
            'lead_days' => $delai,
        ];
    }

    /**
     * Le panier du visiteur, créé s'il n'en a pas encore.
     *
     * Un visiteur qui arrive sur une fiche produit n'a pas de panier :
     * PrestaShop n'en crée un qu'au premier ajout. Or le configurateur doit
     * afficher un prix AVANT l'ajout, et ce prix a besoin d'une identité de
     * configuration — donc d'un panier.
     *
     * On en crée un exactement comme le fait PrestaShop lui-même quand on
     * ajoute un article : rattaché au client s'il est connecté, à la langue et
     * à la devise en cours. Un panier vide ne coûte rien et ne se voit pas.
     */
    private function panier(): ?Cart
    {
        $panier = $this->context->cart;

        if (Validate::isLoadedObject($panier)) {
            return $panier;
        }

        $panier = new Cart();
        $panier->id_lang = (int) $this->context->language->id;
        $panier->id_currency = (int) $this->context->currency->id;
        $panier->id_guest = (int) ($this->context->cookie->id_guest ?? 0);
        $panier->id_shop_group = (int) $this->context->shop->id_shop_group;
        $panier->id_shop = (int) $this->context->shop->id;
        $panier->id_customer = (int) ($this->context->customer->id ?? 0);

        if ($panier->id_customer > 0) {
            $panier->id_address_delivery = (int) Address::getFirstCustomerAddressId($panier->id_customer);
            $panier->id_address_invoice = $panier->id_address_delivery;
        }

        if (!$panier->add()) {
            return null;
        }

        // Le rattacher au contexte ET au cookie : sans quoi l'appel suivant
        // en créerait un autre, et la configuration du premier serait perdue.
        $this->context->cart = $panier;
        $this->context->cookie->id_cart = (int) $panier->id;
        $this->context->cookie->write();

        return $panier;
    }

    /**
     * La configuration en cours pour ce produit, créée si besoin.
     *
     * PrestaShop identifie une configuration par une « customization ». Elle
     * naît sur la fiche produit avec `in_cart = 0`, bascule à 1 au passage au
     * panier, et son identifiant est recopié dans la ligne de commande : c'est
     * ce fil qui fait qu'un prix calculé au premier clic vaut encore sur la
     * facture.
     *
     * @param  array<string,mixed>  $variables
     */
    private function configurationEnCours(Cart $panier, int $idProduct, array $variables, int $ekoProductId = 0): int
    {
        $champ = (int) Db::getInstance()->getValue(
            'SELECT cf.`id_customization_field` FROM `' . _DB_PREFIX_ . 'customization_field` cf'
            . ' WHERE cf.`id_product` = ' . (int) $idProduct
            . ' AND cf.`type` = ' . (int) Product::CUSTOMIZE_TEXTFIELD
            . ' ORDER BY cf.`id_customization_field` ASC'
        );

        if ($champ <= 0) {
            return 0;
        }

        // La configuration est écrite dans le champ texte : le panier, la
        // commande ET LA FACTURE l'affichent. Elle doit donc SE LIRE.
        //
        // Elle y allait en JSON brut — « {"width":"1000","height":"700"} » —
        // affiché tel quel au client sous « Votre personnalisation », et promis
        // à finir sur un document comptable. On la met en toutes lettres.
        $id = $panier->addTextFieldToProduct(
            $idProduct,
            $champ,
            Product::CUSTOMIZE_TEXTFIELD,
            $this->configurationLisible($variables, $ekoProductId),
            true
        );

        return is_numeric($id) ? (int) $id : 0;
    }

    /**
     * La configuration en toutes lettres, avec les libellés de l'ERP.
     *
     * « Largeur : 1000 mm · Hauteur : 700 mm · Bâche : POWERJET 440g »
     *
     * Les libellés, unités et intitulés d'options viennent du même endroit que
     * les champs du formulaire — l'ERP. L'appel est mis en cache par le client
     * HTTP du module, donc il ne coûte rien après le premier chiffrage.
     *
     * Si l'ERP est injoignable au moment où l'on écrit, on retombe sur les
     * clés brutes plutôt que de refuser : mieux vaut « width : 1000 » qu'une
     * commande sans sa configuration.
     *
     * @param  array<string,mixed>  $variables
     */
    private function configurationLisible(array $variables, int $ekoProductId): string
    {
        if ($variables === []) {
            return '';
        }

        $modele = [];

        if ($ekoProductId > 0) {
            /** @var Ekosyncimprimerie $module */
            $module = $this->module;
            $r = $module->client()->appeler('GET', '/api/v1/printing/products/' . $ekoProductId . '/variables');

            foreach (($r['ok'] ? ($r['donnees']['data'] ?? []) : []) as $v) {
                if (is_array($v) && isset($v['key'])) {
                    $modele[(string) $v['key']] = $v;
                }
            }
        }

        $morceaux = [];

        foreach ($variables as $cle => $valeur) {
            $def = $modele[(string) $cle] ?? [];
            $libelle = (string) ($def['label'] ?? $cle);
            $texte = (string) $valeur;

            // Une liste de choix stocke un identifiant : on écrit le nom.
            foreach ((array) ($def['options'] ?? []) as $o) {
                if (is_array($o) && (string) ($o['value'] ?? '') === $texte) {
                    $texte = (string) ($o['label'] ?? $texte);
                    break;
                }
            }

            $unite = (string) ($def['unit'] ?? '');

            $morceaux[] = $libelle . ' : ' . $texte . ($unite === '' ? '' : ' ' . $unite);
        }

        // 255 caractères : la colonne `customized_data.value` n'en prend pas
        // plus, et une troncature muette perdrait la fin de la configuration.
        return mb_substr(implode(' · ', $morceaux), 0, 255);
    }

    /** Le tiers E-KO du client connecté, s'il en a un. */
    private function tierDuClient(): ?int
    {
        $idCustomer = (int) ($this->context->customer->id ?? 0);

        if ($idCustomer <= 0) {
            return null;
        }

        $valeur = Db::getInstance()->getValue(
            'SELECT `tier_id` FROM `' . _DB_PREFIX_ . 'ekosync_tier` WHERE `id_customer` = ' . $idCustomer
        );

        return ($valeur === false || $valeur === null || $valeur === '') ? null : (int) $valeur;
    }

    /**
     * Le prix tel que ce visiteur doit le voir.
     *
     * L'ERP ne rend que du HT. Afficher HT ou TTC dépend du groupe du client,
     * et la TVA de son pays : PrestaShop sait les deux, on lui laisse le
     * calcul plutôt que d'appliquer un taux de notre cru.
     */
    private function avecTaxe(float $ht, int $idProduct): float
    {
        if (ReglesBoutique::afficheHorsTaxe()) {
            return $ht;
        }

        return TaxManagerFactory::getManager(
            ReglesBoutique::adresseFiscale($this->context->cart),
            (int) Product::getIdTaxRulesGroupByIdProduct($idProduct)
        )->getTaxCalculator()->addTaxes($ht);
    }

    /**
     * Un montant écrit comme la boutique l'écrit.
     *
     * `formatPrice()` du « locale » PrestaShop connaît la langue du visiteur,
     * la devise en cours, le séparateur décimal et la place du symbole. Un
     * `number_format()` de notre cru aurait à redevenir juste pour chaque
     * langue et chaque devise — c'est du travail déjà fait, et mieux.
     */
    private function enLettres(float $montant): string
    {
        $devise = $this->context->currency->iso_code ?? 'EUR';

        try {
            return (string) $this->context->getCurrentLocale()->formatPrice($montant, (string) $devise);
        } catch (\Throwable $e) {
            // Un thème ou une version sans « locale » ne doit pas faire échouer
            // le chiffrage : on rend le nombre nu, le prix reste juste.
            return (string) $montant;
        }
    }

    /** @return array{ok: false, message: string} */
    private function refus(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }

}
