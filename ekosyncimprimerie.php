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
require_once __DIR__ . '/src/Configurateur/ServicesProduit.php';
require_once __DIR__ . '/src/Configurateur/PrixConfigure.php';
require_once __DIR__ . '/src/Configurateur/LiaisonProduit.php';
require_once __DIR__ . '/src/Configurateur/DateLivraison.php';
require_once __DIR__ . '/src/Configurateur/Personnalisation.php';
require_once __DIR__ . '/src/Configurateur/IconeSvg.php';
require_once __DIR__ . '/src/Configurateur/SpecTechnique.php';
require_once __DIR__ . '/src/Configurateur/Gabarit.php';
require_once __DIR__ . '/src/Configurateur/DepotGabarit.php';
require_once __DIR__ . '/src/Configurateur/CorrespondanceEtats.php';
require_once __DIR__ . '/src/Configurateur/FichierClient.php';
// ⚠️ CE MODULE N'A PAS D'AUTOCHARGEUR : chaque classe se déclare ici, à la
// main. Une classe neuve dont on oublie la ligne n'existe pas — et l'erreur
// arrive au moment de l'instancier, dans un contrôleur AJAX, donc sous la
// forme d'un « Une erreur est survenue » que rien ne rattache à un oubli de
// déclaration. Vécu le 2026-08-26 : sept appels de suite, sept fatales.
require_once __DIR__ . '/src/Configurateur/DebitDevis.php';
// ⚠️ Ce module n'a PAS d'autochargeur : chaque classe se déclare ici, et une
// classe absente de cette liste est introuvable — le contrôleur qui l'appelle
// meurt sur un « Class not found », donc en HTTP 500 au corps VIDE.
//
// Cette ligne a déjà été perdue une fois, écrasée par le dépôt d'une version
// plus ancienne de ce fichier pendant qu'une autre session livrait la classe.
// Le chiffrage et le catalogue sont tombés ensemble, sans que rien ne relie
// la panne au dépôt.
require_once __DIR__ . '/src/Configurateur/ReponseJson.php';
require_once __DIR__ . '/src/Client/PousseeCommande.php';

use Eko\SyncImprimerie\Client\ClientEko;
use Eko\SyncImprimerie\Client\DepotEko;
use Eko\SyncImprimerie\Client\Groupes;
use Eko\SyncImprimerie\Client\ImportTiers;
use Eko\SyncImprimerie\Client\PousseeCommande;
use Eko\SyncImprimerie\Configurateur\LiaisonProduit;
use Eko\SyncImprimerie\Configurateur\Personnalisation;
use Eko\SyncImprimerie\Configurateur\ServicesProduit;
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

    /** La classe du contrôleur d'administration, et donc de son onglet. */
    public const ONGLET = 'AdminEkoImprimerie';

    /**
     * Le transport, en jours ouvrés, entre la sortie d'atelier et la porte
     * du client.
     *
     * Le délai que porte la grille tarifaire est un délai de PRODUCTION :
     * il dit quand le travail est fini, pas quand il arrive. Un chiffre en
     * dur ici est provisoire et assumé — il vaut mieux qu'une date de
     * livraison qui promet la sortie d'atelier.
     *
     * ponytail: constante unique ; à remplacer par le délai du transporteur
     * choisi dès que le tunnel expose un transporteur par ligne.
     */
    public const JOURS_TRANSPORT = 1;

    /** Nombre de critères de configuration montrés avant le « Voir plus ». */
    public const CONFIG_LIGNES_VISIBLES = 4;

    public function __construct()
    {
        $this->name = 'ekosyncimprimerie';
        $this->tab = 'front_office_features';
        $this->version = '0.50.0';
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
            // L'editeur en liste du back-office : fiche produit et ecran de
            // reglages s'en servent tous les deux.
            'actionAdminControllerSetMedia',
            // PrestaShop 9 : le seul hook par lequel un module atteint encore
            // le formulaire produit. Voir panneauProduit().
            'displayAdminProductsExtra',
            // La configuration de l'imprimé, sur CHAQUE ligne du panier.
            //
            // ⚠️ `…ProductInfo`, pas `…ProductActions`. Le second est appelé
            // dans la cellule du bouton de suppression — 93 px de large, où le
            // bloc s'écrasait. Le premier vit dans la cellule du produit, juste
            // sous le nom : c'est là que la configuration se lit.
            'displayCartExtraProductInfo',
            // Hook maison, appelé par le gabarit du thème enfant dans sa
            // propre cellule. Le panier natif n'offre aucun point d'accroche
            // dans une colonne « Livraison » qu'il ne connaît pas.
            'displayEkoCartLivraison',
            // Idem pour la colonne « Prix », qui affiche HT et TTC côte à
            // côte — ce que le panier natif ne fait jamais, une boutique
            // grand public n'ayant qu'un seul prix à montrer.
            'displayEkoCartPrix',
            // La cellule des liens : données techniques et gabarit.
            'displayEkoCartLiens',
            // Le poids total, sous le récapitulatif.
            'displayEkoCartPoids',
            // La zone de dépôt de fichier, sur chaque ligne configurée.
            'displayEkoCartFichiers',
            // Le bon de commande, dans le détail d'une commande du compte
            // client. Hook NATIF, déjà rendu par le gabarit `order-detail.tpl`
            // du thème Akira (ligne 152) : aucune surcharge de thème n'est
            // nécessaire, et il n'y en a d'ailleurs aucune pour `customer/`.
            'displayOrderDetail',
            // La tuile « Mes fichiers » du compte client. ⚠️ Ce hook est rendu
            // par `customer/page.tpl`, jamais par `my-account.tpl` dont le
            // bloc de liens est vide dans ce thème.
            'displayCustomerAccount',
            // La commande de la boutique devient un devis dans l'ERP. Sans ce
            // hook, les fichiers déposés par le client restaient en transit
            // pour toujours : rien n'ouvrait le dossier auquel les rattacher.
            'actionValidateOrder',
            // Le garde de péremption des prix, au seuil du tunnel. ⚠️ C'est le
            // SEUL hook du cœur qui s'exécute sur le contrôleur de commande et
            // permette encore de rediriger : `actionValidateStepComplete`
            // n'est dispatché qu'au module TRANSPORTEUR
            // (classes/checkout/CheckoutDeliveryStep.php:185-191), il ne nous
            // atteindrait jamais.
            'actionFrontControllerInitAfter',
        ];

        if (!parent::install()) {
            return false;
        }

        foreach ($hooks as $hook) {
            if (!$this->registerHook($hook)) {
                return false;
            }
        }

        return $this->poserOnglet();
    }

    /**
     * L'entrée « EKO Imprimerie » sous Catalogue.
     *
     * Rangée là, et non dans la configuration du module : on ne va dans la
     * configuration d'un module qu'une fois, pour brancher l'API. Ces
     * réglages-ci — fiche technique, prestations, réassurances, heures limites
     * — se retouchent en travaillant le catalogue, donc à côté des produits.
     *
     * Publique et idempotente : le module est déjà posé sur les boutiques en
     * service, où `install()` ne repassera pas. Elle peut donc être appelée
     * après coup sans rien casser.
     */
    public function poserOnglet(): bool
    {
        if ((int) Tab::getIdFromClassName(self::ONGLET) > 0) {
            return true;
        }

        $parent = (int) Tab::getIdFromClassName('AdminCatalog');

        if ($parent <= 0) {
            return false;
        }

        $onglet = new Tab();
        $onglet->class_name = self::ONGLET;
        $onglet->module = $this->name;
        $onglet->id_parent = $parent;
        $onglet->active = true;

        $noms = [];

        foreach (Language::getLanguages(false) as $langue) {
            $noms[(int) $langue['id_lang']] = 'EKO Imprimerie';
        }

        $onglet->name = $noms;

        if ($onglet->add()) {
            return true;
        }

        // ⚠️ REPLI EN SQL, et il sert vraiment. `Tab::add()` passe par la couche
        // de droits, qui réclame le conteneur Symfony — absent hors du
        // back-office. Sur l'hébergement mutualisé de ce projet l'appel échoue
        // donc en ligne de commande, exactement comme `Module::install()`.
        // Mesuré : sans ce repli, un module posé par script s'installe sans son
        // écran de réglages, et rien ne le signale.
        return $this->poserOngletEnSql($parent);
    }

    /**
     * L'onglet écrit directement, avec ses droits.
     *
     * ⚠️ Les colonnes sont nommées une par une : `tab` a perdu
     * `hide_host_mode` en PrestaShop 9, et une insertion qui la cite encore
     * échoue — en silence, puisque `Db::execute()` rend simplement `false`.
     */
    private function poserOngletEnSql(int $parent): bool
    {
        $db = Db::getInstance();
        $prefixe = _DB_PREFIX_;
        $position = 1 + (int) $db->getValue(
            'SELECT MAX(position) FROM ' . $prefixe . 'tab WHERE id_parent = ' . $parent
        );

        $pose = $db->execute(
            'INSERT INTO ' . $prefixe . 'tab (id_parent, position, module, class_name, active, enabled)'
            . ' VALUES (' . $parent . ', ' . $position . ', "' . pSQL($this->name) . '",'
            . ' "' . pSQL(self::ONGLET) . '", 1, 1)'
        );

        if (!$pose) {
            return false;
        }

        $id = (int) $db->Insert_ID();

        foreach (Language::getLanguages(false) as $langue) {
            $db->execute(
                'INSERT INTO ' . $prefixe . 'tab_lang (id_tab, id_lang, name)'
                . ' VALUES (' . $id . ', ' . (int) $langue['id_lang'] . ', "EKO Imprimerie")'
            );
        }

        // Sans droits, l'entrée existe mais reste invisible : le back-office
        // n'affiche que les onglets que le profil a le droit de lire.
        foreach (['CREATE', 'READ', 'UPDATE', 'DELETE'] as $droit) {
            $slug = 'ROLE_MOD_TAB_' . strtoupper(self::ONGLET) . '_' . $droit;

            $db->execute('INSERT IGNORE INTO ' . $prefixe . 'authorization_role (slug) VALUES ("' . pSQL($slug) . '")');

            $role = (int) $db->getValue(
                'SELECT id_authorization_role FROM ' . $prefixe . 'authorization_role'
                . ' WHERE slug = "' . pSQL($slug) . '"'
            );

            if ($role > 0) {
                $db->execute(
                    'INSERT IGNORE INTO ' . $prefixe . 'access (id_profile, id_authorization_role)'
                    . ' VALUES (1, ' . $role . ')'
                );
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

        // L'onglet part avec le module : laissé en place, il mènerait à un
        // contrôleur qui n'existe plus.
        $idOnglet = (int) Tab::getIdFromClassName(self::ONGLET);

        if ($idOnglet > 0) {
            (new Tab($idOnglet))->delete();
        }

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
    /**
     * Le panneau du module sur la fiche produit — PrestaShop 8.
     *
     * ⚠️ CE HOOK N'EXISTE PLUS SUR PRESTASHOP 9, et c'est mesuré :
     *
     *   - aucun gabarit de l'installation ne le nomme ;
     *   - aucun alias ne le redirige (`hook_alias` : 0 entrée pour AdminProducts) ;
     *   - le cœur de la 9 ne connaît que `displayAdminProductsExtra`
     *     (`src/PrestaShopBundle/Form/Admin/Sell/Product/ExtraModulesType.php`) ;
     *   - sur cette boutique, ce hook n'a qu'UN auditeur — nous — quand quatre
     *     autres modules écoutent le nouveau.
     *
     * Conséquence tant que ce fut le seul point d'entrée : le panneau entier —
     * liaison E-KO, fiche technique, gabarits, services, ventes phares — était
     * INVISIBLE au back-office. Le marchand ne pouvait rien régler ; les 84
     * liaisons existantes ont toutes été posées par script.
     *
     * On garde ce hook : le module est diffusable, et il sert sur une 8.
     */
    public function hookDisplayAdminProductsMainStepLeftColumnMiddle(array $params): string
    {
        return $this->panneauProduit((int) ($params['id_product'] ?? 0));
    }

    /**
     * Le même panneau — PrestaShop 9.
     *
     * Le cœur dispatche ce hook avec `id_product` et rend la sortie dans
     * l'onglet « Modules » du formulaire produit. C'est bien DANS le `<form>` :
     * les champs postent, et `hookActionProductSave()` les reçoit.
     */
    public function hookDisplayAdminProductsExtra(array $params): string
    {
        return $this->panneauProduit((int) ($params['id_product'] ?? 0));
    }

    private function panneauProduit(int $idProduct): string
    {
        if ($idProduct <= 0) {
            return '';
        }

        // Les fichiers de l'éditeur voyagent AVEC le bloc. Le pipeline de
        // `setMedia` ne traverse pas de façon fiable l'écran produit de
        // PrestaShop 9, qui est un écran Symfony : posées ici, les balises
        // arrivent forcément, puisqu'elles font partie du HTML rendu.
        return '<div class="eko-bo">'
            . '<link rel="stylesheet" href="' . $this->_path . 'views/css/bo-liste.css">'
            . '<link rel="stylesheet" href="' . $this->_path . 'views/css/bo-gabarits.css">'
            . $this->boLiaison($idProduct)
            . $this->boTechnique($idProduct)
            . $this->boGabarits($idProduct)
            . $this->boServices($idProduct)
            . $this->boVentesPhares($idProduct)
            . '<script src="' . $this->_path . 'views/js/bo-liste.js" defer></script>'
            . '<script src="' . $this->_path . 'views/js/bo-gabarits.js" defer></script>'
            . '</div>';
    }

    /**
     * Le choix du produit E-KO : atelier ou sous-traitance.
     *
     * Une seule liste pour les deux catalogues, chaque entrée portant sa
     * source. Deux listes obligeraient à choisir d'abord laquelle regarder,
     * alors que le marchand cherche un PRODUIT et se moque de savoir qui le
     * fabrique.
     *
     * ⚠️ Un catalogue injoignable ne fait pas disparaître l'autre, ni les
     * champs qui suivent : ils ne dépendent pas de l'ERP.
     */
    private function boLiaison(int $idProduct): string
    {
        $liaison = (new LiaisonProduit())->pour($idProduct);
        $sourceActuelle = $liaison['source'] ?? '';
        $refActuelle = $liaison['reference'] ?? '';

        $options = '<option value="">'
            . $this->trans('— aucun, prix géré par PrestaShop —', [], 'Modules.Ekosyncimprimerie.Admin')
            . '</option>';

        $avertissements = '';

        // ⚠️ Ce témoin porte tout le correctif : voir la remise en liste juste
        // avant le `return`.
        $trouve = false;

        // L'atelier : le prix y est CALCULÉ.
        $r = $this->client()->appeler('GET', '/api/v1/printing/products?per_page=100');

        if ($r['ok']) {
            $groupe = '';

            foreach ((array) ($r['donnees']['data'] ?? []) as $p) {
                if (!is_array($p)) {
                    continue;
                }

                $valeur = LiaisonProduit::SOURCE_ATELIER . ':' . (int) ($p['id'] ?? 0);

                $estCelleEnregistree = $sourceActuelle === LiaisonProduit::SOURCE_ATELIER
                    && $refActuelle === (string) ($p['id'] ?? '');
                $trouve = $trouve || $estCelleEnregistree;

                $groupe .= sprintf(
                    '<option value="%s"%s>%s</option>',
                    htmlspecialchars($valeur),
                    $estCelleEnregistree ? ' selected' : '',
                    htmlspecialchars((string) ($p['name'] ?? ''))
                );
            }

            if ($groupe !== '') {
                $options .= '<optgroup label="'
                    . $this->trans('Atelier — prix calculé', [], 'Modules.Ekosyncimprimerie.Admin')
                    . '">' . $groupe . '</optgroup>';
            }
        } else {
            $avertissements .= '<p class="help-block text-warning">'
                . $this->trans('Catalogue d\'atelier indisponible : ', [], 'Modules.Ekosyncimprimerie.Admin')
                . htmlspecialchars($r['erreur']) . '</p>';
        }

        // La sous-traitance : le prix y est LU dans une grille.
        $r2 = $this->client()->appeler('GET', '/api/v1/printoclock/products?per_page=100');

        if ($r2['ok']) {
            $groupe = '';

            foreach ((array) ($r2['donnees']['data'] ?? []) as $p) {
                if (!is_array($p)) {
                    continue;
                }

                $code = (string) ($p['code'] ?? '');
                $valeur = LiaisonProduit::SOURCE_PRINTOCLOCK . ':' . $code;

                $estCelleEnregistree = $sourceActuelle === LiaisonProduit::SOURCE_PRINTOCLOCK
                    && $refActuelle === $code;
                $trouve = $trouve || $estCelleEnregistree;

                $groupe .= sprintf(
                    '<option value="%s"%s>%s%s</option>',
                    htmlspecialchars($valeur),
                    $estCelleEnregistree ? ' selected' : '',
                    htmlspecialchars((string) ($p['name'] ?? '')),
                    // Un tarif périmé se dit ICI, au moment de lier : le
                    // marchand choisit alors en connaissance de cause.
                    ($p['price_stale'] ?? false) ? ' ⚠' : ''
                );
            }

            if ($groupe !== '') {
                $options .= '<optgroup label="'
                    . $this->trans('Sous-traitance — prix en grille', [], 'Modules.Ekosyncimprimerie.Admin')
                    . '">' . $groupe . '</optgroup>';
            }
        } else {
            $avertissements .= '<p class="help-block text-warning">'
                . $this->trans('Catalogue de sous-traitance indisponible : ', [], 'Modules.Ekosyncimprimerie.Admin')
                . htmlspecialchars($r2['erreur']) . '</p>';
        }

        // Le second sous-traitant : le prix y est LU dans une grille aussi,
        // mais ses options forment un FORMULAIRE et non un arbre.
        //
        // ⚠️ UNE FICHE = UN PRODUIT **ET** UNE MATIÈRE. « Panneau Akylux » et
        // « Panneau Dibond » sont deux pages de cette boutique pour une seule
        // déclinaison chez le fournisseur. La référence porte donc quatre
        // segments, et le marchand doit pouvoir choisir la matière — sans quoi
        // il ne peut rien lier du tout.
        //
        // Deux listes plutôt qu'une : deux cent cinquante et un couples
        // multipliés par leurs matières feraient plus de mille lignes dans un
        // seul menu déroulant, illisible. La seconde liste se remplit depuis
        // les matières que la première porte en attribut.
        $r3 = $this->client()->appeler('GET', '/api/v1/realisaprint/products');
        $matieresJson = '{}';

        if ($r3['ok']) {
            $groupe = '';
            $matieres = [];
            $codeActuel = '';

            if ($sourceActuelle === LiaisonProduit::SOURCE_REALISAPRINT) {
                $bouts = explode(':', $refActuelle);
                $codeActuel = ($bouts[0] ?? '') . ':' . ($bouts[1] ?? '');
            }

            foreach ((array) ($r3['donnees']['data'] ?? []) as $p) {
                if (!is_array($p)) {
                    continue;
                }

                $code = (string) ($p['code'] ?? '');

                if ($code === '') {
                    continue;
                }

                $matieres[$code] = array_values(array_filter(
                    (array) ($p['supports'] ?? []),
                    static fn ($m): bool => is_array($m) && ($m['id'] ?? '') !== ''
                ));

                $estCelleEnregistree = $codeActuel === $code;
                $trouve = $trouve || $estCelleEnregistree;

                $nom = trim(
                    (string) ($p['name'] ?? '')
                    . ' — ' . (string) ($p['variant_name'] ?? '')
                );

                $groupe .= sprintf(
                    '<option value="%s"%s>%s%s</option>',
                    htmlspecialchars(LiaisonProduit::SOURCE_REALISAPRINT . ':' . $code),
                    $estCelleEnregistree ? ' selected' : '',
                    htmlspecialchars($nom !== '—' ? $nom : $code),
                    // Un couple sans tarif construit ne se vend pas : le
                    // marchand doit le savoir AVANT de lier, pas après avoir
                    // publié la fiche.
                    ($p['priced'] ?? false) ? '' : ' ⚠'
                );
            }

            if ($groupe !== '') {
                $options .= '<optgroup label="'
                    . $this->trans('Sous-traitance — prix en grille (formulaire)', [], 'Modules.Ekosyncimprimerie.Admin')
                    . '">' . $groupe . '</optgroup>';
            }

            $encode = json_encode($matieres, JSON_UNESCAPED_UNICODE);
            $matieresJson = is_string($encode) ? $encode : '{}';
        } else {
            $avertissements .= '<p class="help-block text-warning">'
                . $this->trans('Second catalogue de sous-traitance indisponible : ', [], 'Modules.Ekosyncimprimerie.Admin')
                . htmlspecialchars($r3['erreur']) . '</p>';
        }

        // ⚠️ LA LIAISON NE DOIT PAS DÉPENDRE DE L'ERP POUR SURVIVRE.
        //
        // Cette liste est bâtie ENTIÈREMENT depuis les deux appels ci-dessus.
        // Quand ils échouent — ERP muet, jeton révoqué, 500, lenteur — il ne
        // reste que l'option « aucun », que le navigateur sélectionne d'office.
        // Le marchand corrige alors une virgule dans la description, enregistre,
        // et la liaison est SUPPRIMÉE en base : plus de configurateur, retour au
        // prix de fiche PrestaShop. À l'écran : « Mise à jour réussie ».
        //
        // Même effet sans aucune panne : produit retiré du catalogue E-KO, ou
        // simplement au-delà de la centième ligne — la liste ne pagine pas.
        //
        // Or la liaison est connue SANS l'ERP : elle est en base, on vient de la
        // lire ligne 425. Seul son LIBELLÉ manque. On la réémet donc telle
        // quelle, sélectionnée, avec sa référence brute en guise de nom.
        if (!$trouve && $sourceActuelle !== '' && $refActuelle !== '') {
            $options .= sprintf(
                '<option value="%s" selected>%s</option>',
                htmlspecialchars($sourceActuelle . ':' . $refActuelle),
                htmlspecialchars(
                    $this->trans('Liaison enregistrée', [], 'Modules.Ekosyncimprimerie.Admin')
                    . ' — ' . $sourceActuelle . ' : ' . $refActuelle
                )
            );

            $avertissements .= '<p class="help-block text-warning">'
                . $this->trans(
                    'La liaison enregistrée est conservée et reste sélectionnée. Ne la remplacez pas tant que le catalogue n’est pas revenu.',
                    [],
                    'Modules.Ekosyncimprimerie.Admin'
                )
                . '</p>';
        }

        // La matière déjà enregistrée, pour que la seconde liste s'ouvre sur
        // elle plutôt que sur sa première entrée.
        $matiereActuelle = '';

        if ($sourceActuelle === LiaisonProduit::SOURCE_REALISAPRINT) {
            $bouts = explode(':', $refActuelle);
            $matiereActuelle = (string) ($bouts[2] ?? '');
        }

        return '<div class="form-group"><label class="form-control-label">'
            . $this->trans('Produit E-KO', [], 'Modules.Ekosyncimprimerie.Admin')
            . '</label>'
            . '<select name="ekosync_produit" id="ekosync_produit" class="form-control">' . $options . '</select>'
            . $avertissements
            . '<p class="help-block">'
            . $this->trans(
                'Lier cette fiche fait venir son prix de l\'ERP. Sans liaison, PrestaShop garde la main.',
                [],
                'Modules.Ekosyncimprimerie.Admin'
            )
            . '</p></div>'
            // ⚠️ Ce bloc reste CACHÉ tant que le produit choisi n'a pas de
            // matières. Affiché vide, il donnerait à croire qu'un choix
            // manque là où il n'y en a aucun à faire.
            . '<div class="form-group" id="ekosync_matiere_bloc" style="display:none">'
            . '<label class="form-control-label">'
            . $this->trans('Matière de cette fiche', [], 'Modules.Ekosyncimprimerie.Admin')
            . '</label>'
            . '<select name="ekosync_produit_support" id="ekosync_produit_support" class="form-control"></select>'
            . '<p class="help-block">'
            . $this->trans(
                'Un même produit du sous-traitant alimente plusieurs fiches : Akylux, Dibond et PVC expansé sont trois pages pour une seule référence chez lui. La matière choisie ici est celle que cette fiche vend, et le configurateur la verrouillera.',
                [],
                'Modules.Ekosyncimprimerie.Admin'
            )
            . '</p></div>'
            . '<script>(function(){'
            . 'var M=' . $matieresJson . ','
            . 'A=' . json_encode($matiereActuelle) . ','
            . 'p=document.getElementById("ekosync_produit"),'
            . 's=document.getElementById("ekosync_produit_support"),'
            . 'b=document.getElementById("ekosync_matiere_bloc");'
            . 'if(!p||!s||!b){return;}'
            . 'function maj(){'
            . 'var v=p.value||"",c=v.indexOf("realisaprint:")===0?v.slice(13):"",l=M[c]||[];'
            . 's.innerHTML="";'
            . 'if(!l.length){b.style.display="none";return;}'
            . 'l.forEach(function(m){var o=document.createElement("option");'
            . 'o.value=m.id;o.textContent=m.label;if(m.id===A){o.selected=true;}s.appendChild(o);});'
            . 'b.style.display="";'
            . '}'
            . 'p.addEventListener("change",function(){A="";maj();});maj();'
            . '})();</script>';
    }

    /**
     * La fiche technique : ce que l'imprimeur attend d'un fichier.
     *
     * Laissé vide, un champ retombe sur le réglage de boutique, puis sur
     * l'usage du métier. Le filigrane montre ce qui s'appliquera — un champ
     * vide sans filigrane laisserait croire que rien ne s'affiche.
     */
    /**
     * La surcharge PROPRE à ce produit, sans la cascade.
     *
     * ⚠️ C'est toute la différence avec `ServicesProduit::reglage()`, et elle
     * compte : afficher la valeur de boutique DANS le champ d'une fiche ferait
     * qu'un simple enregistrement la recopie en surcharge produit. Le lien
     * serait rompu sans que personne ne l'ait voulu, et la prochaine
     * modification du réglage de boutique n'atteindrait plus cette fiche.
     *
     * Le champ ne montre donc que ce que la fiche porte en propre. Ce qui
     * s'applique réellement va en filigrane.
     */
    private function surchargeProduit(string $cle, int $idProduct): string
    {
        $v = Configuration::get('EKOSYNC_' . strtoupper($cle) . '_' . $idProduct);

        return ($v === false) ? '' : (string) $v;
    }

    /**
     * Ce qui s'appliquera si la fiche ne dit rien : le réglage de boutique,
     * ou l'exemple à défaut.
     *
     * Montré en filigrane, jamais dans le champ. Le marchand voit ainsi ce que
     * sa fiche affiche aujourd'hui sans qu'un enregistrement le fige.
     */
    private function filigrane(string $cle, string $exemple): string
    {
        $boutique = ServicesProduit::reglage($cle, 0);

        return $boutique !== '' ? $boutique : $exemple;
    }

    /**
     * Les attributs que l'éditeur en liste lit sur une zone de texte.
     *
     * Tous les libellés passent par `trans()` : l'éditeur en est dépourvu, et
     * un texte écrit dans le JavaScript resterait français sur un back-office
     * anglais. `$icones` est vide quand la liste ne porte pas d'icônes — c'est
     * ce qui distingue les réassurances des prestations, sans deux éditeurs.
     */
    private function attributsListe(string $icones, string $troisieme = ''): string
    {
        return sprintf(
            'data-icones="%s" data-depot="%s" data-libelle-ajouter="%s"'
            . ' data-libelle-retirer="%s" data-libelle-depot="%s" data-libelle-icone="%s"'
            . ' data-echec-depot="%s" data-libelle-troisieme="%s"',
            htmlspecialchars($icones),
            htmlspecialchars($icones === '' ? '' : $this->urlDepotIcone()),
            htmlspecialchars($this->trans('Ajouter une ligne', [], 'Modules.Ekosyncimprimerie.Admin')),
            htmlspecialchars($this->trans('Retirer cette ligne', [], 'Modules.Ekosyncimprimerie.Admin')),
            htmlspecialchars($this->trans('Déposer un SVG', [], 'Modules.Ekosyncimprimerie.Admin')),
            htmlspecialchars($this->trans('Icône…', [], 'Modules.Ekosyncimprimerie.Admin')),
            htmlspecialchars($this->trans('Dépôt refusé.', [], 'Modules.Ekosyncimprimerie.Admin')),
            htmlspecialchars($troisieme)
        );
    }

    /** L'adresse du dépôt d'icône, jeton d'administration compris. */
    private function urlDepotIcone(): string
    {
        return $this->context->link->getAdminLink(self::ONGLET, true, [], [
            'ajax' => 1,
            'action' => 'televerserIcone',
        ]);
    }

    /**
     * L'état des gabarits, format par format.
     *
     * ─── POURQUOI PAR FORMAT ───────────────────────────────────────────────
     *
     * « Boîte cadeau pour bouteille » porte deux formats, 8 × 8 × 33 cm et
     * 9,5 × 9,5 × 32 cm : deux découpes différentes. Un seul gabarit rangé sur
     * la fiche servirait le mauvais plan à un client sur deux, et rien ne le
     * signalerait — un PDF s'ouvre toujours.
     *
     * ─── POURQUOI LE DÉPÔT PASSE PAR AJAX ──────────────────────────────────
     *
     * La fiche produit de PrestaShop 9 est un formulaire Symfony soumis en
     * AJAX. Un `<input type="file">` injecté par un hook n'y est pas transmis
     * de façon fiable : le champ s'affiche, le marchand choisit son fichier,
     * il croit l'avoir envoyé, et rien n'arrive. On poste donc directement au
     * contrôleur d'administration du module — le même motif que l'éditeur
     * d'icônes, éprouvé sur cet écran.
     */
    private function boGabarits(int $idProduct): string
    {
        $formats = $this->formatsDuProduit($idProduct);

        if ($formats === []) {
            return '';
        }

        $lignes = '';

        foreach ($formats as $libelle) {
            $depose = \Eko\SyncImprimerie\Configurateur\DepotGabarit::lire($idProduct, $libelle);
            $spec = \Eko\SyncImprimerie\Configurateur\SpecTechnique::depuisFormat($libelle);

            if ($depose !== null) {
                $etat = 'depose';
                $texte = $this->trans('Déposé', [], 'Modules.Ekosyncimprimerie.Admin')
                    . ' — ' . number_format($depose['taille'] / 1024, 0, ',', ' ') . ' ko';
            } elseif ($spec !== null) {
                $etat = 'calcule';
                $texte = $this->trans('Calculé', [], 'Modules.Ekosyncimprimerie.Admin') . ' — '
                    . rtrim(rtrim(number_format($spec->largeurAFournirMm() / 10, 1, ',', ''), '0'), ',')
                    . ' × '
                    . rtrim(rtrim(number_format($spec->hauteurAFournirMm() / 10, 1, ',', ''), '0'), ',')
                    . ' cm';
            } else {
                $etat = 'manquant';
                $texte = $this->trans('À fournir', [], 'Modules.Ekosyncimprimerie.Admin');
            }

            $lignes .= sprintf(
                '<li class="eko-gab__ligne eko-gab__ligne--%s" data-format="%s">'
                . '<span class="eko-gab__format">%s</span>'
                . '<span class="eko-gab__etat">%s</span>'
                . '<span class="eko-gab__actions">'
                . '<label class="eko-gab__deposer">%s<input type="file" accept=".pdf,.eps,.ai" hidden></label>'
                . '%s</span></li>',
                $etat,
                htmlspecialchars($libelle, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($libelle, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($texte, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars(
                    $depose !== null
                        ? $this->trans('Remplacer', [], 'Modules.Ekosyncimprimerie.Admin')
                        : $this->trans('Déposer', [], 'Modules.Ekosyncimprimerie.Admin'),
                    ENT_QUOTES,
                    'UTF-8'
                ),
                $depose !== null
                    ? '<button type="button" class="eko-gab__retirer">'
                        . htmlspecialchars($this->trans('Retirer', [], 'Modules.Ekosyncimprimerie.Admin'), ENT_QUOTES, 'UTF-8')
                        . '</button>'
                    : ''
            );
        }

        return sprintf(
            '<fieldset class="eko-bo__bloc eko-gab" data-produit="%d" data-url="%s" data-echec="%s">'
            . '<legend>%s</legend>'
            . '<p class="eko-gab__aide">%s</p>'
            . '<ul class="eko-gab__liste">%s</ul>'
            . '</fieldset>',
            $idProduct,
            htmlspecialchars($this->context->link->getAdminLink(
                self::ONGLET,
                true,
                [],
                ['ajax' => 1, 'action' => 'gabarit']
            ), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($this->trans('Dépôt refusé.', [], 'Modules.Ekosyncimprimerie.Admin'), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($this->trans('Gabarits', [], 'Modules.Ekosyncimprimerie.Admin'), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($this->trans(
                'Un gabarit « calculé » est produit automatiquement depuis le format fini. '
                . 'Les formats en volume, ronds ou à une seule cote demandent une découpe fournisseur : déposez-la ici. '
                . 'Un fichier déposé remplace toujours le calcul.',
                [],
                'Modules.Ekosyncimprimerie.Admin'
            ), ENT_QUOTES, 'UTF-8'),
            $lignes
        );
    }

    /**
     * Les libellés de format d'un produit, dédoublonnés et ordonnés.
     *
     * Plusieurs caractéristiques portent un format selon les produits
     * (« EKO Format », « EKO Format (cm) », « EKO Dimensions », « EKO Taille »…
     * — cinquante-quatre intitulés distincts dans le catalogue). On les prend
     * toutes plutôt que d'en nommer une : un produit dont la caractéristique
     * s'appelle « Format (L x P x h) » a autant besoin de son gabarit.
     *
     * @return string[]
     */
    private function formatsDuProduit(int $idProduct): array
    {
        if ($idProduct <= 0) {
            return [];
        }

        $lignes = \Db::getInstance()->executeS(
            'SELECT DISTINCT fvl.value'
            . ' FROM `' . _DB_PREFIX_ . 'feature_product` fp'
            . ' JOIN `' . _DB_PREFIX_ . 'feature_lang` fl'
            . '   ON fl.id_feature = fp.id_feature AND fl.id_lang = ' . (int) $this->context->language->id
            . ' JOIN `' . _DB_PREFIX_ . 'feature_value_lang` fvl'
            . '   ON fvl.id_feature_value = fp.id_feature_value'
            . '  AND fvl.id_lang = ' . (int) $this->context->language->id
            . ' WHERE fp.id_product = ' . $idProduct
            // ⚠️ SEULES LES CARACTÉRISTIQUES « EKO … » PORTENT UN VRAI FORMAT.
            //
            // Le filtre ne retenait que « ormat / imension / aille », et il
            // attrapait donc aussi « Formats disponibles » et « Dimensions sur
            // mesure » — deux caractéristiques écrites par le script
            // d'injection, dont la valeur est une PHRASE de synthèse :
            // « 1753 formats, de 20 × 30 à 200 × 300 cm ». L'extracteur de
            // cotes y attrapait une des deux bornes, et la fiche offrait un
            // gabarit à une taille arbitraire.
            //
            // Les formats véritables viennent de l'import du catalogue, qui
            // les nomme « EKO Format », « EKO Format (cm) », « EKO Taille »…
            // Un produit vendu au sur-mesure n'en a pas : son gabarit se
            // calcule aux cotes saisies, dans le configurateur.
            . " AND fl.name LIKE 'EKO %'"
            . " AND (fl.name LIKE '%ormat%' OR fl.name LIKE '%imension%' OR fl.name LIKE '%aille%')"
            . ' ORDER BY fvl.value'
        );

        $formats = [];

        foreach ((array) $lignes as $l) {
            $v = trim((string) ($l['value'] ?? ''));

            if ($v !== '') {
                $formats[] = $v;
            }
        }

        return $formats;
    }

    private function boTechnique(int $idProduct): string
    {
        $libelles = [
            'resolution' => $this->trans('Résolution', [], 'Modules.Ekosyncimprimerie.Admin'),
            'couleurs' => $this->trans('Couleurs', [], 'Modules.Ekosyncimprimerie.Admin'),
            'fonds_perdus' => $this->trans('Fonds perdus', [], 'Modules.Ekosyncimprimerie.Admin'),
            'marge' => $this->trans('Marge de sécurité', [], 'Modules.Ekosyncimprimerie.Admin'),
        ];

        $champs = '';

        foreach (ServicesProduit::TECHNIQUE as $cle => $defaut) {
            $valeur = Configuration::get('EKOSYNC_TECH_' . strtoupper($cle) . '_' . $idProduct);
            $champs .= sprintf(
                '<div class="form-group"><label class="form-control-label">%s</label>'
                . '<input type="text" name="ekosync_tech_%s" class="form-control" value="%s" placeholder="%s"></div>',
                htmlspecialchars($libelles[$cle]),
                htmlspecialchars($cle),
                htmlspecialchars(($valeur === false) ? '' : (string) $valeur),
                htmlspecialchars(ServicesProduit::reglage('tech_' . $cle, 0, $defaut))
            );
        }

        return '<fieldset class="eko-bo__bloc"><legend>'
            . $this->trans('Fiche technique', [], 'Modules.Ekosyncimprimerie.Admin')
            . '</legend>' . $champs . '</fieldset>';
    }

    /**
     * Les prestations de l'imprimeur, et leur supplément.
     *
     * Une ligne par option, `Libellé|supplément en euros|description`. La
     * description est facultative — c'est ce qui a permis de l'ajouter sans
     * rouvrir les saisies déjà faites. C'est ce qu'un
     * marchand tape le plus vite et relit d'un œil ; un tableau de saisie
     * coûterait dix fois l'écran pour trois lignes.
     *
     * La PREMIÈRE ligne est l'option par défaut : elle doit être la gratuite,
     * sans quoi le prix d'appel du produit monte sans que personne ne l'ait
     * demandé.
     */
    private function boServices(int $idProduct): string
    {
        $defauts = [
            'bat' => [
                $this->trans('BAT numérique', [], 'Modules.Ekosyncimprimerie.Admin'),
                "Non|0\nOui|15",
            ],
            'creation' => [
                $this->trans('Ma création graphique', [], 'Modules.Ekosyncimprimerie.Admin'),
                "Je dispose de mon fichier|0|Je l'envoie après ma commande, prêt à imprimer"
                    . "\nCréation simple|35|Nos graphistes mettent en page vos textes et vos visuels"
                    . "\nCréation avancée|99|Nos graphistes conçoivent votre visuel de A à Z",
            ],
        ];

        $champs = '';

        foreach ($defauts as $cle => [$libelle, $exemple]) {
            // Le filigrane montre CE QUI S'APPLIQUE : le réglage de boutique
            // s'il existe, l'exemple sinon. Un champ vide devant un exemple
            // inventé laisserait croire que rien ne s'affiche sur la fiche,
            // alors que le réglage de boutique, lui, s'affiche bien.
            $boutique = ServicesProduit::reglage('svc_' . $cle, 0);

            $champs .= sprintf(
                '<div class="form-group"><label class="form-control-label">%s</label>'
                . '<textarea name="ekosync_svc_%s" class="form-control eko-bo-liste" rows="4"'
                . ' ' . $this->attributsListe('', $this->trans('Description (facultative)', [], 'Modules.Ekosyncimprimerie.Admin'))
                . ' placeholder="%s">%s</textarea></div>',
                htmlspecialchars($libelle),
                htmlspecialchars($cle),
                htmlspecialchars($boutique !== '' ? $boutique : $exemple),
                htmlspecialchars($this->surchargeProduit('svc_' . $cle, $idProduct))
            );
        }

        return '<fieldset class="eko-bo__bloc"><legend>'
            . $this->trans('Prestations', [], 'Modules.Ekosyncimprimerie.Admin')
            . '</legend>'
            . '<p class="help-block">'
            . $this->trans(
                'Une ligne par option : « Libellé|supplément en euros|description ». La description est facultative ; elle s\'affiche sous le titre de la tuile. La première ligne est le choix par défaut — elle doit être gratuite.',
                [],
                'Modules.Ekosyncimprimerie.Admin'
            )
            . '<br>'
            . $this->trans(
                'Laisser vide pour reprendre le réglage de la boutique, montré en filigrane. Une création graphique ne demande pas le même travail sur un flyer et sur un dépliant : c\'est ici qu\'on l\'ajuste, fiche par fiche.',
                [],
                'Modules.Ekosyncimprimerie.Admin'
            )
            . '</p>' . $champs . '</fieldset>';
    }

    /**
     * Les ventes phares : des raccourcis vers une configuration courante.
     *
     * ⚠️ CE CHAMP N'A PAS DE VALEUR PAR DÉFAUT, et c'est délibéré. « Top vente
     * A5 » est une affirmation sur ce que les clients achètent : elle appartient
     * au marchand. La pré-remplir avec les premiers formats de l'arbre
     * écrirait au client, sur une étiquette qui a l'air d'un conseil, quelque
     * chose que personne n'a vérifié.
     */
    private function boVentesPhares(int $idProduct): string
    {
        $valeur = Configuration::get('EKOSYNC_VENTES_' . $idProduct);

        return '<fieldset class="eko-bo__bloc"><legend>'
            . $this->trans('Ventes phares', [], 'Modules.Ekosyncimprimerie.Admin')
            . '</legend>'
            . '<p class="help-block">'
            . $this->trans(
                'Une ligne par onglet : « Libellé|format ». Le format s\'écrit avec son nom tel qu\'il apparaît sur le site, ou avec le code du fournisseur. Laisser vide pour n\'afficher aucun onglet.',
                [],
                'Modules.Ekosyncimprimerie.Admin'
            )
            . '</p>'
            . sprintf(
                '<textarea name="ekosync_ventes" class="form-control eko-bo-liste" rows="3"'
                . ' ' . $this->attributsListe('')
                . ' placeholder="%s">%s</textarea>',
                htmlspecialchars("Top vente A5|A5\nTop vente A6|A6"),
                htmlspecialchars(($valeur === false) ? '' : (string) $valeur)
            )
            . sprintf(
                '<div class="form-group"><label class="form-control-label">%s</label>'
                . '<input type="text" name="ekosync_delai_note" class="form-control" value="%s" placeholder="%s">'
                . '<p class="help-block">%s</p></div>',
                htmlspecialchars($this->trans('Heure limite — offre incluse', [], 'Modules.Ekosyncimprimerie.Admin')),
                htmlspecialchars($this->surchargeProduit('delai_note', $idProduct)),
                htmlspecialchars($this->filigrane('delai_note', $this->trans('Si commandé aujourd\'hui avant 18h', [], 'Modules.Ekosyncimprimerie.Admin'))),
                htmlspecialchars($this->trans(
                    'Affichée sous le délai inclus. Laisser vide pour ne rien annoncer : c\'est un engagement pris devant le client.',
                    [],
                    'Modules.Ekosyncimprimerie.Admin'
                ))
            )
            . sprintf(
                '<div class="form-group"><label class="form-control-label">%s</label>'
                . '<input type="text" name="ekosync_delai_note_rapide" class="form-control" value="%s" placeholder="%s">'
                . '<p class="help-block">%s</p></div>',
                htmlspecialchars($this->trans('Heure limite — livraison accélérée', [], 'Modules.Ekosyncimprimerie.Admin')),
                htmlspecialchars($this->surchargeProduit('delai_note_rapide', $idProduct)),
                htmlspecialchars($this->filigrane('delai_note_rapide', $this->trans('Si commandé avant demain 11h', [], 'Modules.Ekosyncimprimerie.Admin'))),
                htmlspecialchars($this->trans(
                    'Affichée sous les délais payants, dont l\'heure limite est souvent différente.',
                    [],
                    'Modules.Ekosyncimprimerie.Admin'
                ))
            )
            . sprintf(
                '<div class="form-group"><label class="form-control-label">%s</label>'
                . '<input type="text" name="ekosync_mention_prix" class="form-control" value="%s" placeholder="%s">'
                . '<p class="help-block">%s</p></div>',
                htmlspecialchars($this->trans('Mention sous le prix', [], 'Modules.Ekosyncimprimerie.Admin')),
                htmlspecialchars($this->surchargeProduit('mention_prix', $idProduct)),
                htmlspecialchars($this->filigrane('mention_prix', $this->trans('Tout inclus — Livraison offerte', [], 'Modules.Ekosyncimprimerie.Admin'))),
                htmlspecialchars($this->trans(
                    'Affichée dans le récapitulatif, sous le montant. Laisser vide si la livraison n\'est pas offerte : la mention serait alors fausse.',
                    [],
                    'Modules.Ekosyncimprimerie.Admin'
                ))
            )
            . sprintf(
                '<div class="form-group"><label class="form-control-label">%s</label>'
                . '<textarea name="ekosync_reassurances" class="form-control eko-bo-liste" rows="4"'
                . ' ' . $this->attributsListe('origine,livraison,fichier,paiement')
                . ' placeholder="%s">%s</textarea>'
                . '<p class="help-block">%s</p></div>',
                htmlspecialchars($this->trans('Réassurances', [], 'Modules.Ekosyncimprimerie.Admin')),
                htmlspecialchars($this->filigrane(
                    'reassurances',
                    "100% Made In Occitanie|/img/cms/occitanie.svg\n"
                    . "Livraison rapide et offerte|livraison\n"
                    . "Vérification gratuite de vos fichiers|fichier\n"
                    . 'Paiement sécurisé|paiement'
                )),
                htmlspecialchars($this->surchargeProduit('reassurances', $idProduct)),
                htmlspecialchars($this->trans(
                    'Une ligne par argument : « Libellé|icône ». L\'icône est origine, livraison, fichier ou paiement — ou le chemin d\'une image déposée sur la boutique, pour un logo qui vous appartient.',
                    [],
                    'Modules.Ekosyncimprimerie.Admin'
                ))
            )
            . '</fieldset>'
            . $this->boGuide($idProduct);
    }

    /**
     * Le guide propre au produit — remplace le « Size Guide » du thème.
     *
     * Deux champs et rien de plus : un intitulé et un contenu. Laisser le
     * contenu vide, c'est ne pas afficher de guide — inutile d'ajouter une case
     * à cocher pour dire la même chose deux fois.
     */
    private function boGuide(int $idProduct): string
    {
        return '<fieldset class="eko-bo__bloc"><legend>'
            . $this->trans('Guide du produit', [], 'Modules.Ekosyncimprimerie.Admin')
            . '</legend>'
            . '<p class="help-block">'
            . $this->trans(
                'Remplace le « Size Guide » du thème, qui est le même pour toute la boutique. Laisser le contenu vide pour n\'afficher aucun guide sur cette fiche — un guide des tailles n\'a pas de sens sur un flyer, il en a un sur un textile.',
                [],
                'Modules.Ekosyncimprimerie.Admin'
            )
            . '</p>'
            . sprintf(
                '<div class="form-group"><label class="form-control-label">%s</label>'
                . '<input type="text" name="ekosync_guide_titre" class="form-control" value="%s" placeholder="%s"></div>',
                htmlspecialchars($this->trans('Intitulé', [], 'Modules.Ekosyncimprimerie.Admin')),
                htmlspecialchars($this->surchargeProduit('guide_titre', $idProduct)),
                htmlspecialchars($this->filigrane('guide_titre', $this->trans('Guide des tailles', [], 'Modules.Ekosyncimprimerie.Admin')))
            )
            . sprintf(
                '<div class="form-group"><label class="form-control-label">%s</label>'
                . '<textarea name="ekosync_guide_contenu" class="form-control" rows="6" placeholder="%s">%s</textarea></div>',
                htmlspecialchars($this->trans('Contenu', [], 'Modules.Ekosyncimprimerie.Admin')),
                htmlspecialchars($this->trans('Texte ou tableau HTML. Vide = pas de guide sur cette fiche.', [], 'Modules.Ekosyncimprimerie.Admin')),
                htmlspecialchars($this->surchargeProduit('guide_contenu', $idProduct))
            )
            . '</fieldset>';
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

        if ($idProduct <= 0) {
            return;
        }

        // Chaque champ n'est lu QUE s'il est présent dans la requête : une
        // sauvegarde déclenchée par un autre écran — import, script, autre
        // module — ne doit rien effacer de ce à quoi personne n'a touché.
        if (Tools::getIsset('ekosync_produit')) {
            $brut = (string) Tools::getValue('ekosync_produit');
            $parts = explode(':', $brut, 2);
            $source = $parts[0] ?? '';
            $reference = $parts[1] ?? '';

            // ⚠️ LA RÉFÉRENCE DU SECOND SOUS-TRAITANT SE COMPOSE ICI.
            //
            // La liste rend « produit:déclinaison » ; il y manque la matière,
            // choisie dans la seconde liste, et de quoi distinguer deux fiches
            // qui vendraient la MÊME matière du MÊME produit — « Panneau
            // immobilier » et « Panneau permis de construire » sont dans ce
            // cas. Sans ce quatrième segment, la seconde fiche heurterait
            // l'index unique de la table et sa liaison serait refusée.
            //
            // Le quatrième segment est l'identifiant de la fiche : il est
            // unique par construction, il ne change jamais, et il ne peut pas
            // entrer en collision — ce qu'un intitulé ne garantit ni l'un ni
            // l'autre.
            if ($source === LiaisonProduit::SOURCE_REALISAPRINT && $reference !== ''
                && substr_count($reference, ':') === 1) {
                $matiere = trim((string) Tools::getValue('ekosync_produit_support'));

                $reference .= ':' . ($matiere !== '' ? $matiere : '0') . ':' . $idProduct;
            }

            (new LiaisonProduit())->lier($idProduct, $source, $reference);
        }

        foreach (array_keys(ServicesProduit::TECHNIQUE) as $cle) {
            if (Tools::getIsset('ekosync_tech_' . $cle)) {
                ServicesProduit::poser('tech_' . $cle, $idProduct, (string) Tools::getValue('ekosync_tech_' . $cle));
            }
        }

        foreach (ServicesProduit::SERVICES as $cle) {
            if (Tools::getIsset('ekosync_svc_' . $cle)) {
                ServicesProduit::poser('svc_' . $cle, $idProduct, (string) Tools::getValue('ekosync_svc_' . $cle));
            }
        }

        if (Tools::getIsset('ekosync_ventes')) {
            ServicesProduit::poser('ventes', $idProduct, (string) Tools::getValue('ekosync_ventes'));
        }

        foreach (['delai_note', 'delai_note_rapide', 'mention_prix', 'reassurances', 'guide_titre', 'guide_contenu'] as $cle) {
            if (Tools::getIsset('ekosync_' . $cle)) {
                ServicesProduit::poser(
                    $cle,
                    $idProduct,
                    (string) Tools::getValue('ekosync_' . $cle),
                    in_array($cle, ServicesProduit::RICHES, true)
                );
            }
        }
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
    /**
     * La date de livraison estimée, sous le panier.
     *
     * ─── POURQUOI ELLE SE RECALCULE À CHAQUE AFFICHAGE ─────────────────────
     *
     * Ce qui est mémorisé au moment de l'ajout, c'est le DÉLAI en jours
     * ouvrés — jamais la date. Un client qui laisse son panier trois jours
     * verrait sinon une livraison annoncée pour une date déjà passée, et cette
     * date-là finirait sur sa commande. Ici, elle repart de today() à chaque
     * chargement de page : elle est juste par construction.
     *
     * Le délai retenu est le PLUS LONG du panier : une commande part quand son
     * article le plus lent est prêt.
     */
    /**
     * La configuration du client, en VRAIE liste.
     *
     * ─── POURQUOI ON LA REND NOUS-MÊMES ────────────────────────────────────
     *
     * PrestaShop range toute la configuration dans UN champ de personnalisation
     * — c'est juste : c'est un seul choix, indivisible, qui doit rester
     * solidaire jusque sur la facture. Le gabarit du thème l'affiche donc en un
     * seul bloc de texte.
     *
     * On avait d'abord séparé les critères par des retours à la ligne, rendus
     * par `white-space: pre-line`. Ça se lit, mais ce ne sont pas des lignes :
     * c'est un paragraphe avec des ruptures. Un lecteur d'écran l'annonce d'une
     * traite, et rien ne dit que « Format : 10x10 » est un élément parmi
     * d'autres.
     *
     * Ici, chaque critère devient un `<li>`. La donnée stockée ne change pas —
     * un seul champ, un seul texte — seule sa présentation gagne sa structure.
     * Le bloc natif est masqué par la feuille de style, sans quoi la
     * configuration s'afficherait deux fois.
     */
    /**
     * La valeur d'UN champ de personnalisation nommé, sur une ligne donnée.
     *
     * ─── ⚠️ POURQUOI CETTE MÉTHODE EXISTE ──────────────────────────────────
     *
     * Les deux lectures du module interrogeaient `customized_data` sur
     * `id_customization` + `type`, SANS jamais filtrer `index`. Tant que la
     * ligne ne portait qu'un seul champ texte, cela marchait par accident.
     *
     * Le jour où une ligne en porte deux — la configuration et le commentaire
     * du client — cette requête en rend DEUX, et `getValue()` (qui passe par
     * `getRow()`, lequel colle un `LIMIT 1` sans `ORDER BY`) en garde un au
     * hasard. Le hasard décide alors si la fiche technique, le gabarit et le
     * poids sont calculés sur la configuration… ou sur une phrase libre.
     *
     * Aucune erreur, aucun journal : juste des spécifications fausses.
     *
     * `index` porte l'identifiant du champ. On l'y ramène en joignant sur le
     * NOM, seule désignation qui ne dépende d'aucun ordre de création.
     */
    private static function valeurDuChamp(int $idCustomization, string $nomChamp): string
    {
        if ($idCustomization <= 0) {
            return '';
        }

        $db = \Db::getInstance();
        $prefixe = _DB_PREFIX_;
        $type = (int) \Product::CUSTOMIZE_TEXTFIELD;

        $valeur = (string) $db->getValue(
            'SELECT cd.`value`'
            . " FROM `{$prefixe}customized_data` cd"
            . " JOIN `{$prefixe}customization_field` cf"
            . ' ON cf.`id_customization_field` = cd.`index`'
            . " JOIN `{$prefixe}customization_field_lang` cfl"
            . ' ON cfl.`id_customization_field` = cd.`index`'
            . ' WHERE cd.`id_customization` = ' . (int) $idCustomization
            . ' AND cd.`type` = ' . $type
            // ⚠️ `is_deleted`, comme à l'ÉCRITURE. `champNomme()` le filtre
            // depuis toujours ; ne pas le filtrer ici faisait lire un champ que
            // l'écriture n'élirait plus — les deux ne désignaient pas le même.
            . ' AND cf.`is_deleted` = 0'
            . " AND cfl.`name` = '" . pSQL($nomChamp) . "'"
            // ⚠️ `index`, et non un identifiant de ligne : MESURÉ, la table
            // n'a PAS de clé technique. Sa clé primaire est le triplet
            // (id_customization, type, index). Trier sur une colonne imaginée
            // aurait été une erreur SQL, donc une valeur vide, donc une ligne
            // qui se serait lue « sans configuration ».
            //
            // ⚠️ Et pas de `LIMIT` : `getValue()` en ajoute un. En écrire un
            // ici donnerait « LIMIT 1 LIMIT 1 », qui ne rend RIEN.
            . ' ORDER BY cd.`index` ASC'
        );

        if ($valeur !== '') {
            return $valeur;
        }

        // ─── LES LIGNES DONT LE CHAMP N'EXISTE PLUS ────────────────────────
        //
        // ⚠️ MESURÉ EN PRODUCTION, et c'est le témoin qui l'a montré : des
        // lignes de `customized_data` portent un `index` (17) qui ne
        // correspond à AUCUN `customization_field`. Le champ a été supprimé
        // pour de bon — pas marqué `is_deleted`, effacé — et sa donnée est
        // restée orpheline dans des paniers en place.
        //
        // L'ancienne lecture les voyait, puisqu'elle ne joignait rien. Les
        // perdre d'un coup aurait vidé la configuration de ces paniers, donc
        // leur fiche technique, leur gabarit et leur poids.
        //
        // ⚠️ LE REPLI NE VISE QUE LES LIGNES VRAIMENT ORPHELINES, et seulement
        // pour la configuration.
        //
        // Compter simplement « un seul champ texte sur la ligne » ne suffisait
        // pas : le jour où le client commente une de ces vieilles lignes, elle
        // en porte deux, le compte passe à deux, et sa configuration
        // redevient illisible — le commentaire aurait effacé la fiche
        // technique, le gabarit et le poids.
        //
        // On ne retient donc que les valeurs dont l'`index` ne correspond à
        // AUCUN champ existant. Le commentaire, lui, est toujours créé par son
        // nom : son champ existe, il n'est jamais orphelin, et il ne peut donc
        // pas être ramassé ici.
        if ($nomChamp !== Personnalisation::CHAMP_CONFIGURATION) {
            return '';
        }

        return (string) $db->getValue(
            "SELECT cd.`value` FROM `{$prefixe}customized_data` cd"
            . " LEFT JOIN `{$prefixe}customization_field` cf"
            . ' ON cf.`id_customization_field` = cd.`index`'
            . ' WHERE cd.`id_customization` = ' . (int) $idCustomization
            . ' AND cd.`type` = ' . $type
            . ' AND cf.`id_customization_field` IS NULL'
            . ' ORDER BY cd.`index` ASC'
        );
    }

    private function configurationEnListe(int $idCustomization): string
    {
        if ($idCustomization <= 0) {
            return '';
        }

        $texte = self::valeurDuChamp($idCustomization, Personnalisation::CHAMP_CONFIGURATION);

        if (trim($texte) === '') {
            return '';
        }

        // ⚠️ Deux formats coexistent dans les paniers, et coexisteront tant que
        // des lignes anciennes y traînent :
        //   — un critère par ligne, écrit par le chemin catalogue ;
        //   — tous les critères sur une ligne, séparés par « · », écrit par le
        //     chemin grand format jusqu'au 2026-08-13.
        //
        // Le second est corrigé à la source, mais les lignes DÉJÀ au panier
        // gardent leur format : elles s'affichaient en pavé continu là où les
        // autres rendaient une liste. On coupe donc sur les deux séparateurs
        // au point unique où toute configuration passe pour être affichée —
        // corriger seulement l'écriture aurait laissé les paniers en cours
        // fautifs, sans que rien ne le signale.
        $lignes = array_filter(array_map(
            'trim',
            preg_split('/\r\n|\r|\n|\x{00B7}/u', $texte) ?: []
        ));

        if ($lignes === []) {
            return '';
        }

        $sortie = '<ul class="eko-config">';

        foreach ($lignes as $ligne) {
            // « Format : 10x10 » se coupe en deux : le critère en gras, la
            // valeur à côté. Une ligne sans séparateur reste telle quelle —
            // mieux vaut l'afficher brute que de la découper au hasard.
            $bouts = explode(' : ', $ligne, 2);

            $sortie .= '<li>';

            if (count($bouts) === 2) {
                $sortie .= '<span class="eko-config__cle">' . htmlspecialchars($bouts[0], ENT_QUOTES, 'UTF-8') . '</span>'
                    . '<span class="eko-config__val">' . htmlspecialchars($bouts[1], ENT_QUOTES, 'UTF-8') . '</span>';
            } else {
                $sortie .= htmlspecialchars($ligne, ENT_QUOTES, 'UTF-8');
            }

            $sortie .= '</li>';
        }

        $sortie .= '</ul>';

        // Au-delà de quatre critères, le repli. Une configuration d'imprimé
        // en compte couramment dix ; toutes affichées, la colonne « Produit »
        // ferait deux fois la hauteur des quatre autres et le panier
        // deviendrait illisible dès le deuxième article.
        if (count($lignes) > self::CONFIG_LIGNES_VISIBLES) {
            $sortie = '<div class="eko-config__boite eko-config__boite--repliee">' . $sortie
                . '<button type="button" class="eko-config__plus"'
                . ' data-plus="' . htmlspecialchars($this->trans('Voir plus', [], 'Modules.Ekosyncimprimerie.Shop'), ENT_QUOTES, 'UTF-8') . '"'
                . ' data-moins="' . htmlspecialchars($this->trans('Voir moins', [], 'Modules.Ekosyncimprimerie.Shop'), ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars($this->trans('Voir plus', [], 'Modules.Ekosyncimprimerie.Shop'), ENT_QUOTES, 'UTF-8')
                . '</button></div>';
        }

        return $sortie;
    }

    /**
     * Cellule « Produit » : la configuration, et rien d'autre.
     *
     * Les six premiers critères sont visibles, le reste se déplie derrière
     * « Voir plus ». Une configuration d'imprimé compte couramment dix
     * lignes ; les afficher toutes ferait une colonne deux fois plus haute
     * que les quatre autres, et le panier deviendrait illisible dès deux
     * articles.
     */
    public function hookDisplayCartExtraProductInfo(array $params): string
    {
        $idCustomization = (int) ($params['product']['id_customization'] ?? 0);

        return $this->configurationEnListe($idCustomization)
            . $this->commentaireDeLigne($idCustomization);
    }

    /**
     * Le champ de commentaire d'une ligne de panier.
     *
     * ─── ⚠️ POURQUOI IL FAUT LE RENDRE ICI, EXPLICITEMENT ──────────────────
     *
     * Le commentaire est un vrai champ de personnalisation : le back-office et
     * la facture l'affichent donc sans une ligne de code. Mais le gabarit de
     * ligne du thème enfant a SUPPRIMÉ le bloc natif
     * `cart_detailed_product_line_customization`. Sans ce rendu, le champ
     * existerait partout SAUF là où le client peut le saisir et le relire —
     * il ne pourrait ni le corriger, ni même vérifier qu'il a bien été pris.
     *
     * ─── ET POURQUOI UN `textarea` PRÉ-REMPLI, PAS UN CHAMP VIDE ───────────
     *
     * C'est le CHEMIN DE RETOUR. Un champ qu'on n'affiche pas rempli se
     * réenregistre vide au premier geste qui touche la ligne : l'écran reste
     * juste, la donnée part. Il porte donc toujours ce qui est en base.
     */
    private function commentaireDeLigne(int $idCustomization): string
    {
        if ($idCustomization <= 0) {
            return '';
        }

        $texte = self::valeurDuChamp($idCustomization, Personnalisation::CHAMP_COMMENTAIRE);

        $etiquette = $this->trans('Une consigne pour cette ligne ?', [], 'Modules.Ekosyncimprimerie.Shop');
        $exemple = $this->trans('Ex. : livrer avant le 20, ou couper en deux paquets égaux.', [], 'Modules.Ekosyncimprimerie.Shop');
        $enregistre = $this->trans('Commentaire enregistré.', [], 'Modules.Ekosyncimprimerie.Shop');

        return '<div class="eko-commentaire" data-id-customization="' . $idCustomization . '">'
            . '<label class="eko-commentaire__etiquette" for="eko-commentaire-' . $idCustomization . '">'
            . htmlspecialchars($etiquette, ENT_QUOTES, 'UTF-8')
            . '</label>'
            . '<textarea class="eko-commentaire__champ"'
            . ' id="eko-commentaire-' . $idCustomization . '"'
            . ' rows="2"'
            . ' maxlength="' . Personnalisation::LONGUEUR_VALEUR . '"'
            . ' placeholder="' . htmlspecialchars($exemple, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($texte, ENT_QUOTES, 'UTF-8')
            . '</textarea>'
            . '<span class="eko-commentaire__etat" role="status" data-message="'
            . htmlspecialchars($enregistre, ENT_QUOTES, 'UTF-8') . '"></span>'
            . '</div>';
    }

    /**
     * Les critères de configuration d'une ligne, en tableau.
     *
     * Le texte stocké est le MÊME que celui qu'affiche la liste du panier —
     * une seule vérité en base, deux lectures. Le découper ici plutôt que de
     * ranger un second champ JSON évite qu'un jour les deux divergent : ce que
     * le client lit et ce que le gabarit calcule sortiraient de sources
     * différentes.
     *
     * @return array<string,string>
     */
    private function criteresDeLigne(int $idCustomization): array
    {
        if ($idCustomization <= 0) {
            return [];
        }

        $texte = self::valeurDuChamp($idCustomization, Personnalisation::CHAMP_CONFIGURATION);

        $criteres = [];

        foreach (preg_split('/\r\n|\r|\n|\x{00B7}/u', $texte) ?: [] as $ligne) {
            $bouts = explode(' : ', trim($ligne), 2);

            if (count($bouts) === 2 && trim($bouts[0]) !== '') {
                $criteres[trim($bouts[0])] = trim($bouts[1]);
            }
        }

        return $criteres;
    }

    /**
     * Le libellé de format d'une configuration, s'il y en a un.
     *
     * @param array<string,string> $criteres
     */
    private function formatDesCriteres(array $criteres): ?string
    {
        foreach ($criteres as $critere => $valeur) {
            $c = mb_strtolower((string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $critere));

            if (preg_match('/format|dimension|taille/u', $c) && trim($valeur) !== '') {
                return trim($valeur);
            }
        }

        return null;
    }

    /**
     * La cellule des liens du panier : données techniques et gabarit.
     *
     * ─── POURQUOI C'EST RENDU CÔTÉ SERVEUR ─────────────────────────────────
     *
     * Au panier, la configuration est FIGÉE : elle a été écrite au moment de
     * l'ajout. Rien ne bouge, donc rien ne justifie d'aller chercher les cotes
     * en JavaScript. Sur la fiche produit, où le client change de format à
     * chaque clic, ce sera l'inverse — mais c'est le même calcul PHP qui
     * répondra, pas une seconde implémentation.
     */
    public function hookDisplayEkoCartLiens(array $params): string
    {
        $produit = $params['product'] ?? null;

        if (!is_array($produit) && !$produit instanceof \ArrayAccess) {
            return '';
        }

        return $this->blocSpecifications(
            (int) $this->valeur($produit, 'id_product'),
            $this->criteresDeLigne((int) $this->valeur($produit, 'id_customization')),
            max(1, (int) $this->valeur($produit, 'cart_quantity', 1)),
            'eko-fitech-' . (int) $this->valeur($produit, 'id_customization')
        );
    }

    /**
     * Le bloc « spécifications techniques + gabarit », d'où qu'on l'appelle.
     *
     * ─── UN SEUL RENDU POUR DEUX ÉCRANS ────────────────────────────────────
     *
     * Au panier, la configuration est figée et le bloc se rend côté serveur.
     * Sur la fiche produit, elle change à chaque clic et c'est le configurateur
     * qui redemande le bloc — mais il redemande CE bloc, rendu par ce même
     * code. Écrire la fenêtre une seconde fois en JavaScript, c'est se
     * condamner à ce que les deux divergent : le client verrait des cotes au
     * panier différentes de celles qu'il a lues sur la fiche.
     *
     * @param array<string,string> $criteres
     */
    public function blocSpecifications(
        int $idProduct,
        array $criteres,
        int $quantiteCart = 1,
        string $idFenetre = 'eko-fitech',
        bool $avecGabarit = true
    ): string {
        if ($criteres === []) {
            return '';
        }

        $quantite = max(1, $quantiteCart);
        $format = $this->formatDesCriteres($criteres);

        if ($format === null) {
            return '';
        }

        $spec = \Eko\SyncImprimerie\Configurateur\SpecTechnique::depuisFormat(
            $format,
            \Eko\SyncImprimerie\Configurateur\SpecTechnique::pagesDepuisCriteres($criteres)
        );

        $gabarit = $this->lienGabarit($idProduct, $format, $spec);

        $liens = '';

        if ($spec !== null) {
            $liens .= '<li><button type="button" class="eko-liens__lien eko-fitech__ouvrir"'
                . ' data-cible="' . htmlspecialchars($idFenetre, ENT_QUOTES, 'UTF-8') . '">'
                . '<i class="axicon-file-pdf"></i>'
                . htmlspecialchars($this->trans('Spécifications techniques', [], 'Modules.Ekosyncimprimerie.Shop'), ENT_QUOTES, 'UTF-8')
                . '</button></li>';
        }

        // Sur la fiche produit, la colonne « Gabarits » liste déjà un fichier
        // par format : répéter ici le gabarit du format courant ferait deux
        // liens voisins vers le même PDF.
        if ($avecGabarit && $gabarit !== '') {
            $liens .= '<li><a class="eko-liens__lien" href="' . htmlspecialchars($gabarit, ENT_QUOTES, 'UTF-8') . '">'
                . '<i class="axicon-download"></i>'
                . htmlspecialchars($this->trans('Télécharger le gabarit', [], 'Modules.Ekosyncimprimerie.Shop'), ENT_QUOTES, 'UTF-8')
                . '</a></li>';
        }

        if ($liens === '') {
            return '';
        }

        $fenetre = $spec === null
            ? ''
            : $this->fenetreTechnique(
                $idFenetre,
                $spec,
                \Eko\SyncImprimerie\Configurateur\SpecTechnique::grammage($this->papierDesCriteres($criteres)),
                // Le tirage configuré, multiplié par le nombre de fois que ce
                // même travail figure au panier.
                $this->tirageDesCriteres($criteres) * $quantite
            );

        return '<ul class="eko-liens">' . $liens . '</ul>' . $fenetre;
    }

    /**
     * Le TIRAGE d'une configuration : le nombre d'exemplaires commandés.
     *
     * ⚠️ Ce n'est pas `cart_quantity`. Chez un imprimeur, une ligne de panier
     * est UN TRAVAIL — « 500 flyers » — et sa quantité de panier vaut 1. Le
     * nombre d'exemplaires vit dans la configuration, au même titre que le
     * format et le papier, parce que c'est lui qui détermine le prix au tarif
     * fournisseur.
     *
     * Calculer le poids sur `cart_quantity` donnait 0,00 kg pour cinq cents
     * flyers : un chiffre faux, affiché sans le moindre signal.
     *
     * @param array<string,string> $criteres
     */
    private function tirageDesCriteres(array $criteres): int
    {
        foreach ($criteres as $critere => $valeur) {
            $c = mb_strtolower((string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $critere));

            // « Quantité », et rien d'autre : « Nombre de feuillets » ou
            // « Pages » sont des quantités elles aussi, mais ne comptent pas
            // des exemplaires.
            if (preg_match('/^quantite$/u', trim($c)) && preg_match('/(\d+)/', (string) $valeur, $m)) {
                return max(1, (int) $m[1]);
            }
        }

        return 1;
    }

    /**
     * Le libellé de papier d'une configuration, pour en tirer le grammage.
     *
     * @param array<string,string> $criteres
     */
    private function papierDesCriteres(array $criteres): string
    {
        foreach ($criteres as $critere => $valeur) {
            $c = mb_strtolower((string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $critere));

            if (preg_match('/papier|matiere|support|carton|bache/u', $c)) {
                return (string) $valeur;
            }
        }

        return '';
    }

    /**
     * Le lien de téléchargement du gabarit, ou une chaîne vide.
     *
     * Un lien n'est proposé que si un gabarit EXISTE réellement — calculé ou
     * déposé. Un lien qui rendrait 404 fait douter le client de tout le reste
     * de la page.
     */
    private function lienGabarit(
        int $idProduct,
        string $format,
        ?\Eko\SyncImprimerie\Configurateur\SpecTechnique $spec
    ): string {
        if ($idProduct <= 0) {
            return '';
        }

        $depose = \Eko\SyncImprimerie\Configurateur\DepotGabarit::lire($idProduct, $format);

        if ($depose === null && $spec === null) {
            return '';
        }

        $parametres = ['id_product' => $idProduct, 'format' => $format];

        if ($spec !== null && $spec->pages > 1) {
            $parametres['pages'] = $spec->pages;
        }

        return (string) $this->context->link->getModuleLink(
            'ekosyncimprimerie',
            'gabarit',
            $parametres
        );
    }

    /**
     * La fenêtre « données techniques ».
     *
     * Elle dit ce que le client doit FOURNIR, pas ce qu'il achète : hauteur et
     * largeur fonds perdus compris, marges, forme, échelle, résolution. Les
     * cotes du produit fini n'y figurent pas — elles sont sur la fiche, et les
     * répéter ici ferait deux nombres proches dont on ne saurait plus lequel
     * porter sur le document.
     */
    private function fenetreTechnique(
        string $id,
        \Eko\SyncImprimerie\Configurateur\SpecTechnique $spec,
        ?int $grammage,
        int $quantite
    ): string {
        $t = $spec->enTableau();
        $nombre = static fn (float $v): string => rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');

        $lignes = [
            $this->trans('Hauteur à fournir (cm)', [], 'Modules.Ekosyncimprimerie.Shop') => $nombre((float) $t['hauteur_fournir_cm']),
            $this->trans('Largeur à fournir (cm)', [], 'Modules.Ekosyncimprimerie.Shop') => $nombre((float) $t['largeur_fournir_cm']),
            $this->trans('Bords perdus (cm)', [], 'Modules.Ekosyncimprimerie.Shop') => $nombre((float) $t['fond_perdu_cm']),
            $this->trans('Bords de sécurité (cm)', [], 'Modules.Ekosyncimprimerie.Shop') => $nombre((float) $t['securite_cm']),
            $this->trans('Forme', [], 'Modules.Ekosyncimprimerie.Shop') => $this->trans('Rectangle', [], 'Modules.Ekosyncimprimerie.Shop'),
            $this->trans('Échelle autorisée', [], 'Modules.Ekosyncimprimerie.Shop') => '100 %',
            $this->trans('Résolution demandée (dpi)', [], 'Modules.Ekosyncimprimerie.Shop') => (string) $t['resolution_dpi'],
            $this->trans('Types de fichiers autorisés', [], 'Modules.Ekosyncimprimerie.Shop') => implode(', ', (array) $t['types']),
        ];

        if ((int) $t['pages'] > 1) {
            $lignes[$this->trans('Pages attendues', [], 'Modules.Ekosyncimprimerie.Shop')] = (string) $t['pages'];
        }

        if (!empty($t['plie'])) {
            // ⚠️ Formulation pesée deux fois. « Le pliage reste à votre
            // charge » faisait croire au client qu'il recevrait une feuille à
            // plat, à plier lui-même. On énonce donc les DEUX faits dans
            // l'ordre où ils le rassurent : qui plie, puis ce qui lui reste à
            // faire. Une phrase qui ne dit que la seconde moitié se lit comme
            // la première.
            $lignes[$this->trans('Pliage', [], 'Modules.Ekosyncimprimerie.Shop')] =
                $this->trans(
                    'Nous assurons le pliage. Le gabarit est au format ouvert : calez votre contenu sur les plis.',
                    [],
                    'Modules.Ekosyncimprimerie.Shop'
                )
                . ((int) $t['volets'] > 0
                    ? ' ' . $this->trans('(%n% volets)', ['%n%' => (int) $t['volets']], 'Modules.Ekosyncimprimerie.Shop')
                    : '');
        }

        if ($grammage !== null) {
            $lignes[$this->trans('Masse/m² (g)', [], 'Modules.Ekosyncimprimerie.Shop')] = (string) $grammage;

            $poids = $spec->poidsKg($quantite, $grammage);

            if ($poids !== null) {
                $lignes[$this->trans('Poids (kg)', [], 'Modules.Ekosyncimprimerie.Shop')] = $nombre($poids);
            }
        }

        $corps = '';

        foreach ($lignes as $libelle => $valeur) {
            // ⚠️ Une valeur longue ne se lit pas alignée à droite : elle
            // revient en drapeau, bord gauche déchiqueté, et la deuxième ligne
            // semble flotter. On marque donc les valeurs de plus de quarante
            // caractères pour que la feuille les cale à gauche — le libellé,
            // lui, garde sa colonne.
            //
            // Le seuil est en CARACTÈRES et non en pixels parce qu'il se
            // décide ici, là où la valeur est connue ; une règle CSS ne sait
            // pas ce qu'elle mesure.
            $long = mb_strlen((string) $valeur) > 40;

            $corps .= '<div' . ($long ? ' class="eko-fitech__long"' : '') . '>'
                . '<dt>' . htmlspecialchars((string) $libelle, ENT_QUOTES, 'UTF-8') . '</dt>'
                . '<dd>' . htmlspecialchars((string) $valeur, ENT_QUOTES, 'UTF-8') . '</dd></div>';
        }

        return '<div class="eko-fitech" id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '" hidden>'
            . '<div class="eko-fitech__voile"></div>'
            . '<div class="eko-fitech__boite" role="dialog" aria-modal="true">'
            . '<div class="eko-fitech__entete">'
            . '<p class="eko-fitech__titre"><i class="axicon-cog"></i>'
            . htmlspecialchars($this->trans('Données techniques', [], 'Modules.Ekosyncimprimerie.Shop'), ENT_QUOTES, 'UTF-8')
            . '</p>'
            . '<button type="button" class="eko-fitech__fermer" aria-label="'
            . htmlspecialchars($this->trans('Fermer', [], 'Modules.Ekosyncimprimerie.Shop'), ENT_QUOTES, 'UTF-8')
            . '">&times;</button></div>'
            . '<dl class="eko-fitech__liste">' . $corps . '</dl>'
            . '</div></div>';
    }

    /**
     * Le poids d'une ligne de panier, en kilogrammes, ou `null`.
     *
     * Extrait de la fenêtre technique le 2026-08-13, quand il a fallu le
     * totaliser : deux calculs du même poids auraient fini par diverger, et
     * c'est le genre d'écart qu'on ne découvre qu'à la pesée, au moment
     * d'affranchir.
     *
     * Rend `null` — et non zéro — quand le support ne porte pas de masse
     * (« Adhésif Vinyl Blanc », « Aucun ») ou que le format ne se lit pas. Un
     * zéro se totaliserait en silence et ferait annoncer un colis plus léger
     * qu'il ne l'est.
     */
    private function poidsDeLigne(int $idCustomization, int $quantiteCart): ?float
    {
        $criteres = $this->criteresDeLigne($idCustomization);

        if ($criteres === []) {
            return null;
        }

        $format = $this->formatDesCriteres($criteres);

        if ($format === null) {
            return null;
        }

        $spec = \Eko\SyncImprimerie\Configurateur\SpecTechnique::depuisFormat(
            $format,
            \Eko\SyncImprimerie\Configurateur\SpecTechnique::pagesDepuisCriteres($criteres)
        );

        if ($spec === null) {
            return null;
        }

        return $spec->poidsKg(
            $this->tirageDesCriteres($criteres) * max(1, $quantiteCart),
            \Eko\SyncImprimerie\Configurateur\SpecTechnique::grammage($this->papierDesCriteres($criteres))
        );
    }

    /**
     * Le poids total de la commande, sous le récapitulatif du panier.
     *
     * ─── POURQUOI IL EST ANNONCÉ, ET AVEC QUELLE PRUDENCE ──────────────────
     *
     * Un imprimé se transporte : cinq cents dépliants A3 pèsent sept kilos, et
     * le client a le droit de le savoir avant de choisir son mode de livraison
     * — ou de venir les chercher.
     *
     * Le chiffre est celui du PAPIER seul : ni emballage, ni palette, ni
     * calage. Il est donc annoncé comme approché. Le donner au gramme près
     * serait une précision que nous n'avons pas.
     *
     * ⚠️ Si UNE SEULE ligne ne se pèse pas, le total est marqué comme partiel.
     * Additionner ce qu'on sait peser et taire le reste donnerait un total
     * faussement rassurant — et c'est sur ce total que se décide un
     * transporteur.
     */
    public function hookDisplayEkoCartPoids(array $params): string
    {
        $cart = $this->context->cart;

        if (!\Validate::isLoadedObject($cart)) {
            return '';
        }

        $total = 0.0;
        $peses = 0;
        $lignes = 0;

        foreach ($cart->getProducts() as $ligne) {
            $lignes++;

            $poids = $this->poidsDeLigne(
                (int) ($ligne['id_customization'] ?? 0),
                (int) ($ligne['cart_quantity'] ?? 1)
            );

            if ($poids === null) {
                continue;
            }

            $total += $poids;
            $peses++;
        }

        if ($peses === 0 || $total <= 0.0) {
            return '';
        }

        // Sous le kilo, on parle en grammes : « 0,06 kg » ne se lit pas, alors
        // que « 60 g » se sent dans la main.
        $affiche = $total < 1.0
            ? number_format($total * 1000, 0, ',', ' ') . ' g'
            : number_format($total, 2, ',', ' ') . ' kg';

        $partiel = $peses < $lignes
            ? ' <span class="eko-poids__reserve">'
                . htmlspecialchars(
                    // « Grammage » et « papier » ne valent que pour l'imprimé.
                    // La boutique vend aussi de la bâche, du vinyle, du
                    // textile et des objets : le libellé doit tenir pour tous.
                    $this->trans('hors articles dont le poids n’est pas connu', [], 'Modules.Ekosyncimprimerie.Shop'),
                    ENT_QUOTES,
                    'UTF-8'
                )
                . '</span>'
            : '';

        // La réserve « papier seul, hors emballage » a disparu le 2026-08-13 :
        // l'emballage est désormais COMPTÉ (3 %, cf. SpecTechnique::EMBALLAGE).
        // Un avertissement qui ne correspond plus au calcul est pire que pas
        // d'avertissement — il fait douter d'un chiffre devenu juste.
        return '<p class="eko-poids">'
            . '<span class="eko-poids__label">'
            . htmlspecialchars($this->trans('Poids total de ma commande', [], 'Modules.Ekosyncimprimerie.Shop'), ENT_QUOTES, 'UTF-8')
            . '</span> <strong class="eko-poids__valeur">' . htmlspecialchars($affiche, ENT_QUOTES, 'UTF-8') . '</strong>'
            . $partiel
            . '</p>';
    }

    /**
     * Le lien vers le bon de commande, dans le détail d'une commande.
     *
     * ─── POURQUOI CE DOCUMENT N'EXISTAIT PAS ───────────────────────────────
     *
     * PrestaShop ne sert au client que trois PDF : la facture, l'avoir et le
     * bon de retour. Le bon de livraison lui-même n'est PAS téléchargeable
     * côté client — il n'existe qu'en back-office. Et aucun bon de commande
     * n'est prévu nulle part.
     *
     * Le client n'avait donc AUCUN document entre la validation de sa commande
     * et l'émission de la facture, c'est-à-dire pendant toute la fabrication —
     * la période où il a justement besoin de relire ce qu'il a demandé.
     *
     * ─── ET POURQUOI LE LIEN NE S'AFFICHE PAS TOUJOURS ─────────────────────
     *
     * Une commande sans ligne ne donne rien à imprimer. Le hook rend alors une
     * chaîne vide plutôt qu'un lien qui produirait un document creux.
     */
    public function hookDisplayOrderDetail(array $params): string
    {
        $commande = $params['order'] ?? null;

        if (!($commande instanceof \Order) || !\Validate::isLoadedObject($commande)) {
            return '';
        }

        $client = $this->context->customer ?? null;

        // Le hook s'exécute dans la page du client, mais un hook ne garantit
        // rien sur QUI regarde : le contrôleur du PDF refait le contrôle, et
        // celui-ci évite seulement d'afficher un lien qui mènerait à une 404.
        if (!\Validate::isLoadedObject($client) || (int) $commande->id_customer !== (int) $client->id) {
            return '';
        }

        if (count($commande->getProducts()) === 0) {
            return '';
        }

        $this->context->smarty->assign([
            'eko_url_bon_commande' => $this->context->link->getModuleLink(
                $this->name,
                'pdfcommande',
                ['id_order' => (int) $commande->id],
                true
            ),
        ]);

        return $this->display(__FILE__, 'views/templates/hook/bon-commande.tpl');
    }

    /**
     * La commande vient d'être validée : elle part vers l'ERP.
     *
     * ⚠️ CE HOOK NE DOIT JAMAIS FAIRE ÉCHOUER LA COMMANDE. Il s'exécute dans
     * le tunnel, juste après l'enregistrement. Une exception qui remonte
     * ferait perdre au client une commande qu'il vient de payer, pour une
     * panne qui ne le concerne en rien — l'ERP injoignable, un jeton périmé.
     *
     * L'échec est donc consigné, jamais levé. La table `ekosync_commande`
     * garde le motif et le nombre de tentatives : c'est de là qu'on rejouera.
     *
     * @param array<string,mixed> $params
     */
    public function hookActionValidateOrder(array $params): void
    {
        try {
            $commande = $params['order'] ?? null;

            if (!($commande instanceof \Order) || !\Validate::isLoadedObject($commande)) {
                return;
            }

            $client = $this->client(0);

            if (!$client->estConfigure()) {
                return;
            }

            $pousseur = new PousseeCommande(
                $client,
                new ImportTiers(new DepotEko($client), $client, $this->groupes())
            );

            $pousseur->pousser($commande);
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog(
                'ekosyncimprimerie — poussée de commande interrompue : '
                . mb_substr($e->getMessage(), 0, 400),
                3,
                null,
                'Order',
                (int) (($params['order'] ?? null) instanceof \Order ? $params['order']->id : 0)
            );
        }
    }

    /**
     * La tuile « Mes fichiers », dans le compte client.
     *
     * ⚠️ `displayCustomerAccount` est rendu par `customer/page.tpl:99`, PAS
     * par `my-account.tpl` — dont le bloc `my_account_links` est vide dans ce
     * thème. Poser la tuile sur l'autre hook l'aurait rendue invisible, sans
     * la moindre erreur.
     *
     * La tuile ne s'affiche que si le client a au moins une commande : offrir
     * un écran vide à quelqu'un qui n'a rien commandé n'aide personne.
     */
    public function hookDisplayCustomerAccount(): string
    {
        $client = $this->context->customer ?? null;

        if (!\Validate::isLoadedObject($client) || !$client->isLogged()) {
            return '';
        }

        if (\Order::getCustomerNbOrders((int) $client->id) <= 0) {
            return '';
        }

        $this->context->smarty->assign([
            'eko_url_mesfichiers' => $this->context->link->getModuleLink(
                $this->name,
                'mesfichiers',
                [],
                true
            ),
        ]);

        return $this->display(__FILE__, 'views/templates/hook/tuile-mesfichiers.tpl');
    }

    /**
     * Accroche le module à `displayCustomerAccount` sur une boutique servie.
     *
     * Même raison que `poserHookCommande()` : `install()` ne repassera jamais.
     */
    public function poserHookCompte(): bool
    {
        if ($this->isRegisteredInHook('displayCustomerAccount')) {
            return true;
        }

        return (bool) $this->registerHook('displayCustomerAccount');
    }

    /**
     * Combien de jours un prix mémorisé reste commandable.
     *
     * ⚠️ CE NOMBRE EST UN CHOIX MÉTIER, pas une constante technique : c'est la
     * durée pendant laquelle 2M accepte d'honorer un tarif fournisseur figé.
     * Arrêté à 30 jours par Mathieu le 2026-08-13, en connaissance de cause —
     * c'est PLUS LONG que la fenêtre réelle du cookie de panier (20 jours),
     * donc aucun client ne sera jamais bloqué en pratique. Le garde est un
     * filet pour la reprise exceptionnelle, pas un obstacle au parcours.
     */
    public const JOURS_VALIDITE_PRIX = 30;

    /**
     * Au seuil du tunnel : un panier dont le prix a vieilli ne part pas.
     *
     * ─── LE RISQUE QU'IL COUVRE ────────────────────────────────────────────
     *
     * Le prix d'un imprimé est calculé une fois, à la configuration, et
     * mémorisé. Rien ne le repérime : un panier repris des semaines plus tard
     * est facturé au tarif fournisseur du jour où il a été configuré. Le
     * client, lui, paie bien ce qu'on lui montre — c'est la MARGE de la
     * boutique qui absorbe l'écart, en silence.
     *
     * ─── ⚠️ ON REDIRIGE, ON NE CORRIGE PAS ────────────────────────────────
     *
     * Recalculer ici serait pire : le client verrait son total changer entre
     * le panier et le paiement, sans rien avoir demandé. On le ramène au
     * panier en le disant, et il reconfigure.
     *
     * @param array<string,mixed> $params
     */
    /** Au plus une reprise par minute, toutes pages confondues. */
    private const REPRISE_INTERVALLE = 60;

    /**
     * Rejouer UNE poussée de commande en échec.
     *
     * ⚠️ CE FILET N'EXISTAIT PAS, et c'était le défaut le plus lourd du pont.
     * La poussée n'avait lieu qu'à la validation ; l'ERP injoignable à cet
     * instant, le motif partait au journal et plus rien ne se produisait. La
     * boutique encaissait, l'atelier ne voyait jamais le dossier. Le
     * 2026-08-19 l'ERP a été indisponible plusieurs heures : toute commande
     * passée pendant cette fenêtre aurait été orpheline, en silence.
     *
     * Trois brides, parce que ceci s'exécute sur une page vue par un client :
     * jamais en AJAX, au plus une fois par minute, une seule commande. Et
     * l'heure est notée MÊME quand il n'y a rien à reprendre — sans quoi
     * chaque page paierait la requête de sélection.
     *
     * Le tout sous `try` : un filet qui tombe ne doit pas emporter la page.
     */
    private function reprendreUnePoussee(): void
    {
        try {
            // Le configurateur enchaîne les appels AJAX ; leur ajouter une
            // requête vers l'ERP allongerait le chiffrage sous les yeux du
            // client, pour un travail qui n'a aucune urgence.
            if (Tools::getValue('ajax') || (isset($this->context->controller) && $this->context->controller->isXmlHttpRequest())) {
                return;
            }

            $cle = 'EKOSYNC_REPRISE_DERNIERE';

            if (time() - (int) Configuration::getGlobalValue($cle) < self::REPRISE_INTERVALLE) {
                return;
            }

            Configuration::updateGlobalValue($cle, (string) time());

            $idOrder = PousseeCommande::enAttente();

            if ($idOrder === null) {
                return;
            }

            $commande = new Order($idOrder);

            if (!Validate::isLoadedObject($commande)) {
                return;
            }

            $c = $this->client(0);
            $pousseur = new PousseeCommande($c, new ImportTiers(new DepotEko($c), $c, $this->groupes()));
            $r = $pousseur->pousser($commande);

            \PrestaShopLogger::addLog(
                'ekosyncimprimerie — reprise de la commande ' . $idOrder . ' : '
                . (($r['ok'] ?? false) ? ('devis ' . (string) ($r['proposal_code'] ?? $r['proposal_id'] ?? '?')) : ('échec — ' . (string) ($r['message'] ?? ''))),
                ($r['ok'] ?? false) ? 1 : 2,
                null,
                'Order',
                $idOrder
            );
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog(
                'ekosyncimprimerie — reprise de poussée interrompue : ' . mb_substr($e->getMessage(), 0, 300),
                3
            );
        }
    }

    public function hookActionFrontControllerInitAfter(array $params): void
    {
        $this->reprendreUnePoussee();

        try {
            $controleur = $params['controller'] ?? $this->context->controller;

            // Le seuil, et lui seul : le panier doit rester consultable même
            // avec un prix vieilli, sinon le client ne pourrait plus RIEN
            // faire — ni voir, ni corriger.
            if (!($controleur instanceof \OrderController)) {
                return;
            }

            $panier = $this->context->cart ?? null;

            if (!\Validate::isLoadedObject($panier)) {
                return;
            }

            // ⚠️ On demande un MOTIF, plus un âge. L'âge ne voyait qu'une des
            // trois façons de commander un produit lié sans prix de l'ERP : la
            // ligne dont la quantité a changé n'a AUCUN prix mémorisé, donc
            // aucun âge, donc elle était invisible au garde. Voir motifDeRefus().
            $motif = (new PrixConfigure())->motifDeRefus((int) $panier->id, self::JOURS_VALIDITE_PRIX);

            if ($motif === null) {
                return;
            }

            \PrestaShopLogger::addLog(
                'ekosyncimprimerie — commande refusée au seuil du tunnel, motif « ' . $motif
                . ' » (panier ' . (int) $panier->id . ')',
                2,
                null,
                'Cart',
                (int) $panier->id
            );

            $lien = $this->context->link->getPageLink('cart', true, null, ['action' => 'show']);

            \Tools::redirect(
                $lien . (str_contains($lien, '?') ? '&' : '?') . 'eko_prix_perime=' . rawurlencode($motif)
            );
        } catch (\Throwable $e) {
            // Un garde qui tombe ne doit pas emporter le tunnel avec lui : le
            // pire qu'on risque en le laissant passer est un tarif périmé,
            // pas une commande impossible.
            \PrestaShopLogger::addLog(
                'ekosyncimprimerie — garde de péremption interrompu : ' . mb_substr($e->getMessage(), 0, 300),
                3
            );
        }
    }

    /**
     * Accroche le garde de péremption sur une boutique déjà servie.
     */
    public function poserGardePrix(): bool
    {
        if ($this->isRegisteredInHook('actionFrontControllerInitAfter')) {
            return true;
        }

        return (bool) $this->registerHook('actionFrontControllerInitAfter');
    }

    /**
     * Accroche le module à `actionValidateOrder` et crée sa table de suivi.
     *
     * Même raison que les deux précédentes : `install()` ne repasse jamais sur
     * une boutique servie. La table, elle, se crée au même moment — sans elle
     * la poussée n'aurait nulle part où consigner ses échecs, et l'on ne
     * saurait pas qu'une commande n'est jamais partie.
     */
    public function poserPousseeCommande(): bool
    {
        if (!PousseeCommande::installer()) {
            return false;
        }

        if ($this->isRegisteredInHook('actionValidateOrder')) {
            return true;
        }

        return (bool) $this->registerHook('actionValidateOrder');
    }

    /**
     * Accroche le module à `displayOrderDetail` sur une boutique déjà servie.
     *
     * ⚠️ `install()` ne repassera JAMAIS ici : le module est posé depuis le
     * 2026-08-10. Un hook ajouté à la liste d'installation reste donc lettre
     * morte sur la boutique en service — le module se déclare installé, le
     * hook n'est enregistré nulle part, et rien ne le signale.
     *
     * Publique et idempotente, sur le modèle de `poserOnglet()` : elle peut
     * être rejouée sans effet, et l'`upgrade` de la 0.21.0 l'appelle.
     */
    public function poserHookCommande(): bool
    {
        if ($this->isRegisteredInHook('displayOrderDetail')) {
            return true;
        }

        return (bool) $this->registerHook('displayOrderDetail');
    }

    /**
     * La cellule « Fichiers » du panier.
     *
     * ─── ELLE N'EST PLUS INERTE ────────────────────────────────────────────
     *
     * Le bouton était désactivé et le disait, faute de chemin vers le S3. Ce
     * chemin existe désormais : le fichier part en flux vers E-KO, y est
     * analysé, et le verdict revient ici.
     *
     * La case « je déposerai mes fichiers plus tard » n'est pas un pis-aller :
     * beaucoup de clients commandent avant d'avoir leur maquette. Sans elle,
     * ils abandonnent le panier ou déposent n'importe quoi pour passer.
     */
    public function hookDisplayEkoCartFichiers(array $params): string
    {
        $produit = $params['product'] ?? null;

        if (!is_array($produit) && !$produit instanceof \ArrayAccess) {
            return '';
        }

        return $this->zoneDeDepot((int) $this->valeur($produit, 'id_customization'));
    }

    /**
     * La zone de dépôt d'une ligne : bouton, liste des fichiers, état.
     *
     * ⚠️ PUBLIQUE, ET UNIQUE ÉCRIVAIN DE CE BALISAGE. Le panier l'obtient par
     * son hook, l'écran « Mes fichiers » du compte client l'appelle
     * directement. La redessiner là-bas aurait donné deux zones qui divergent
     * — et c'est celle qu'on regarde le moins qui aurait vieilli.
     */
    public function zoneDeDepot(int $idCustomization, bool $forcer = false): string
    {
        // Un article sans configuration n'attend pas de fichier : ce n'est pas
        // un imprimé sur mesure.
        //
        // ⚠️ `$forcer` OUVRE LA ZONE AUX LIGNES D'UN AUTRE MODULE, et c'est
        // délibéré. Un objet publicitaire marqué attend lui aussi un logo, et
        // toute la mécanique — dépôt, analyse, S3, écran « Mes fichiers » —
        // existe déjà ici. La recopier dans le module des objets aurait donné
        // deux zones de dépôt qui divergent, et c'est celle qu'on regarde le
        // moins qui aurait vieilli.
        //
        // Le contrôleur de dépôt, lui, n'a rien à changer : il vérifie déjà
        // que la configuration appartient au panier du visiteur, sans rien
        // exiger des critères d'imprimerie.
        if ($idCustomization <= 0 || (!$forcer && $this->criteresDeLigne($idCustomization) === [])) {
            return '';
        }

        $fichiers = \Eko\SyncImprimerie\Configurateur\FichierClient::deLaLigne($idCustomization);

        $liste = '';

        foreach ($fichiers as $f) {
            $liste .= $this->ligneDeFichier($f);
        }

        $lien = $this->context->link->getModuleLink('ekosyncimprimerie', 'fichier');

        return sprintf(
            '<div class="eko-fichier" data-custo="%d" data-url="%s">'
            . '<label class="btn btn-primary eko-fichier__bouton">%s'
            . '<input type="file" accept=".pdf,.jpg,.jpeg,.png,.tif,.tiff,.eps,.ai,.zip" hidden multiple></label>'
            . '<ul class="eko-fichier__liste">%s</ul>'
            . '<p class="eko-fichier__note">%s</p>'
            . '<label class="eko-fichier__plus-tard">'
            . '<input type="checkbox" class="eko-fichier__differe"%s> %s</label>'
            . '</div>',
            $idCustomization,
            htmlspecialchars($lien, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($this->trans('Ajouter mon fichier', [], 'Modules.Ekosyncimprimerie.Shop'), ENT_QUOTES, 'UTF-8'),
            $liste,
            htmlspecialchars($this->trans(
                'Si vous avez un fichier recto-verso, ne le séparez pas : chargez le PDF directement. '
                . 'Vous pouvez aussi rassembler plusieurs fichiers d\'un même produit dans un .zip. '
                . 'Pour plusieurs modèles, ajoutez le produit autant de fois au panier.',
                [],
                'Modules.Ekosyncimprimerie.Shop'
            ), ENT_QUOTES, 'UTF-8'),
            // La case se coche d'elle-même quand aucun fichier n'est déposé :
            // c'est l'état réel, et le client n'a rien à déclarer pour qu'il
            // soit vrai.
            $fichiers === [] ? ' checked' : '',
            htmlspecialchars($this->trans('Je chargerai mes fichiers plus tard', [], 'Modules.Ekosyncimprimerie.Shop'), ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Une ligne de fichier déposé, avec son état.
     *
     * @param array<string,mixed> $fichier
     */
    private function ligneDeFichier(array $fichier): string
    {
        $statut = (string) ($fichier['statut'] ?? 'pending');

        // Le libellé dit ce que le client doit COMPRENDRE, pas le vocabulaire
        // interne du préflight. « incomplete » ne veut rien dire pour lui ;
        // « nous n'avons pas pu tout vérifier » se lit.
        [$classe, $texte] = match ($statut) {
            'pass' => ['ok', $this->trans('Fichier conforme', [], 'Modules.Ekosyncimprimerie.Shop')],
            'warning' => ['reserve', $this->trans('Conforme, avec réserves', [], 'Modules.Ekosyncimprimerie.Shop')],
            'error' => ['refus', $this->trans('À revoir — voir le détail', [], 'Modules.Ekosyncimprimerie.Shop')],
            'incomplete' => ['reserve', $this->trans('Nous n’avons pas pu tout vérifier', [], 'Modules.Ekosyncimprimerie.Shop')],
            'failed' => ['refus', $this->trans('L’envoi a échoué', [], 'Modules.Ekosyncimprimerie.Shop')],
            'analyzing' => ['attente', $this->trans('Vérification en cours…', [], 'Modules.Ekosyncimprimerie.Shop')],
            default => ['attente', $this->trans('En attente', [], 'Modules.Ekosyncimprimerie.Shop')],
        };

        return sprintf(
            '<li class="eko-fichier__ligne eko-fichier__ligne--%s" data-upload="%d">'
            . '<span class="eko-fichier__nom">%s</span>'
            . '<span class="eko-fichier__etat">%s</span>'
            . '<button type="button" class="eko-fichier__retirer" aria-label="%s">&times;</button>'
            . '</li>',
            $classe,
            (int) ($fichier['upload_id'] ?? 0),
            htmlspecialchars((string) ($fichier['nom'] ?? ''), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($texte, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($this->trans('Retirer', [], 'Modules.Ekosyncimprimerie.Shop'), ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Cellule « Livraison » : expédition, puis réception.
     *
     * ⚠️ La date se pose sur la LIGNE, pas sous le panier. Deux articles
     * d'une même commande n'ont pas le même délai — un roll-up en J+5 à
     * côté de flyers en J+2 — et une date unique mentirait sur l'un des
     * deux. Chaque ligne porte donc la sienne.
     *
     * Deux dates et non une : le délai stocké en base est celui de
     * PRODUCTION, c'est-à-dire la sortie d'atelier. Le client, lui, attend
     * une date de réception. Confondre les deux, c'est promettre pour le
     * jeudi ce qui part le jeudi.
     */
    public function hookDisplayEkoCartLivraison(array $params): string
    {
        $idCustomization = (int) ($params['product']['id_customization'] ?? 0);

        $jours = \Eko\SyncImprimerie\Configurateur\DateLivraison::delaiDeLigne($idCustomization);

        if ($jours <= 0) {
            return '';
        }

        $locale = (string) ($this->context->language->locale ?? 'fr-FR');

        $expedition = \Eko\SyncImprimerie\Configurateur\DateLivraison::dans($jours, $locale);
        $reception = \Eko\SyncImprimerie\Configurateur\DateLivraison::dans($jours + self::JOURS_TRANSPORT, $locale);

        if ($expedition === '' || $reception === '') {
            return '';
        }

        return '<p class="eko-liv"><span class="eko-liv__label">'
            . htmlspecialchars($this->trans('Expédition prévue le', [], 'Modules.Ekosyncimprimerie.Shop'), ENT_QUOTES, 'UTF-8')
            . '</span><strong class="eko-liv__date">' . htmlspecialchars($expedition, ENT_QUOTES, 'UTF-8') . '</strong></p>'
            . '<p class="eko-liv"><span class="eko-liv__label">'
            . htmlspecialchars($this->trans('Livraison prévue le', [], 'Modules.Ekosyncimprimerie.Shop'), ENT_QUOTES, 'UTF-8')
            . '</span><strong class="eko-liv__date">' . htmlspecialchars($reception, ENT_QUOTES, 'UTF-8') . '</strong></p>'
            . '<p class="eko-liv__note">'
            . htmlspecialchars($this->trans('Jours ouvrés, hors jours fériés.', [], 'Modules.Ekosyncimprimerie.Shop'), ENT_QUOTES, 'UTF-8')
            . '</p>';
    }

    /**
     * Cellule « Prix » : barré s'il y a remise, puis HT, puis TTC.
     *
     * L'imprimerie vend à des entreprises. Le montant qui compte pour elles
     * est le HT ; le TTC est ce qu'elles décaissent. Les deux doivent être
     * lisibles sans calcul mental, et la TVA nommée — sinon un client au
     * taux réduit croit à une erreur.
     *
     * Le prix barré ne s'invente pas : il n'apparaît que si PrestaShop a
     * réellement appliqué une remise (règle panier, prix spécifique, tarif
     * revendeur). Un barré décoratif sur un prix jamais pratiqué est une
     * pratique commerciale trompeuse, pas un argument.
     */
    public function hookDisplayEkoCartPrix(array $params): string
    {
        // ⚠️ Ne PAS convertir en tableau. `$params['product']` est un
        // `ProductLazyArray` : un objet qui implémente `ArrayAccess`. Le
        // caster en tableau ne rend pas ses données mais ses propriétés
        // internes, aux noms mangés par les octets nuls de la visibilité —
        // `id_product` devient introuvable et la cellule reste vide sans la
        // moindre erreur. Il se lit par accès tableau, jamais par cast.
        $produit = $params['product'] ?? null;

        if (!is_array($produit) && !$produit instanceof \ArrayAccess) {
            return '';
        }

        $locale = $this->context->currentLocale;
        $devise = (string) ($this->context->currency->iso_code ?? 'EUR');

        // ⚠️ Les montants bruts ne sont PAS dans le tableau présenté.
        // `CartLazyArray::presentProduct()` écrase `total` par sa version
        // formatée — « 68,04 € », que `is_numeric()` rejette — et le HT
        // disparaît dans l'opération. On retourne donc à la source :
        // `Cart::getProducts()`, qui rend `total` (HT) et `total_wt` (TTC)
        // tels que PrestaShop les a calculés et arrondis.
        //
        // Recomposer le total à partir d'un prix unitaire et d'une quantité
        // donnerait des écarts au centime sur les grosses quantités : les
        // arrondis de PrestaShop ne se rejouent pas à la main.
        $ligne = $this->ligneDePanier($produit);

        if ($ligne === null) {
            return '';
        }

        $ht = $this->nombreOuNull($ligne, ['total']);
        $ttc = $this->nombreOuNull($ligne, ['total_wt']);

        if ($ttc === null && $ht === null) {
            return '';
        }

        $sortie = '<div class="eko-prix">';

        // ─── Le barré ──────────────────────────────────────────────────────
        //
        // Il n'apparaît que si le montant SANS remise dépasse réellement le
        // montant facturé — cas d'un tarif revendeur, d'un prix spécifique ou
        // d'une règle panier. Le seuil d'un centime écarte les écarts
        // d'arrondi, qui feraient clignoter un barré de 0,00 € d'économie.
        $quantite = max(1, (int) ($ligne['cart_quantity'] ?? 1));
        $avant = $this->nombreOuNull($ligne, ['price_without_reduction']);

        if ($avant !== null && $ttc !== null && ($avant * $quantite) - $ttc > 0.01) {
            $sortie .= '<span class="eko-prix__barre">'
                . htmlspecialchars($locale->formatPrice($avant * $quantite, $devise), ENT_QUOTES, 'UTF-8')
                . '</span>';
        }

        if ($ht !== null) {
            $sortie .= '<span class="eko-prix__ht">'
                . htmlspecialchars($locale->formatPrice($ht, $devise), ENT_QUOTES, 'UTF-8')
                . ' <em>' . htmlspecialchars($this->trans('HT', [], 'Modules.Ekosyncimprimerie.Shop'), ENT_QUOTES, 'UTF-8') . '</em></span>';
        }

        if ($ttc !== null) {
            $sortie .= '<span class="eko-prix__ttc">'
                . htmlspecialchars($locale->formatPrice($ttc, $devise), ENT_QUOTES, 'UTF-8')
                . ' <em>' . htmlspecialchars($this->trans('TTC', [], 'Modules.Ekosyncimprimerie.Shop'), ENT_QUOTES, 'UTF-8') . '</em></span>';
        }

        // Le taux se DÉDUIT des deux montants plutôt que de se lire dans une
        // clé : le produit peut porter plusieurs taux selon l'adresse, et le
        // rapport HT/TTC est le seul chiffre qui ne puisse pas mentir.
        if ($ht !== null && $ttc !== null && $ht > 0.0) {
            $taux = round((($ttc / $ht) - 1) * 100, 1);

            if ($taux > 0.0) {
                $sortie .= '<span class="eko-prix__tva">'
                    . htmlspecialchars(
                        $this->trans('(TVA %taux% %)', ['%taux%' => rtrim(rtrim(number_format($taux, 1, ',', ''), '0'), ',')], 'Modules.Ekosyncimprimerie.Shop'),
                        ENT_QUOTES,
                        'UTF-8'
                    )
                    . '</span>';
            }
        }

        return $sortie . '</div>';
    }

    /**
     * Lit une clé, que la source soit un tableau ou un `ArrayAccess`.
     *
     * Les hooks du panier reçoivent tantôt l'un, tantôt l'autre selon le
     * point d'appel : `ProductLazyArray` sur la page panier, tableau simple
     * dans le panier latéral. Un `??` direct fonctionne pour les deux ; ce
     * qui casse, c'est le `isset()` implicite sur un `ArrayAccess` dont
     * `offsetExists()` peut mentir. On passe donc par `offsetGet()`.
     */
    private function valeur($source, string $cle, $defaut = null)
    {
        if (is_array($source)) {
            return $source[$cle] ?? $defaut;
        }

        if ($source instanceof \ArrayAccess) {
            try {
                $valeur = $source->offsetGet($cle);
            } catch (\Throwable $e) {
                return $defaut;
            }

            return $valeur ?? $defaut;
        }

        return $defaut;
    }

    /**
     * La ligne brute du panier correspondant au produit présenté.
     *
     * L'appariement se fait sur le TRIPLET produit / déclinaison /
     * personnalisation. Le seul identifiant de produit ne suffit pas : deux
     * lignes du même flyer configurées différemment — l'une en A5 recto,
     * l'autre en A4 recto-verso — coexistent légitimement dans un panier
     * d'imprimeur, et n'ont ni le même prix ni le même délai. Apparier sur
     * `id_product` seul afficherait le montant de la première sur les deux.
     */
    private function ligneDePanier($produit): ?array
    {
        $cart = $this->context->cart;

        if (!\Validate::isLoadedObject($cart)) {
            return null;
        }

        $cible = [
            (int) $this->valeur($produit, 'id_product'),
            (int) $this->valeur($produit, 'id_product_attribute'),
            (int) $this->valeur($produit, 'id_customization'),
        ];

        foreach ($cart->getProducts() as $ligne) {
            $candidat = [
                (int) ($ligne['id_product'] ?? 0),
                (int) ($ligne['id_product_attribute'] ?? 0),
                (int) ($ligne['id_customization'] ?? 0),
            ];

            if ($candidat === $cible) {
                return $ligne;
            }
        }

        return null;
    }

    /**
     * Première clé numériquement exploitable d'une liste, ou `null`.
     *
     * Les noms de clés du présentateur de panier ont bougé entre PrestaShop
     * 1.7, 8 et 9. Plutôt que de parier sur l'un d'eux — et de rendre une
     * cellule vide sans la moindre erreur le jour où il disparaît — on essaie
     * les noms connus dans l'ordre et on s'arrête au premier qui répond.
     */
    private function nombreOuNull(array $source, array $cles): ?float
    {
        foreach ($cles as $cle) {
            if (!array_key_exists($cle, $source)) {
                continue;
            }

            $valeur = $source[$cle];

            if (is_numeric($valeur)) {
                return (float) $valeur;
            }
        }

        return null;
    }

    public function hookDisplayProductPriceBlock(array $params): string
    {
        $type = (string) ($params['type'] ?? '');

        // ─── « À partir de », devant le prix d'appel ────────────────────────
        //
        // Le prix d'un imprimé ne tient pas dans un nombre : il dépend du
        // format, du papier, de la quantité et du délai. La fiche porte donc
        // le PLANCHER — la commande la moins chère de la grille du
        // fournisseur — et cette mention dit que c'en est un.
        //
        // Sans elle, la liste annonce « 9,00 € » pour une carte de visite et
        // le client découvre 42 € au configurateur : la mention n'est pas un
        // ornement, c'est ce qui rend le chiffre honnête.
        // ⚠️ CE QUI SUIT N'EST PLUS VRAI — relevé le 2026-08-24 sur /recherche :
        // le thème appelle DÉSORMAIS `before_price` sur les listes, en plus de
        // `custom_price`. La mention sortait donc DEUX FOIS côte à côte :
        // « À partir de » nu, puis « À partir de 32,40 € ». D'où le garde plus
        // bas. On ne supprime pas la branche `before_price` : rien ne dit que
        // tous les gabarits l'appellent, et la perdre coûterait la mention là
        // où elle est seule à sortir.
        //
        // L'affirmation d'origine, conservée pour mémoire :
        // ⚠️ `before_price` N'EST JAMAIS APPELÉ PAR CE THÈME, et c'est mesuré :
        // `themes/akira/templates/catalog/_partials/product-prices.tpl` appelle
        // `old_price`, `custom_price`, `weight`, `price` et `after_price`, et
        // rien d'autre. La mention « À partir de » n'a donc JAMAIS été affichée
        // sur cette boutique — ni sur les 84 fiches de sous-traitance, ni
        // ailleurs. Le commentaire d'origine expliquait pourtant que c'est elle
        // qui « rend le chiffre honnête » : un flyer annoncé 9,00 € que le
        // client découvre à 42 € au configurateur.
        //
        // `custom_price`, lui, est appelé — et il REMPLACE le prix quand il
        // rend quelque chose. On y rend donc la mention ET le prix, dans le
        // bon ordre. On garde `before_price` pour les thèmes qui l'appellent.
        if ($type === 'before_price' || $type === 'custom_price') {
            $idProduct = $this->produitDuBlocPrix($params);

            if ($idProduct <= 0 || (new LiaisonProduit())->pour($idProduct) === null) {
                return '';
            }

            $mention = '<span class="eko-des">'
                . htmlspecialchars($this->trans('À partir de', [], 'Modules.Ekosyncimprimerie.Shop'), ENT_QUOTES, 'UTF-8')
                . '</span> ';

            // Le thème appelle `before_price` PUIS `custom_price` sur la même
            // fiche. On retient qu'une mention vient d'être posée, et le second
            // appel se contente alors du prix. Le drapeau est CONSOMMÉ, pas
            // seulement lu : un produit affiché deux fois sur la même page
            // retrouve sa mention au second passage.
            static $mentionEnAttente = [];

            if ($type === 'before_price') {
                $mentionEnAttente[$idProduct] = true;

                return $mention;
            }

            if (!empty($mentionEnAttente[$idProduct])) {
                unset($mentionEnAttente[$idProduct]);
                $mention = '';
            }

            // Le prix DÉJÀ FORMATÉ par PrestaShop — devise, séparateur, place
            // du symbole. Le reformater ici ferait diverger cette fiche du
            // reste de la boutique.
            //
            // ⚠️ MÊME PIÈGE QUE L'IDENTIFIANT : `$params['product']` est un
            // `ProductLazyArray`, et `??` y interroge `offsetExists()`. Lu
            // ainsi, le prix ressortait VIDE — et comme `custom_price`
            // REMPLACE le prix du gabarit, la fiche affichait « À partir de »
            // suivi de rien. Sur les 84 fiches en ligne.
            $prix = $this->valeurDuBlocPrix($params, 'price');

            // Sans prix lisible, on rend une chaîne vide : le gabarit retombe
            // alors sur `$product.price`. Mieux vaut perdre la mention que le
            // chiffre.
            if ($prix === '') {
                return '';
            }

            return $mention . htmlspecialchars($prix, ENT_QUOTES, 'UTF-8');
        }

        if ($type !== 'after_price') {
            return '';
        }

        if (!($this->context->controller instanceof ProductController)) {
            return '';
        }

        return $this->hookDisplayProductAdditionalInfo($params);
    }

    /**
     * L'identifiant du produit dont on rend le bloc prix.
     *
     * ⚠️ `$params['product']` n'est PAS un tableau sur la fiche produit : c'est
     * un `ProductLazyArray`. L'opérateur `??` y interroge `offsetExists()`, qui
     * ne répond pas comme sur un tableau — la lecture rendait donc 0, et la
     * mention « À partir de » ne sortait jamais. Le même code marchait
     * parfaitement quand on lui passait un vrai tableau, ce qui rendait le
     * défaut invisible à tout essai en ligne de commande.
     *
     * On lit donc par accès direct sous `isset()`, et on retombe sur la requête
     * si rien ne vient : sur une fiche produit, `id_product` y est toujours.
     *
     * @param  array<string,mixed>  $params
     */
    /**
     * Une valeur du produit passé au bloc prix, lue quel qu'en soit le type.
     *
     * @param  array<string,mixed>  $params
     */
    private function valeurDuBlocPrix(array $params, string $cle): string
    {
        $p = $params['product'] ?? null;

        if (is_array($p) && isset($p[$cle])) {
            return (string) $p[$cle];
        }

        if (is_object($p)) {
            if ($p instanceof \ArrayAccess && isset($p[$cle])) {
                return (string) $p[$cle];
            }

            if (isset($p->{$cle})) {
                return (string) $p->{$cle};
            }
        }

        return '';
    }

    private function produitDuBlocPrix(array $params): int
    {
        $p = $params['product'] ?? null;

        foreach (['id_product', 'id'] as $cle) {
            if (is_array($p) && isset($p[$cle])) {
                return (int) $p[$cle];
            }

            if (is_object($p)) {
                if ($p instanceof \ArrayAccess && isset($p[$cle])) {
                    return (int) $p[$cle];
                }

                if (isset($p->{$cle})) {
                    return (int) $p->{$cle};
                }
            }
        }

        return (int) Tools::getValue('id_product');
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

        // ─── Un gabarit par FORMAT du produit ──────────────────────────────
        //
        // Réécrit le 2026-08-13. La version précédente listait les fichiers
        // d'un dossier `modules/ekosyncimprimerie/gabarits/<id>/` — mécanisme
        // abandonné pour deux raisons : le dossier d'un module est effacé à sa
        // réinstallation, et il fallait déposer à la main un gabarit que le
        // format fini permet de calculer.
        //
        // La liste vient donc des CARACTÉRISTIQUES du produit. Chaque format
        // qui donne un gabarit — calculé ou déposé — obtient sa ligne ; les
        // autres n'en ont pas, parce qu'un lien mort vaut moins que rien.
        $gabarits = [];
        $nomProduit = (string) (new Product($idProduct, false, (int) $this->context->language->id))->name;

        foreach ($this->formatsDuProduit($idProduct) as $format) {
            $spec = \Eko\SyncImprimerie\Configurateur\SpecTechnique::depuisFormat($format);
            $lien = $this->lienGabarit($idProduct, $format, $spec);

            if ($lien === '') {
                continue;
            }

            $gabarits[] = [
                'nom' => $nomProduit === '' ? $format : $nomProduit . ' — ' . $format,
                'url' => $lien,
                // Un gabarit déposé à la main peut porter une découpe que le
                // calcul ne sait pas produire : le dire évite qu'on le prenne
                // pour un simple rectangle.
                'depose' => \Eko\SyncImprimerie\Configurateur\DepotGabarit::lire($idProduct, $format) !== null,
            ];
        }

        return [
            'lignes' => $lignes,
            'gabarits' => $gabarits,
            // Le guide propre à ce produit, quand le marchand en a écrit un.
            'guide' => ServicesProduit::guide($idProduct),
        ];
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
                // Le point d'entrée des cotes. Il rend le MÊME bloc que le
                // panier, calculé par le même code — le configurateur ne fait
                // que l'afficher.
                'url_specs' => $this->context->link->getModuleLink($this->name, 'specifications'),
                'technique' => $this->ficheTechnique($idProduct),
            ]);

            return $this->fetch('module:' . $this->name . '/views/templates/hook/configurateur-poc.tpl');
        }

        // Le second sous-traitant : un FORMULAIRE de champs indépendants, là
        // où le premier descend un arbre. Même coque, même feuille de style,
        // même script — le gabarit dit seulement lequel des deux modes servir.
        if ($liaison['source'] === LiaisonProduit::SOURCE_REALISAPRINT) {
            $this->configurateurRendu = true;

            $this->smarty->assign('eko', [
                'id_product' => $idProduct,
                // ⚠️ Un contrôleur À PART, et pas celui du premier. Il hérite
                // du sien pour la TVA, les montants et les dates, mais son
                // garde de source refuse l'autre catalogue : servir un produit
                // par le mauvais relais rendrait un formulaire vide, sans dire
                // pourquoi.
                'url' => $this->context->link->getBaseLink()
                    . 'index.php?fc=module&module=' . $this->name . '&controller=configrp',
                // La fiche technique est réglée PAR LE MARCHAND, produit par
                // produit — résolution, mode colorimétrique, fonds perdus. Elle
                // ne vient d'aucun fournisseur, et l'omettre ici faisait
                // disparaître un réglage qu'il avait pris la peine de saisir.
                'technique' => $this->ficheTechnique($idProduct),
                // ⚠️ LE GABARIT SE CALCULE AUX COTES SAISIES, pas d'avance.
                //
                // Le bloc technique listait un gabarit par « format » du
                // produit, lus dans ses caractéristiques. Or ces valeurs sont
                // des PHRASES — « 1753 formats, de 20 × 30 à 200 × 300 cm » —
                // et l'extracteur de cotes y attrapait une des deux bornes :
                // le client téléchargeait un plan de travail à une taille
                // arbitraire, sans rapport avec sa commande.
                //
                // Le contrôleur sait déjà recevoir `hauteur` et `largeur`. On
                // lui passe donc son adresse nue, et le configurateur la
                // complète avec ce que le client vient de taper.
                'url_gabarit' => $this->context->link->getModuleLink(
                    $this->name,
                    'gabarit',
                    [],
                    true
                ),
            ]);

            return $this->fetch('module:' . $this->name . '/views/templates/hook/configurateur-rp.tpl');
        }

        // ⚠️ LES TROIS SORTIES CI-DESSOUS SE TAISAIENT, et c'était le défaut.
        //
        // Cette fiche est LIÉE : son prix ne peut venir que de l'ERP. En rendant
        // une chaîne vide, on laissait la page se dresser avec la mention
        // « À partir de » du module devant le prix de fiche de PrestaShop, et
        // les deux boutons d'achat actifs. Sur une fiche créée par le pont, ce
        // prix vaut 0,00 €.
        //
        // Le commentaire d'origine disait « on se tait plutôt que d'afficher un
        // formulaire vide : un configurateur sans champ inviterait à commander
        // n'importe quoi ». L'intention était juste, le geste l'inversait : se
        // taire laissait justement commander n'importe quoi, au prix de
        // PrestaShop. Ce qu'il fallait, c'est un bloc qui VERROUILLE.
        $ekoProductId = (new LiaisonProduit())->produitAtelier($idProduct);

        if ($ekoProductId === null) {
            // Liaison d'atelier dont la référence n'est pas un identifiant.
            // Cas NOMINAL le jour où le pont écrit un code plutôt qu'un nombre.
            return $this->blocAtelierIndisponible($idProduct);
        }

        $r = $this->client()->appeler('GET', '/api/v1/printing/products/' . (int) $ekoProductId . '/variables');

        if (!$r['ok']) {
            // ERP muet : jeton révoqué, 500, lenteur. Vu en vrai le 2026-08-19.
            return $this->blocAtelierIndisponible($idProduct);
        }

        $variables = $r['donnees']['data'] ?? [];

        if (!is_array($variables) || $variables === []) {
            // Produit d'atelier sans variable déclarée : rien à configurer,
            // donc rien à chiffrer honnêtement.
            return $this->blocAtelierIndisponible($idProduct);
        }

        $this->smarty->assign('eko', [
            'id_product' => $idProduct,
            'variables' => $variables,
            'indisponible' => false,
            // Le générateur de gabarits, appelé avec les COTES saisies : sur un
            // produit d'atelier il n'existe aucun format catalogue dont on
            // pourrait déduire un plan de travail.
            'url_gabarit' => $this->context->link->getBaseLink()
                . 'index.php?fc=module&module=' . $this->name . '&controller=gabarit',
            // Le seuil au-delà duquel le gabarit est réduit. Il vient de la
            // classe qui le décide : l'écrire une seconde fois dans le gabarit
            // Smarty ferait diverger l'annonce du comportement.
            'gabarit_seuil_mm' => \Eko\SyncImprimerie\Configurateur\Gabarit::COTE_PDF_MAX_MM,
            'gabarit_echelles' => \Eko\SyncImprimerie\Configurateur\Gabarit::ECHELLES,
            // Le seuil au-delà duquel le gabarit est réduit. Il vient de la
            // classe qui le décide : l'écrire une seconde fois dans le gabarit
            // Smarty ferait diverger l'annonce du comportement.
            'gabarit_seuil_mm' => \Eko\SyncImprimerie\Configurateur\Gabarit::COTE_PDF_MAX_MM,
            'gabarit_echelles' => \Eko\SyncImprimerie\Configurateur\Gabarit::ECHELLES,
            // Ce que le marchand a saisi au back-office : résolution, mode
            // colorimétrique, fonds perdus, guide, gabarits. Il le remplissait
            // pour toute fiche, et côté atelier rien n'en ressortait.
            'technique' => $this->ficheTechnique($idProduct),
            // Les prestations de l'imprimeur — bon à tirer, création graphique.
            // Elles sont servies DÈS LE RENDU, et pas seulement dans la réponse
            // de chiffrage : le client doit pouvoir les choisir avant qu'un
            // prix existe. Les réglages étaient déjà en base, avec leur cascade
            // produit → boutique ; seul le chemin d'atelier ne les lisait pas.
            'services' => $this->prestationsAvecPrix($idProduct),
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
     * Les prestations d'un produit, chaque option portant son prix EN TOUTES
     * LETTRES.
     *
     * Le montant est formaté ici et non dans le navigateur : le JavaScript ne
     * sait ni quelle langue ni quelle devise il sert, PrestaShop si. C'est la
     * même règle que pour le prix de la fiche.
     *
     * `Tools::displayPrice()` n'existe plus en PrestaShop 9 : on passe par la
     * locale du contexte. Si elle manquait, l'option sort sans son prix plutôt
     * qu'avec un montant faux.
     *
     * @return list<array<string, mixed>>
     */
    private function prestationsAvecPrix(int $idProduct): array
    {
        $services = \Eko\SyncImprimerie\Configurateur\ServicesProduit::services($idProduct);

        $iso = '';
        $locale = null;

        try {
            $locale = $this->context->currentLocale;
            $iso = (string) $this->context->currency->iso_code;
        } catch (\Throwable $e) {
            $locale = null;
        }

        foreach ($services as &$service) {
            foreach ($service['options'] as &$option) {
                $centimes = (int) ($option['centimes'] ?? 0);
                $option['prix_texte'] = '';

                if ($centimes > 0 && $locale !== null && $iso !== '') {
                    try {
                        // ⚠️ LE MÊME RÉGIME QUE LE RESTE DE LA PAGE.
                        //
                        // `centimes` est un montant HORS TAXE. Servi tel quel
                        // sur une boutique qui affiche du TTC, il annonçait
                        // 15,00 € pour un bon à tirer facturé 18,00 € — et le
                        // total, lui, était juste. Deux prix pour la même
                        // prestation sur le même écran.
                        $montant = $centimes / 100;

                        if (!\Eko\SyncImprimerie\Configurateur\ReglesBoutique::afficheHorsTaxe()) {
                            $montant = \TaxManagerFactory::getManager(
                                \Eko\SyncImprimerie\Configurateur\ReglesBoutique::adresseFiscale($this->context->cart),
                                (int) \Product::getIdTaxRulesGroupByIdProduct($idProduct)
                            )->getTaxCalculator()->addTaxes($montant);
                        }

                        $option['prix_texte'] = $locale->formatPrice($montant, $iso);
                    } catch (\Throwable $e) {
                        $option['prix_texte'] = '';
                    }
                }
            }
            unset($option);
        }
        unset($service);

        return $services;
    }

    /**
     * Le configurateur d'atelier, rendu en état d'indisponibilité.
     *
     * La racine `.eko-configurateur` est là, avec ses attributs : c'est elle
     * que cherche `demarrer()`. Le script masque alors le prix natif, ferme les
     * deux boutons d'achat, et s'arrête sans rien chiffrer — un formulaire sans
     * champ obtiendrait sinon un prix pour les valeurs par défaut, et rouvrirait
     * les boutons sur une pièce que le client n'a pas pu configurer.
     *
     * Le client lit pourquoi, et on lui propose un devis. C'est moins bien
     * qu'un prix ; c'est infiniment mieux qu'un prix faux.
     */
    private function blocAtelierIndisponible(int $idProduct): string
    {
        $this->smarty->assign('eko', [
            'id_product' => $idProduct,
            'variables' => [],
            'indisponible' => true,
            // Le générateur de gabarits, appelé avec les COTES saisies : sur un
            // produit d'atelier il n'existe aucun format catalogue dont on
            // pourrait déduire un plan de travail.
            'url_gabarit' => $this->context->link->getBaseLink()
                . 'index.php?fc=module&module=' . $this->name . '&controller=gabarit',
            // Le prix est indisponible, pas les gabarits : le client peut
            // toujours télécharger de quoi préparer son fichier pendant qu'il
            // demande un devis.
            'technique' => $this->ficheTechnique($idProduct),
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
    /**
     * L'éditeur en liste, dans le back-office.
     *
     * Chargé sur les seuls écrans qui en ont un — la fiche produit et l'écran
     * de réglages. Un module qui pose ses fichiers sur tout le back-office
     * ralentit des pages qui n'en font rien, et finit par entrer en conflit
     * avec un autre.
     */
    public function hookActionAdminControllerSetMedia(): void
    {
        $ecran = (string) (Tools::getValue('controller') ?: '');

        if (!in_array($ecran, ['AdminProducts', self::ONGLET], true)) {
            return;
        }

        $controleur = $this->context->controller ?? null;

        // ⚠️ ON VÉRIFIE AVANT D'APPELER. Ce hook se déclenche aussi là où il n'y
        // a pas de contrôleur hérité — écran Symfony, appel hors requête — et
        // `addCSS()` sur `null` lève une fatale AU MILIEU du chargement de la
        // page d'administration. Un module n'a pas à casser un écran parce
        // qu'il n'a pas su poser sa feuille de style.
        if (!is_object($controleur) || !method_exists($controleur, 'addCSS')) {
            return;
        }

        $controleur->addCSS($this->_path . 'views/css/bo-liste.css');
        $controleur->addJS($this->_path . 'views/js/bo-liste.js');
    }

    public function hookActionFrontControllerSetMedia(): void
    {
        if (!($this->context->controller instanceof ProductController)) {
            return;
        }

        $idProduct = (int) Tools::getValue('id_product');
        $liaison = $idProduct > 0 ? (new LiaisonProduit())->pour($idProduct) : null;

        if ($liaison === null) {
            return;
        }

        // ⚠️ UNE SEULE FEUILLE POUR LES DEUX CONFIGURATEURS.
        //
        // Elle porte le système visuel entier — jetons, cartes, lignes de
        // critères, récapitulatif — et elle est éprouvée sur 84 fiches en
        // production. Le configurateur d'atelier ÉMET LES MÊMES CLASSES plutôt
        // que d'avoir sa copie : une seconde feuille aurait divergé de la
        // première à la toute première retouche, et le visiteur aurait vu deux
        // boutiques. Toute correction visuelle profite désormais aux deux.
        $this->context->controller->registerStylesheet(
            'ekosync-configurateur',
            'modules/' . $this->name . '/views/css/configurateur-poc.css',
            ['media' => 'all', 'priority' => 200]
        );

        // Le complément propre à l'atelier : les cotes libres, que l'arbre de
        // choix discrets de la sous-traitance n'a pas.
        $this->context->controller->registerStylesheet(
            'ekosync-configurateur-atelier',
            'modules/' . $this->name . '/views/css/configurateur.css',
            ['media' => 'all', 'priority' => 201]
        );

        // Les deux scripts diffèrent vraiment : l'un descend un arbre, l'autre
        // poste des champs libres. On ne pose que celui dont la fiche dépend.
        // ⚠️ UN SEUL SCRIPT POUR LES DEUX SOUS-TRAITANCES, comme il n'y a
        // qu'une feuille de style. Il porte les quantités, les délais, les
        // prestations, le récapitulatif, l'ajout au panier et le masquage du
        // prix natif — rien de tout cela ne dépend du fournisseur. Ce qui en
        // dépend, c'est la forme des options : un arbre d'un côté, un
        // formulaire de l'autre, et le gabarit dit lequel par `data-mode`.
        if ($liaison['source'] === LiaisonProduit::SOURCE_PRINTOCLOCK
            || $liaison['source'] === LiaisonProduit::SOURCE_REALISAPRINT) {
            $this->context->controller->registerJavascript(
                'ekosync-configurateur-poc',
                'modules/' . $this->name . '/views/js/configurateur-poc.js',
                ['position' => 'bottom', 'priority' => 201]
            );

            return;
        }

        $this->context->controller->registerJavascript(
            'ekosync-configurateur',
            'modules/' . $this->name . '/views/js/configurateur.js',
            ['position' => 'bottom', 'priority' => 200]
        );
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

    /**
     * Le client HTTP vers E-KO.
     *
     * ⚠️ `$dureeCache` existe pour UN cas précis : le sondage d'un statut de
     * préflight. Le cache par défaut retient tout GET pendant quinze minutes ;
     * un statut mis en cache annoncerait « analyse en cours » un quart d'heure
     * après la fin, et le client attendrait devant un écran qui ne bouge plus.
     *
     * Passer 0 le désactive pour cet appel-là, sans toucher au réglage général
     * — que le reste du module a de bonnes raisons d'employer.
     */
    public function client(?int $dureeCache = null): ClientEko
    {
        return new ClientEko(
            (string) Configuration::get(self::CLE_BASE),
            (string) Configuration::get(self::CLE_JETON),
            $dureeCache ?? $this->dureeCache()
        );
    }

    /**
     * Les critères de configuration d'une ligne, pour le contrôleur de dépôt.
     *
     * Simple ouverture de `criteresDeLigne()` : le contrôleur en a besoin pour
     * composer l'attendu du préflight, et il doit le tirer du MÊME endroit que
     * la fenêtre technique et le gabarit. Trois lectures d'une même donnée
     * finiraient par diverger ; une seule méthode, trois appelants.
     *
     * @return array<string,string>
     */
    public function criteresDeLignePublic(int $idCustomization): array
    {
        return $this->criteresDeLigne($idCustomization);
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
