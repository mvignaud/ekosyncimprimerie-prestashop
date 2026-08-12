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
require_once __DIR__ . '/src/Configurateur/Personnalisation.php';
require_once __DIR__ . '/src/Configurateur/IconeSvg.php';

use Eko\SyncImprimerie\Client\ClientEko;
use Eko\SyncImprimerie\Client\DepotEko;
use Eko\SyncImprimerie\Client\Groupes;
use Eko\SyncImprimerie\Client\ImportTiers;
use Eko\SyncImprimerie\Configurateur\LiaisonProduit;
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

    public function __construct()
    {
        $this->name = 'ekosyncimprimerie';
        $this->tab = 'front_office_features';
        $this->version = '0.18.0';
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
    public function hookDisplayAdminProductsMainStepLeftColumnMiddle(array $params): string
    {
        $idProduct = (int) ($params['id_product'] ?? 0);

        if ($idProduct <= 0) {
            return '';
        }

        // Les fichiers de l'éditeur voyagent AVEC le bloc. Le pipeline de
        // `setMedia` ne traverse pas de façon fiable l'écran produit de
        // PrestaShop 9, qui est un écran Symfony : posées ici, les balises
        // arrivent forcément, puisqu'elles font partie du HTML rendu.
        return '<div class="eko-bo">'
            . '<link rel="stylesheet" href="' . $this->_path . 'views/css/bo-liste.css">'
            . $this->boLiaison($idProduct)
            . $this->boTechnique($idProduct)
            . $this->boServices($idProduct)
            . $this->boVentesPhares($idProduct)
            . '<script src="' . $this->_path . 'views/js/bo-liste.js" defer></script>'
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

        // L'atelier : le prix y est CALCULÉ.
        $r = $this->client()->appeler('GET', '/api/v1/printing/products?per_page=100');

        if ($r['ok']) {
            $groupe = '';

            foreach ((array) ($r['donnees']['data'] ?? []) as $p) {
                if (!is_array($p)) {
                    continue;
                }

                $valeur = LiaisonProduit::SOURCE_ATELIER . ':' . (int) ($p['id'] ?? 0);
                $groupe .= sprintf(
                    '<option value="%s"%s>%s</option>',
                    htmlspecialchars($valeur),
                    ($sourceActuelle === LiaisonProduit::SOURCE_ATELIER
                        && $refActuelle === (string) ($p['id'] ?? '')) ? ' selected' : '',
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
                $groupe .= sprintf(
                    '<option value="%s"%s>%s%s</option>',
                    htmlspecialchars($valeur),
                    ($sourceActuelle === LiaisonProduit::SOURCE_PRINTOCLOCK && $refActuelle === $code) ? ' selected' : '',
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

        return '<div class="form-group"><label class="form-control-label">'
            . $this->trans('Produit E-KO', [], 'Modules.Ekosyncimprimerie.Admin')
            . '</label>'
            . '<select name="ekosync_produit" class="form-control">' . $options . '</select>'
            . $avertissements
            . '<p class="help-block">'
            . $this->trans(
                'Lier cette fiche fait venir son prix de l\'ERP. Sans liaison, PrestaShop garde la main.',
                [],
                'Modules.Ekosyncimprimerie.Admin'
            )
            . '</p></div>';
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
    private function attributsListe(string $icones): string
    {
        return sprintf(
            'data-icones="%s" data-depot="%s" data-libelle-ajouter="%s"'
            . ' data-libelle-retirer="%s" data-libelle-depot="%s" data-libelle-icone="%s"'
            . ' data-echec-depot="%s"',
            htmlspecialchars($icones),
            htmlspecialchars($icones === '' ? '' : $this->urlDepotIcone()),
            htmlspecialchars($this->trans('Ajouter une ligne', [], 'Modules.Ekosyncimprimerie.Admin')),
            htmlspecialchars($this->trans('Retirer cette ligne', [], 'Modules.Ekosyncimprimerie.Admin')),
            htmlspecialchars($this->trans('Déposer un SVG', [], 'Modules.Ekosyncimprimerie.Admin')),
            htmlspecialchars($this->trans('Icône…', [], 'Modules.Ekosyncimprimerie.Admin')),
            htmlspecialchars($this->trans('Dépôt refusé.', [], 'Modules.Ekosyncimprimerie.Admin'))
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
     * Une ligne par option, `Libellé|supplément en euros`. C'est ce qu'un
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
                "Je dispose de mon fichier|0\nCréation simple|35\nCréation avancée|99",
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
                . ' ' . $this->attributsListe('')
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
                'Une ligne par option : « Libellé|supplément en euros ». La première ligne est le choix par défaut — elle doit être gratuite.',
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

            (new LiaisonProduit())->lier(
                $idProduct,
                $parts[0] ?? '',
                $parts[1] ?? ''
            );
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
