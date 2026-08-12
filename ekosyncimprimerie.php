<?php

/**
 * EKO Sync — Imprimerie
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

/**
 * EKO Sync — Imprimerie
 *
 * Relie la boutique à l'ERP E-KO : catalogue, tarifs, documents, fichiers.
 *
 * PRINCIPE FONDATEUR : le tarif affiché sur le
 * site doit être RIGOUREUSEMENT celui d'un devis produit dans E-KO. Ce module
 * ne calcule donc aucun prix. Il demande, il met en cache la réponse, il
 * affiche. Toute tentative de recalculer localement — même « juste pour un
 * repli » — recrée deux vérités et brise l'égalité.
 *
 * Le partage des responsabilités qui en découle :
 *   PrestaShop  → la présentation : produits publiés, options montrées, ordre,
 *                 libellés, visuels, groupes de clients.
 *   E-KO        → les coûts, les barèmes, les clés de variables et leurs
 *                 valeurs autorisées.
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/src/Client/ClientEko.php';
require_once __DIR__ . '/src/Client/DepotEko.php';
require_once __DIR__ . '/src/Client/Groupes.php';
require_once __DIR__ . '/src/Client/ImportTiers.php';
require_once __DIR__ . '/src/Configurateur/ReglesBoutique.php';
require_once __DIR__ . '/src/Configurateur/PrixConfigure.php';
require_once __DIR__ . '/src/Configurateur/LiaisonProduit.php';

use Eko\SyncImprimerie\Client\ClientEko;
use Eko\SyncImprimerie\Client\DepotEko;
use Eko\SyncImprimerie\Client\Groupes;
use Eko\SyncImprimerie\Client\ImportTiers;
use Eko\SyncImprimerie\Configurateur\LiaisonProduit;
use Eko\SyncImprimerie\Configurateur\PrixConfigure;

class Ekosyncimprimerie extends Module
{
    public const CLE_BASE = 'EKOSYNC_BASE_URL';
    public const CLE_JETON = 'EKOSYNC_JETON';
    public const CLE_CACHE_S = 'EKOSYNC_CACHE_SECONDES';

    /**
     * Durée de cache par défaut, en secondes.
     *
     * Une CONSTANTE et non une valeur écrite à l'installation : sur cet
     * hébergement, `Module::install()` lève « Call to a member function get()
     * on null » dans Language.php — `SymfonyContainer::getInstance()` rend null
     * hors du back-office. Le module se retrouvait enregistré mais sans aucun
     * réglage, et l'échec passait inaperçu.
     *
     * Ne rien faire écrire à install() supprime le problème à la racine :
     * le module fonctionne qu'il ait été posé depuis le BO, un script ou la
     * ligne de commande.
     */
    public const CACHE_DEFAUT = 900;

    public function __construct()
    {
        $this->name = 'ekosyncimprimerie';
        $this->tab = 'front_office_features';
        $this->version = '0.9.0';
        $this->author = '2M Numérique';
        $this->need_instance = 0;
        // PrestaShop 9 impose PHP 8.1, que ce module exige (proprietes promues
        // en lecture seule). Annoncer PrestaShop 8, qui tourne encore sur PHP
        // 7.2, laisserait un marchand deposer le module puis perdre son
        // back-office sur une erreur fatale : les `require_once` sont evalues
        // avant que le moindre garde puisse s'executer.
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('EKO Sync — Imprimerie', [], 'Modules.Ekosyncimprimerie.Admin');
        $this->description = $this->trans(
            'Relie la boutique à l\'ERP E-KO : catalogue, tarifs, documents et fichiers clients. Les tarifs affichés sont ceux calculés par E-KO, sans recalcul local.',
            [],
            'Modules.Ekosyncimprimerie.Admin'
        );
        $this->confirmUninstall = $this->trans(
            'Désinstaller le module coupera la liaison avec E-KO. Les tarifs ne seront plus disponibles sur les fiches configurables.',
            [],
            'Modules.Ekosyncimprimerie.Admin'
        );
    }

    public function install(): bool
    {
        // Un seul hook, et c'est le point de bascule du configurateur : c'est
        // par lui que le prix calculé par l'ERP entre dans PrestaShop. Trois
        // chemins y convergent — fiche produit, panier, et la commande, qui
        // FIGE le prix dans `order_detail`. La facture ne recalcule donc rien.
        //
        // Toujours aucune écriture de réglage ici : voir CACHE_DEFAUT. Les
        // réglages n'existent qu'une fois saisis, et leur absence est gérée à
        // la lecture — ce qui rend le module installable depuis le back-office,
        // un script ou la ligne de commande indifféremment.
        $hooks = [
            // Le prix : par ou l'ERP entre dans PrestaShop.
            'actionProductPriceCalculation',
            // La liaison fiche <-> produit d'atelier, posee et lue au meme
            // endroit que le reste des reglages du produit.
            'displayAdminProductsMainStepLeftColumnMiddle',
            'actionProductSave',
            // Le configurateur lui-meme, sur la fiche produit.
            'displayProductAdditionalInfo',
            'displayProductPriceBlock',
            'actionFrontControllerSetMedia',
        ];

        if (!parent::install()) {
            return false;
        }

        foreach ($hooks as $hook) {
            if (!$this->registerHook($hook)) {
                return false;
            }
        }

        return true;
    }

    public function uninstall(): bool
    {
        // On retire les réglages : un jeton d'API qui survit à une
        // désinstallation est un secret qui traîne.
        //
        // Là non plus pas de && : `deleteByName` sur une clé absente rend
        // false, et la désinstallation échouerait pour la seule raison qu'un
        // réglage n'avait jamais été saisi.
        Configuration::deleteByName(self::CLE_BASE);
        Configuration::deleteByName(self::CLE_JETON);
        Configuration::deleteByName(self::CLE_CACHE_S);

        // Les identifiants de groupes : sans quoi « reinitialiser le module »
        // ne remet rien a zero et les gardes restent satisfaits par un id
        // perime, pointant vers un groupe qui n'existe peut-etre plus.
        foreach (Groupes::ROLES as $def) {
            Configuration::deleteByName($def['cle']);
        }

        ClientEko::viderCache();

        // La correspondance compte boutique -> tiers relie une personne
        // identifiable a un identifiant d'un systeme tiers. La laisser apres
        // desinstallation, c'est laisser une donnee personnelle qu'aucune
        // procedure de la boutique ne connait.
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'ekosync_tier`');
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'ekosync_address`');

        // Les prix mémorisés n'ont aucun sens sans le module qui les a
        // demandés : les laisser ferait resservir un tarif que plus personne
        // ne peut expliquer ni recalculer.
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'ekosync_prix`');
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'ekosync_produit`');
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'ekosync_address`');

        return parent::uninstall();
    }

    /**
     * Le choix du produit d'atelier, sur la fiche produit du back-office.
     *
     * Rendu dans le formulaire natif plutot que dans un ecran a part : le
     * marchand y pense au moment ou il pense au produit, et le champ part avec
     * l'enregistrement de la fiche, sans bouton supplementaire.
     *
     * @param  array<string,mixed>  $params
     */
    public function hookDisplayAdminProductsMainStepLeftColumnMiddle(array $params): string
    {
        $idProduct = (int) ($params['id_product'] ?? 0);

        if ($idProduct <= 0) {
            return '';
        }

        $liaison = new LiaisonProduit();
        $actuel = $liaison->produitAtelier($idProduct);

        $r = $this->client()->appeler('GET', '/api/v1/printing/products?per_page=100');

        if (!$r['ok']) {
            // L'ERP est injoignable : on le DIT plutot que d'afficher une liste
            // vide, qui se lirait comme « aucun produit d'atelier ».
            return $this->panneauProduit(
                '<p class="alert alert-warning">'
                . $this->trans('Liste des produits d\'atelier indisponible : ', [], 'Modules.Ekosyncimprimerie.Admin')
                . htmlspecialchars($r['erreur'])
                . '</p>'
            );
        }

        $produits = $r['donnees']['data'] ?? [];
        $options = '<option value="0">' . $this->trans('— aucun, prix géré par PrestaShop —', [], 'Modules.Ekosyncimprimerie.Admin') . '</option>';

        foreach (is_array($produits) ? $produits : [] as $p) {
            if (!is_array($p)) {
                continue;
            }

            $id = (int) ($p['id'] ?? 0);
            $options .= sprintf(
                '<option value="%d"%s>%s — %s</option>',
                $id,
                $id === $actuel ? ' selected' : '',
                htmlspecialchars((string) ($p['code'] ?? '')),
                htmlspecialchars((string) ($p['name'] ?? ''))
            );
        }

        return $this->panneauProduit(
            '<select name="ekosync_produit_atelier" class="form-control">' . $options . '</select>'
            . '<p class="help-block">'
            . $this->trans(
                'Lier cette fiche à un produit d\'atelier fait venir son prix de l\'ERP. Sans liaison, PrestaShop garde la main.',
                [],
                'Modules.Ekosyncimprimerie.Admin'
            )
            . '</p>'
        );
    }

    /** L'encadré qui porte le choix, pour n'écrire son gabarit qu'une fois. */
    private function panneauProduit(string $contenu): string
    {
        return '<div class="form-group"><label class="form-control-label">'
            . $this->trans('Produit d\'atelier E-KO', [], 'Modules.Ekosyncimprimerie.Admin')
            . '</label>' . $contenu . '</div>';
    }

    /**
     * Enregistre la liaison en même temps que la fiche.
     *
     * Le champ n'est lu QUE s'il est present dans la requete : une sauvegarde
     * declenchee par un autre ecran — import, script, autre module — ne doit
     * pas rompre une liaison a laquelle personne n'a touche.
     *
     * @param  array<string,mixed>  $params
     */
    public function hookActionProductSave(array $params): void
    {
        $idProduct = (int) ($params['id_product'] ?? 0);

        if ($idProduct <= 0 || !Tools::getIsset('ekosync_produit_atelier')) {
            return;
        }

        (new LiaisonProduit())->lier(
            $idProduct,
            LiaisonProduit::SOURCE_ATELIER,
            (string) Tools::getValue('ekosync_produit_atelier')
        );
    }

    /**
     * Le configurateur, sur la fiche produit.
     *
     * Les champs viennent de l'ERP : lui seul sait ce qu'un produit accepte.
     * Ajouter une option a un produit dans l'atelier la fait apparaitre sur la
     * boutique sans toucher au theme ni au module.
     *
     * @param  array<string,mixed>  $params
     */
    /**
     * Le configurateur, rendu LA OU ETAIT LE PRIX.
     *
     * `displayProductAdditionalInfo` le posait SOUS le bouton d'ajout au
     * panier : le client voyait un formulaire de personnalisation et un bouton
     * « Ajouter au panier », et ne decouvrait le configurateur — donc le prix —
     * qu'en descendant. Il faut configurer AVANT de commander, pas apres.
     *
     * `displayProductPriceBlock` en `after_price` place le bloc exactement ou
     * le theme affiche son prix, que le module masque par ailleurs. C'est un
     * hook du cœur, present dans les gabarits de tous les themes qui heritent
     * du formulaire produit standard.
     *
     * ⚠️ Ce hook est AUSSI appele pour chaque vignette d'une liste de produits.
     * Sans le garde sur `ProductController`, une categorie de trente articles
     * declencherait trente rendus du configurateur — et autant d'appels a
     * l'ERP pour construire les champs.
     *
     * @param  array<string,mixed>  $params
     */
    public function hookDisplayProductPriceBlock(array $params): string
    {
        if (($params['type'] ?? '') !== 'after_price') {
            return '';
        }

        if (!($this->context->controller instanceof ProductController)) {
            return '';
        }

        return $this->hookDisplayProductAdditionalInfo($params);
    }

    /**
     * La fiche technique d'un produit : ce que l'imprimeur attend d'un fichier.
     *
     * Ces valeurs ne viennent PAS de l'ERP. L'ERP sait ce que le fournisseur
     * FABRIQUE ; la fiche technique dit ce qu'il faut LUI ENVOYER — résolution,
     * mode colorimétrique, fonds perdus, marge de sécurité. C'est une propriété
     * de l'imprimeur, pas du catalogue.
     *
     * Elles se règlent donc au back-office, produit par produit, avec des
     * valeurs par défaut qui sont les usages du métier. Un marchand qui ne
     * touche à rien obtient une fiche juste ; celui qui a des exigences propres
     * les pose sans toucher au code.
     *
     * @return array{lignes: list<array{label: string, valeur: string}>, gabarits: list<array{nom: string, url: string}>}
     */
    private function ficheTechnique(int $idProduct): array
    {
        // Les libellés sont écrits EN TOUTES LETTRES dans l'appel à `trans()`.
        //
        // Les passer en variable — `$this->trans($libelle, …)` — était le
        // premier jet, et il était faux : l'extraction ne lit que des
        // littéraux. Les quatre chaînes échappaient donc à toute traduction, et
        // aucun garde ne pouvait le voir puisqu'elles n'apparaissaient nulle
        // part comme texte. C'est le générateur de catalogue qui les a
        // signalées, en orphelines.
        $defauts = [
            'resolution' => [$this->trans('Résolution', [], 'Modules.Ekosyncimprimerie.Admin'), '300 DPI'],
            'couleurs' => [$this->trans('Couleurs', [], 'Modules.Ekosyncimprimerie.Admin'), 'CMJN'],
            'fonds_perdus' => [$this->trans('Fonds perdus', [], 'Modules.Ekosyncimprimerie.Admin'), '2 mm'],
            'marge' => [$this->trans('Marge de sécurité', [], 'Modules.Ekosyncimprimerie.Admin'), '4 mm'],
        ];

        $lignes = [];

        foreach ($defauts as $cle => [$libelle, $defaut]) {
            $valeur = Configuration::get('EKOSYNC_TECH_' . strtoupper($cle) . '_' . $idProduct);

            if ($valeur === false || $valeur === '') {
                $valeur = Configuration::get('EKOSYNC_TECH_' . strtoupper($cle));
            }

            if ($valeur === false || $valeur === '') {
                $valeur = $defaut;
            }

            $lignes[] = ['label' => $libelle, 'valeur' => (string) $valeur];
        }

        // Les gabarits sont des FICHIERS : on ne peut pas les inventer. Tant
        // que le marchand n'en a déposé aucun, la colonne ne s'affiche pas —
        // une liste de liens morts vaut moins que pas de liste.
        $gabarits = [];
        $dossier = _PS_MODULE_DIR_ . $this->name . '/gabarits/' . $idProduct;

        if (is_dir($dossier)) {
            foreach ((array) scandir($dossier) as $f) {
                if (!is_string($f) || $f[0] === '.') {
                    continue;
                }

                $gabarits[] = [
                    'nom' => pathinfo($f, PATHINFO_FILENAME),
                    'url' => $this->context->link->getBaseLink()
                        . 'modules/' . $this->name . '/gabarits/' . $idProduct . '/' . rawurlencode($f),
                ];
            }
        }

        return ['lignes' => $lignes, 'gabarits' => $gabarits];
    }

    /**
     * Le configurateur n'est rendu QU'UNE fois par page.
     *
     * Les deux hooks restent enregistres, et c'est voulu : `after_price` place
     * le bloc au bon endroit sur les themes qui heritent du formulaire produit
     * standard, `displayProductAdditionalInfo` sert de repli sur ceux qui ne
     * l'exposent pas. Sans ce drapeau, un theme qui a les DEUX affiche deux
     * configurateurs — mesure sur Akira — et le second ecrase le premier dans
     * le JS, qui ne s'accroche qu'a la premiere racine trouvee.
     *
     * Le premier qui parle gagne. Comme `product-prices.tpl` precede
     * `product-additional-info.tpl` dans le gabarit, c'est naturellement celui
     * du bon endroit.
     */
    private bool $configurateurRendu = false;

    public function hookDisplayProductAdditionalInfo(array $params): string
    {
        if ($this->configurateurRendu) {
            return '';
        }

        $idProduct = $this->produitAffiche($params);

        if ($idProduct <= 0) {
            return '';
        }

        $liaison = (new LiaisonProduit())->pour($idProduct);

        if ($liaison === null) {
            // Fiche non liee : PrestaShop garde la main, on ne montre rien.
            return '';
        }

        // Deux natures de chiffrage, deux configurateurs. L'atelier pose des
        // champs libres et fait CALCULER un prix ; la sous-traitance descend un
        // arbre de choix et LIT une grille. Les partager aurait donne un
        // gabarit plein de conditions, illisible des deux cotes.
        if ($liaison['source'] === LiaisonProduit::SOURCE_PRINTOCLOCK) {
            $this->configurateurRendu = true;

            $this->smarty->assign('eko', [
                'id_product' => $idProduct,
                'url' => $this->context->link->getBaseLink()
                    . 'index.php?fc=module&module=' . $this->name . '&controller=catalogue',
                'technique' => $this->ficheTechnique($idProduct),
            ]);

            return $this->fetch('module:' . $this->name . '/views/templates/hook/configurateur-poc.tpl');
        }

        $ekoProductId = (new LiaisonProduit())->produitAtelier($idProduct);

        if ($ekoProductId === null) {
            return '';
        }

        $r = $this->client()->appeler('GET', '/api/v1/printing/products/' . (int) $ekoProductId . '/variables');

        if (!$r['ok']) {
            // On se tait plutot que d'afficher un formulaire vide : un
            // configurateur sans champ inviterait a commander n'importe quoi.
            return '';
        }

        $variables = $r['donnees']['data'] ?? [];

        if (!is_array($variables) || $variables === []) {
            return '';
        }

        $this->smarty->assign('eko', [
            'id_product' => $idProduct,
            'variables' => $variables,
            // URL DIRECTE, et pas `getModuleLink()`. La forme simplifiee
            // `/module/<module>/<controleur>` depend d'une regle de reecriture
            // que PrestaShop n'ajoute au `.htaccess` qu'en le regenerant : sur
            // une boutique qui ne l'a pas fait depuis l'installation du module,
            // elle repond 404 et le configurateur reste muet. Cet appel n'est
            // jamais vu par un humain ni indexe : rien ne justifie de le faire
            // dependre de l'etat du fichier de reecriture du marchand.
            'url' => $this->context->link->getBaseLink()
                . 'index.php?fc=module&module=' . $this->name . '&controller=prix',
        ]);

        $this->configurateurRendu = true;

        return $this->fetch('module:' . $this->name . '/views/templates/hook/configurateur.tpl');
    }

    /**
     * Charge le script du configurateur, et LUI SEUL quand il sert.
     *
     * Le poser sur toutes les pages ferait payer a chaque visiteur un fichier
     * qui ne concerne que les fiches configurables.
     */
    public function hookActionFrontControllerSetMedia(): void
    {
        if ($this->context->controller instanceof ProductController) {
            $this->context->controller->registerJavascript(
                'ekosync-configurateur',
                'modules/' . $this->name . '/views/js/configurateur.js',
                ['position' => 'bottom', 'priority' => 200]
            );

            // Le configurateur de sous-traitance a son propre script : il
            // descend un arbre au lieu de poster des champs libres. Charger les
            // deux partout couterait un fichier inutile a chaque visiteur ; on
            // ne pose celui-ci que sur une fiche qui en depend.
            $idProduct = (int) Tools::getValue('id_product');

            if ($idProduct > 0) {
                $l = (new LiaisonProduit())->pour($idProduct);

                if ($l !== null && $l['source'] === LiaisonProduit::SOURCE_PRINTOCLOCK) {
                    $this->context->controller->registerJavascript(
                        'ekosync-configurateur-poc',
                        'modules/' . $this->name . '/views/js/configurateur-poc.js',
                        ['position' => 'bottom', 'priority' => 201]
                    );
                    $this->context->controller->registerStylesheet(
                        'ekosync-configurateur-poc',
                        'modules/' . $this->name . '/views/css/configurateur-poc.css',
                        ['media' => 'all', 'priority' => 201]
                    );
                }
            }

            // Le module masque le bloc prix du theme sur les fiches liees : il
            // doit donc porter lui-meme le poids visuel d'un prix. Sans cette
            // feuille, le configurateur herite de ce que le theme veut bien
            // donner — et le montant se perd au milieu du formulaire.
            $this->context->controller->registerStylesheet(
                'ekosync-configurateur',
                'modules/' . $this->name . '/views/css/configurateur.css',
                ['media' => 'all', 'priority' => 200]
            );
        }
    }

    /**
     * L'identifiant du produit affiche, quelle que soit la forme des parametres.
     *
     * Selon le theme et la version, le hook recoit un tableau, un objet
     * `Product`, ou rien du tout — auquel cas la requete fait foi.
     *
     * @param  array<string,mixed>  $params
     */
    private function produitAffiche(array $params): int
    {
        $produit = $params['product'] ?? null;

        if (is_array($produit) && isset($produit['id_product'])) {
            return (int) $produit['id_product'];
        }

        if (is_object($produit) && isset($produit->id_product)) {
            return (int) $produit->id_product;
        }

        if (is_object($produit) && isset($produit->id)) {
            return (int) $produit->id;
        }

        return (int) Tools::getValue('id_product');
    }

    /**
     * Le prix d'une configuration entre ici, et nulle part ailleurs.
     *
     * ⚠️ CE HOOK NE DOIT JAMAIS APPELER L'ERP. PrestaShop l'exécute jusqu'à huit
     * fois pour une seule fiche produit et quatre fois par ligne de panier : un
     * appel réseau à quinze secondes de délai rendrait la boutique inutilisable
     * au premier incident. Le prix a été calculé et enregistré en amont, par le
     * contrôleur qui répond au changement d'option. Ici, on lit.
     *
     * Pourquoi ce hook et pas le mécanisme natif `customized_data.price` : ce
     * dernier n'est consulté que si l'identifiant de configuration est transmis
     * au calcul de prix. Le panier et la commande le transmettent ; **la fiche
     * produit ne le transmet jamais**. On aurait donc eu le bon prix au panier
     * et le prix catalogue sur la fiche — deux vérités, ce que ce module existe
     * précisément pour éviter.
     *
     * @param  array<string,mixed>  $params
     */
    public function hookActionProductPriceCalculation(array &$params): void
    {
        $idProduct = (int) ($params['id_product'] ?? 0);
        $quantite = max(1, (int) ($params['quantity'] ?? 1));

        if ($idProduct <= 0) {
            return;
        }

        $prix = new PrixConfigure();

        // Panier et commande désignent la configuration ; la fiche produit, non
        // — il faut alors la retrouver parmi celles en attente de ce panier.
        $idCustomization = (int) ($params['id_customization'] ?? 0);

        if ($idCustomization <= 0) {
            $panier = $this->context->cart ?? null;

            if (!$panier instanceof \Cart) {
                return;
            }

            $idCustomization = $prix->customizationEnCours($panier, $idProduct);
        }

        if ($idCustomization <= 0) {
            // Aucune configuration en cours : ce produit n'est pas configuré
            // ici. C'est le cas d'une vignette de liste — la surcharger
            // afficherait un prix personnel hors contexte.
            return;
        }

        $centimes = $prix->lire($idCustomization, $quantite);

        if ($centimes === null) {
            // Rien de chiffré pour cette quantité. On laisse PrestaShop faire
            // plutôt que d'inventer : un prix inventé serait indétectable.
            return;
        }

        $montant = $centimes / 100;

        // L'ERP ne rend que du HT. La TVA relève du client — pays, exonération,
        // groupe d'affichage — et PrestaShop la connaît. On réutilise SON
        // calculateur plutôt que d'appliquer un taux de notre cru.
        if (!empty($params['use_tax'])) {
            $montant = \TaxManagerFactory::getManager(
                $params['address'] ?? null,
                (int) \Product::getIdTaxRulesGroupByIdProduct($idProduct)
            )->getTaxCalculator()->addTaxes($montant);
        }

        $params['price'] = $montant;
    }

    public function getContent(): string
    {
        $sortie = '';

        if (Tools::isSubmit('submitEkosync')) {
            $sortie .= $this->enregistrer();
        }

        if (Tools::isSubmit('testerEkosync')) {
            $sortie .= $this->tester();
        }

        if (Tools::isSubmit('sonderEkosync')) {
            $sortie .= $this->sonder();
        }

        if (Tools::isSubmit('groupesEkosync')) {
            $sortie .= $this->poserGroupes();
        }

        if (Tools::isSubmit('importerEkosync')) {
            $sortie .= $this->importerTiers();
        }

        return $sortie . $this->tableauGroupes() . $this->formulaire();
    }

    /**
     * L'adresse vise-t-elle le réseau interne du serveur ?
     *
     * Une adresse d'API est saisie par un administrateur puis appelée par le
     * serveur lui-même : c'est un canal de sortie. Sans ce garde, elle permet
     * d'atteindre ce que le serveur voit et que l'extérieur ne voit pas —
     * bases de données, interfaces d'administration, service de métadonnées de
     * l'hébergeur (169.254.169.254), qui rend des identifiants.
     *
     * Le contrôle porte sur l'hôte ET sur son adresse IP résolue : `https://
     * interne.exemple/` peut pointer sur 127.0.0.1.
     */
    private function adresseInterne(string $url): bool
    {
        $hote = parse_url($url, PHP_URL_HOST);

        if (!is_string($hote) || $hote === '') {
            return true;
        }

        $hote = strtolower($hote);

        if ($hote === 'localhost' || str_ends_with($hote, '.localhost') || str_ends_with($hote, '.internal')) {
            return true;
        }

        // Une IP littérale se juge telle quelle ; un nom se résout d'abord.
        $ips = filter_var($hote, FILTER_VALIDATE_IP) ? [$hote] : (gethostbynamel($hote) ?: []);

        if ($ips === []) {
            // Un nom qui ne résout pas ne sert à rien : autant le refuser ici
            // plutôt que de laisser l'appel échouer sans explication.
            return true;
        }

        foreach ($ips as $ip) {
            $publique = filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );

            if ($publique === false) {
                return true;
            }
        }

        return false;
    }

    public function groupes(): Groupes
    {
        return new Groupes();
    }

    /** Crée ce qui manque, corrige ce qui a dérivé, et rend le compte rendu. */
    private function poserGroupes(): string
    {
        $lignes = [];

        $echec = false;

        foreach ($this->groupes()->assurer() as $r) {
            $echec = $echec || $r['id'] === 0;
            $lignes[] = sprintf(
                '%s → groupe #%d (%s) : %s',
                htmlspecialchars($r['nom']),
                $r['id'],
                $r['affichage'] === Groupes::AFFICHAGE_HT ? 'HT' : 'TTC',
                htmlspecialchars($r['action'])
            );
        }

        $texte = implode('<br>', $lignes);

        return $echec ? $this->displayError($texte) : $this->displayConfirmation($texte);
    }

    /**
     * L'état de la correspondance, visible sans avoir à cliquer.
     *
     * Un réglage qu'il faut déclencher pour connaître est un réglage dont on
     * ignore l'état le reste du temps.
     */
    private function tableauGroupes(): string
    {
        $correspondance = [
            Groupes::B2C => 'type_tiers = PARTICULIER',
            Groupes::B2B => 'type_tiers = ENTREPRISE ou ADMINISTRATION',
            Groupes::ASSOCIATION => 'type_tiers = ASSOCIATION',
            Groupes::REVENDEUR => 'statut_commercial = REVENDEUR (prime sur la nature fiscale)',
        ];

        $lignes = '';

        foreach (Groupes::ROLES as $role => $def) {
            $id = $this->groupes()->identifiant($role);

            $lignes .= sprintf(
                '<tr><td><strong>%s</strong></td><td>%s</td><td>%s</td><td>%s</td></tr>',
                htmlspecialchars($def['nom']),
                htmlspecialchars($correspondance[$role] ?? ''),
                $def['affichage'] === Groupes::AFFICHAGE_HT ? 'HT' : 'TTC',
                $id > 0
                    ? '#' . $id
                    : '<span class="text-danger">' . $this->trans('non posé', [], 'Modules.Ekosyncimprimerie.Admin') . '</span>'
            );
        }

        return '<div class="panel"><div class="panel-heading"><i class="icon-users"></i> '
            . $this->trans('Groupes de clients', [], 'Modules.Ekosyncimprimerie.Admin')
            . '</div><p>'
            . $this->trans(
                'Ces groupes ne portent AUCUNE remise. Le prix remisé vient d\'E-KO : une remise posée ici serait un second calcul, avec ses propres arrondis, et le site cesserait de coïncider au centime près avec un devis.',
                [],
                'Modules.Ekosyncimprimerie.Admin'
            )
            . '</p><table class="table"><thead><tr><th>'
            . $this->trans('Groupe', [], 'Modules.Ekosyncimprimerie.Admin') . '</th><th>'
            . $this->trans('Correspond à', [], 'Modules.Ekosyncimprimerie.Admin') . '</th><th>'
            . $this->trans('Affichage', [], 'Modules.Ekosyncimprimerie.Admin') . '</th><th>'
            . $this->trans('Identifiant boutique', [], 'Modules.Ekosyncimprimerie.Admin')
            . '</th></tr></thead><tbody>' . $lignes . '</tbody></table>'
            . '<form method="post"><button type="submit" name="groupesEkosync" class="btn btn-default">'
            . '<i class="icon-refresh"></i> '
            . $this->trans('Poser / vérifier les groupes', [], 'Modules.Ekosyncimprimerie.Admin')
            . '</button></form></div>';
    }

    private function enregistrer(): string
    {
        $base = trim((string) Tools::getValue(self::CLE_BASE));
        $jeton = trim((string) Tools::getValue(self::CLE_JETON));
        $cache = (int) Tools::getValue(self::CLE_CACHE_S);

        if ($base !== '' && !filter_var($base, FILTER_VALIDATE_URL)) {
            return $this->displayError($this->trans(
                'L\'adresse de l\'API n\'est pas une URL valide.',
                [],
                'Modules.Ekosyncimprimerie.Admin'
            ));
        }

        if ($base !== '' && !str_starts_with($base, 'https://')) {
            // Le jeton circule dans un en-tête : en clair, il est lisible par
            // tout intermédiaire. On refuse plutôt que d'avertir.
            return $this->displayError($this->trans(
                'L\'API doit être appelée en HTTPS : le jeton transiterait sinon en clair.',
                [],
                'Modules.Ekosyncimprimerie.Admin'
            ));
        }

        if ($base !== '' && $this->adresseInterne($base)) {
            // Sans ce refus, l'adresse de l'API devient un canal de sortie vers
            // le réseau interne du serveur — bases de données, interfaces
            // d'administration, métadonnées de l'hébergeur — atteignable par
            // quelqu'un qui n'a que le droit de configurer les modules.
            return $this->displayError($this->trans(
                'Cette adresse pointe vers le réseau interne du serveur : refusée.',
                [],
                'Modules.Ekosyncimprimerie.Admin'
            ));
        }

        $ancienneBase = (string) Configuration::get(self::CLE_BASE);
        $changementInstance = $base !== $ancienneBase && $ancienneBase !== '';

        Configuration::updateValue(self::CLE_BASE, $base);
        Configuration::updateValue(self::CLE_CACHE_S, max(0, $cache));

        // Un champ jeton laissé vide ne doit PAS effacer le jeton enregistré :
        // le formulaire le réaffiche masqué, et l'enregistrer tel quel le
        // remplacerait par des astérisques.
        if ($jeton !== '' && !preg_match('/^\*+$/', $jeton)) {
            Configuration::updateValue(self::CLE_JETON, $jeton);
        }

        if ($changementInstance) {
            // Le cache d'abord : ses réponses portent l'identité de l'ancienne
            // instance, et un jeton révoqué continuerait de servir des données.
            ClientEko::viderCache();

            // ⚠️ LE JETON EST EFFACÉ, et c'est le point important.
            //
            // Sans cela, le module envoie le jeton de l'ancienne instance à la
            // NOUVELLE adresse dès le premier appel. Quelqu'un qui n'a que le
            // droit de configurer les modules pointe le module sur un serveur
            // qu'il contrôle, clique « Tester la liaison », et repart avec un
            // secret qu'il n'a jamais eu le droit de lire — le formulaire le
            // masque pourtant scrupuleusement.
            //
            // Le jeton saisi DANS LE MÊME enregistrement est conservé : changer
            // d'instance et fournir son jeton est un geste légitime.
            if ($jeton === '' || preg_match('/^\*+$/', $jeton)) {
                Configuration::deleteByName(self::CLE_JETON);

                return $this->displayWarning($this->trans(
                    'Adresse de l\'API modifiée : le jeton a été effacé et doit être ressaisi. Un jeton délivré par l\'ancienne instance n\'a pas à être envoyé à la nouvelle.',
                    [],
                    'Modules.Ekosyncimprimerie.Admin'
                ));
            }
        }

        return $this->displayConfirmation($this->trans(
            'Réglages enregistrés.',
            [],
            'Modules.Ekosyncimprimerie.Admin'
        ));
    }

    private function tester(): string
    {
        $resultat = $this->client()->diagnostiquer();
        $texte = implode('<br>', array_map('htmlspecialchars', $resultat['messages']));

        return $resultat['ok'] ? $this->displayConfirmation($texte) : $this->displayError($texte);
    }

    /**
     * Interroge E-KO sur une adresse e-mail et rend ce qu'il en sait.
     *
     * Outil de contrôle, et futur outil de support : quand un client dira « je
     * ne vois pas ma facture », c'est ici qu'on saura s'il est rattaché, à quel
     * tiers, et ce que l'ERP porte à son nom.
     */
    private function sonder(): string
    {
        $email = trim((string) Tools::getValue('EKOSYNC_EMAIL_SONDE'));

        if ($email === '') {
            return $this->displayError($this->trans(
                'Renseignez une adresse e-mail à rechercher.',
                [],
                'Modules.Ekosyncimprimerie.Admin'
            ));
        }

        $depot = new DepotEko($this->client());
        $r = $depot->tierParEmail($email);

        if (!$r['trouve']) {
            $motif = $r['erreur'] !== ''
                ? $r['erreur']
                : sprintf('Aucun tiers E-KO pour « %s ».', $email);

            return $r['ambigu']
                ? $this->displayWarning(htmlspecialchars($motif))
                : $this->displayError(htmlspecialchars($motif));
        }

        $tier = $r['tier'];
        $id = (int) ($tier['id'] ?? 0);

        $lignes = [sprintf(
            'Tiers <strong>%s</strong> (id %d) trouvé.',
            htmlspecialchars((string) ($tier['name'] ?? '—')),
            $id
        )];

        $role = $this->groupes()->roleDe($tier);
        $lignes[] = sprintf(
            'Tarifs applicables : <strong>%s</strong>%s.',
            htmlspecialchars($role),
            $role === Groupes::REVENDEUR ? ' (le prix public reste affiché comme prix conseillé)' : ''
        );

        foreach ($depot->documentsDuTier($id) as $type => $bloc) {
            $lignes[] = $bloc['ok']
                ? sprintf('%s : <strong>%d</strong>', htmlspecialchars($type), $bloc['nombre'])
                : sprintf('%s : erreur — %s', htmlspecialchars($type), htmlspecialchars($bloc['erreur']));
        }

        // Le CODE, pas l'identifiant numérique : la route de l'API lie par
        // `code`. Passer l'`id` que la ressource expose pourtant rend un 404.
        $lignes[] = sprintf(
            '<form method="post" style="margin-top:8px">'
            . '<input type="hidden" name="EKOSYNC_TIER_CODE" value="%s">'
            . '<button type="submit" name="importerEkosync" class="btn btn-default">'
            . '<i class="icon-user"></i> %s</button></form>',
            htmlspecialchars((string) ($tier['code'] ?? '')),
            $this->trans('Créer le compte boutique', [], 'Modules.Ekosyncimprimerie.Admin')
        );

        return $this->displayConfirmation(implode('<br>', $lignes));
    }

    /**
     * Crée le compte boutique d'un tiers E-KO, à la demande.
     *
     * Sens ERP → PrestaShop. Aucun mot de passe n'est choisi ni transmis : le
     * client passe par « mot de passe oublié ». Voir ImportTiers.
     */
    private function importerTiers(): string
    {
        $code = trim((string) Tools::getValue('EKOSYNC_TIER_CODE'));

        if ($code === '') {
            return $this->displayError($this->trans(
                'Aucun tiers désigné.',
                [],
                'Modules.Ekosyncimprimerie.Admin'
            ));
        }

        if ($this->groupes()->identifiant(Groupes::B2B) === 0) {
            // Créer les comptes avant les groupes les rangerait tous dans le
            // groupe par défaut, et il faudrait les reprendre un par un.
            return $this->displayError($this->trans(
                'Posez d\'abord les groupes de clients : sans eux, le compte serait créé sans tarif applicable.',
                [],
                'Modules.Ekosyncimprimerie.Admin'
            ));
        }

        $client = $this->client();
        $import = new ImportTiers(new DepotEko($client), $client, $this->groupes());
        $r = $import->importer($code);

        return $r['ok']
            ? $this->displayConfirmation(htmlspecialchars($r['message']))
            : $this->displayError(htmlspecialchars($r['message']));
    }

    /** Durée de cache effective, défaut compris. */
    public function dureeCache(): int
    {
        $v = Configuration::get(self::CLE_CACHE_S);

        return ($v === false || $v === '') ? self::CACHE_DEFAUT : max(0, (int) $v);
    }

    public function client(): ClientEko
    {
        return new ClientEko(
            (string) Configuration::get(self::CLE_BASE),
            (string) Configuration::get(self::CLE_JETON),
            $this->dureeCache()
        );
    }

    private function formulaire(): string
    {
        $champs = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Liaison avec E-KO', [], 'Modules.Ekosyncimprimerie.Admin'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->trans('Adresse de l\'API', [], 'Modules.Ekosyncimprimerie.Admin'),
                        'name' => self::CLE_BASE,
                        'desc' => $this->trans(
                            'Racine du service, sans /api/v1. HTTPS obligatoire.',
                            [],
                            'Modules.Ekosyncimprimerie.Admin'
                        ),
                        'required' => true,
                    ],
                    [
                        'type' => 'password',
                        'label' => $this->trans('Jeton', [], 'Modules.Ekosyncimprimerie.Admin'),
                        'name' => self::CLE_JETON,
                        'desc' => $this->trans(
                            'Jeton dédié à ce module, aux portées minimales. Laisser vide pour conserver le jeton actuel.',
                            [],
                            'Modules.Ekosyncimprimerie.Admin'
                        ),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Durée du cache (secondes)', [], 'Modules.Ekosyncimprimerie.Admin'),
                        'name' => self::CLE_CACHE_S,
                        'desc' => $this->trans(
                            'Le cache ne conserve que des réponses d\'E-KO, jamais un calcul local. 0 désactive le cache.',
                            [],
                            'Modules.Ekosyncimprimerie.Admin'
                        ),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Rechercher un client', [], 'Modules.Ekosyncimprimerie.Admin'),
                        'name' => 'EKOSYNC_EMAIL_SONDE',
                        'desc' => $this->trans(
                            'Adresse e-mail d\'un client : affiche le tiers E-KO correspondant et le nombre de documents à son nom. Aucune écriture.',
                            [],
                            'Modules.Ekosyncimprimerie.Admin'
                        ),
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Enregistrer', [], 'Modules.Ekosyncimprimerie.Admin'),
                    'name' => 'submitEkosync',
                ],
                'buttons' => [
                    [
                        'type' => 'submit',
                        'title' => $this->trans('Tester la liaison', [], 'Modules.Ekosyncimprimerie.Admin'),
                        'icon' => 'process-icon-refresh',
                        'name' => 'testerEkosync',
                        'class' => 'btn btn-default pull-right',
                    ],
                    [
                        'type' => 'submit',
                        'title' => $this->trans('Rechercher', [], 'Modules.Ekosyncimprimerie.Admin'),
                        'icon' => 'process-icon-search',
                        'name' => 'sonderEkosync',
                        'class' => 'btn btn-default pull-right',
                    ],
                ],
            ],
        ];

        $aide = new HelperForm();
        $aide->module = $this;
        $aide->name_controller = $this->name;
        $aide->token = Tools::getAdminTokenLite('AdminModules');
        $aide->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $aide->submit_action = 'submitEkosync';

        // Le jeton n'est JAMAIS renvoyé au navigateur : on affiche un masque de
        // longueur fixe. Sans ça, il apparaît en clair dans le HTML de la page
        // de configuration, lisible par toute personne ayant accès au BO.
        $aide->fields_value = [
            self::CLE_BASE => Configuration::get(self::CLE_BASE),
            self::CLE_JETON => Configuration::get(self::CLE_JETON) ? '••••••••' : '',
            self::CLE_CACHE_S => $this->dureeCache(),
            'EKOSYNC_EMAIL_SONDE' => Tools::getValue('EKOSYNC_EMAIL_SONDE', ''),
        ];

        return $aide->generateForm([$champs]);
    }
}
