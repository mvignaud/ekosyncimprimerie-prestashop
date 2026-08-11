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

        $ekoProductId = (new LiaisonProduit())->pour($idProduct);

        if ($ekoProductId === null) {
            return $this->refus('Cette fiche n\'est pas liée à un produit d\'atelier.');
        }

        $panier = $this->panier();

        if ($panier === null) {
            // Sans panier, aucune configuration ne peut être identifiée, donc
            // aucun prix ne pourrait survivre jusqu'à la commande.
            return $this->refus('Panier indisponible.');
        }

        $idCustomization = $this->configurationEnCours($panier, $idProduct, $variables);

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

        // ⚠️ LISTE BLANCHE. On nomme ce qui sort ; tout le reste — coûts,
        // marge, détail des matières — ne sort pas, aujourd'hui ni après un
        // ajout de champ côté ERP.
        return [
            'ok' => true,
            'id_customization' => $idCustomization,
            'quantity' => $quantite,
            'unit_price_ht' => round($centimes / 100, 2),
            'unit_price' => round($this->avecTaxe($centimes / 100, $idProduct), 2),
            'total_price' => round($this->avecTaxe($centimes / 100, $idProduct) * $quantite, 2),
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
    private function configurationEnCours(Cart $panier, int $idProduct, array $variables): int
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

        // La configuration est écrite dans le champ texte : le panier et la
        // facture l'affichent, et le client voit sur quoi il s'est engagé.
        $id = $panier->addTextFieldToProduct(
            $idProduct,
            $champ,
            Product::CUSTOMIZE_TEXTFIELD,
            (string) json_encode($variables, JSON_UNESCAPED_UNICODE),
            true
        );

        return is_numeric($id) ? (int) $id : 0;
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
        $groupe = (int) ($this->context->customer->id_default_group ?? 0);
        $afficheHt = $groupe > 0 && (int) Group::getPriceDisplayMethod($groupe) === PS_TAX_EXC;

        if ($afficheHt) {
            return $ht;
        }

        // `Address::initialize()` et pas `new Address(...)` : un visiteur qui
        // n'a pas encore d'adresse n'en a PAS, et `TaxManagerFactory` refuse
        // `null`. PrestaShop sait fabriquer l'adresse par défaut de la
        // boutique — pays, région — c'est exactement ce qu'il faut ici, et
        // c'est ce qu'il fait lui-même pour un panier anonyme.
        $adresse = Address::initialize(
            (int) ($this->context->cart->id_address_invoice ?? 0) ?: null,
            true
        );

        return TaxManagerFactory::getManager(
            $adresse,
            (int) Product::getIdTaxRulesGroupByIdProduct($idProduct)
        )->getTaxCalculator()->addTaxes($ht);
    }

    /** @return array{ok: false, message: string} */
    private function refus(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }

}
