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
use Eko\SyncImprimerie\Configurateur\Personnalisation;
use Eko\SyncImprimerie\Configurateur\PrixConfigure;
use Eko\SyncImprimerie\Configurateur\DateLivraison;
use Eko\SyncImprimerie\Configurateur\ReglesBoutique;
use Eko\SyncImprimerie\Configurateur\ServicesProduit;
use Eko\SyncImprimerie\Configurateur\ReponseJson;

class EkosyncimprimeriePrixModuleFrontController extends ModuleFrontController
{
    /**
     * Longueur maximale d'une configuration écrite en personnalisation.
     *
     * Mesurée en base le 2026-08-13 : `customized_data.value` est un
     * `varchar(1024)`.
     *
     * ⚠️ PLUS DE COPIE LOCALE. Ce fichier annonçait 1024 et
     * `Personnalisation::tronquer()` 255 — deux limites pour une même colonne,
     * dans le même dépôt, et c'est la plus basse qui décidait en silence : une
     * configuration longue perdait les trois quarts de ce qui tenait, sans
     * erreur ni avertissement, jusque sur la facture.
     *
     * La valeur vit désormais à un seul endroit, à côté du champ qu'elle
     * mesure.
     */
    private const LONGUEUR_CONFIGURATION = Personnalisation::LONGUEUR_VALEUR;

    /**
     * Les catégories d'avertissement qui regardent le CLIENT.
     *
     * En minuscules : la comparaison passe par `Tools::strtolower()`, qui gère
     * l'accent de « Lés ». Toute autre catégorie — « Matière … », « Tâche »,
     * « Imposition » — est un diagnostic de moteur, et ne s'affiche pas.
     */
    private const CATEGORIES_CLIENT = ['lés', 'les', 'laize'];

    /** Voir `debutDestineAuClient()` : dette nommée, en attente de l'ERP. */
    private const DEBUTS_CLIENT = ['laize inconnue'];

    /** Au-delà, ce n'est plus un avertissement qu'on lit, c'est un journal. */
    private const NOMBRE_AVERTISSEMENTS = 5;

    /** Un avertissement plus long qu'une phrase n'est plus lu avant de commander. */
    private const LONGUEUR_AVERTISSEMENT = 300;

    /**
     * ⚠️ SANS EFFET, et c'est mesuré.
     *
     * `Controller::__construct()` (Controller.php:252) l'écrase par
     * `isAjax()` avant que quiconque la lise. C'est en la croyant décisive
     * qu'on a laissé ce contrôleur répondre 500 depuis son écriture. Ce qui
     * garantit la sortie, c'est `ReponseJson::rendre()`, et rien d'autre.
     *
     * @var bool
     */
    public $ajax = true;

    /**
     * Tout se joue ici, et pas dans `displayAjax()`.
     *
     * `Controller::run()` appelle `postProcess()` PUIS `initContent()`, et
     * finit par `display()`. Un contrôleur de module qui ne rend aucun gabarit
     * meurt dans le dernier.
     *
     * ⚠️ CE COMMENTAIRE AFFIRMAIT que `ajaxRender()` « pose l'en-tête et
     * termine », et que la branche AJAX de `run()` nous éviterait `display()`.
     * Les deux étaient faux, et la mesure du 2026-08-13 l'a montré : chaque
     * réponse de ce contrôleur — LES SUCCÈS COMPRIS — partait en HTTP 500,
     * derrière un corps JSON parfaitement correct. La démonstration complète
     * est dans `ReponseJson`, à côté du remède.
     *
     * On sort donc par `ReponseJson::rendre()`, qui termine la requête pour de
     * bon.
     */
    public function postProcess()
    {
        ReponseJson::rendre($this->chiffrer());
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
            //
            // Ce refus-là n'est PAS une saisie incomplète : `Cart::add()` a
            // échoué, c'est-à-dire la boutique. Il se rend en 500 pour rester
            // visible d'une supervision.
            return $this->refus('Panier indisponible.', ReponseJson::PANNE);
        }

        // ⚠️ ON CONTRÔLE ICI, ON ÉCRIT PLUS BAS.
        //
        // Cette ligne posait l'ENTIÈRE personnalisation — « Votre configuration :
        // Largeur 2000 mm · Bâche POWERJET… » — AVANT d'avoir demandé le moindre
        // prix. Quand l'ERP refusait ensuite, le panier gardait le texte de
        // l'option refusée à côté du prix de la précédente : deux affirmations
        // contradictoires sur la même ligne, dont une fausse.
        //
        // On ne garde donc ici que ce qui ne coûte rien et ne laisse aucune
        // trace : la fiche accepte-t-elle d'être personnalisée ? Sinon, on
        // refuse tout de suite, sans dépenser un appel à l'ERP.
        $champ = Personnalisation::champTexte($idProduct, Personnalisation::CHAMP_CONFIGURATION);

        if ($champ <= 0) {
            return $this->refus(
                'Ce produit n\'accepte pas de personnalisation : le configurateur ne peut pas retenir le choix.'
            );
        }

        /** @var Ekosyncimprimerie $module */
        $module = $this->module;

        // Toutes les variables déclarées partent, y compris celles laissées à
        // leur défaut : sans elles, le moteur exclut des lignes par exception.
        $variables = $this->completerVariables($variables, $this->modeleVariables($ekoProductId));

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
            //
            // Mais « l'ERP a refusé » et « l'ERP n'a pas répondu » arrivent
            // tous deux ici avec `ok` à faux. Son code HTTP tranche : 4xx, il
            // a jugé la demande (422) ; le reste, il est en peine (502).
            return $this->refus(
                $r['erreur'] !== '' ? $r['erreur'] : 'Configuration impossible à chiffrer.',
                ReponseJson::statutAmont((int) $r['code'])
            );
        }

        $donnees = $r['donnees']['data'] ?? [];
        $centimes = (int) ($donnees['unit_price_ht_cents'] ?? 0);

        if ($centimes <= 0) {
            return $this->refus('L\'ERP n\'a pas rendu de prix pour cette configuration.');
        }

        $delai = isset($donnees['estimated_lead_days']) ? (int) $donnees['estimated_lead_days'] : null;

        // ─── Les prestations de l'imprimeur ─────────────────────────────
        //
        // Elles s'AJOUTENT au prix de l'ERP, elles ne le recalculent pas : le
        // montant de l'atelier reste ce qu'il a rendu, au centime. Et
        // l'addition se fait ICI, côté serveur — additionnée dans le
        // navigateur, ce serait un montant que la boutique n'a pas vérifié.
        // C'est exactement la règle que suit déjà le chemin de sous-traitance.
        $choixServices = $this->choixServices();
        $supplementCents = ServicesProduit::supplement($idProduct, $choixServices);

        // ⚠️ POURQUOI LE SUPPLÉMENT EST RÉPARTI SUR LA QUANTITÉ.
        //
        // Le chemin de sous-traitance met UN LOT dans le panier : son
        // supplément s'y applique une fois, naturellement. Ici le panier porte
        // la quantité réelle et PrestaShop multiplie le prix UNITAIRE par
        // celle-ci. Ajouter 1 800 centimes au prix unitaire facturerait le bon
        // à tirer autant de fois qu'il y a d'exemplaires.
        //
        // Le supplément est donc réparti, pour que le TOTAL facturé soit juste.
        // L'arrondi peut décaler le total d'un centime sur des quantités qui ne
        // divisent pas le supplément ; le récapitulatif montre les deux lignes
        // séparément, de sorte que le client rapproche son devis du prix affiché
        // sans avoir à deviner ce qui a été ajouté.
        $centimes += $quantite > 0 ? (int) round($supplementCents / $quantite) : $supplementCents;

        // `$variables` emporte le choix : il part dans la personnalisation lue
        // au panier, et de là dans la `long_description` de la ligne poussée à
        // l'ERP. Sans lui, un client paierait un BAT que l'atelier ne verrait
        // nulle part.
        $variables['services'] = $choixServices;

        // Le prix est là : la configuration peut maintenant être écrite, et ce
        // qui s'affichera au panier décrira bien ce qui vient d'être chiffré.
        $idCustomization = $this->ecrireConfiguration($panier, $idProduct, $champ, $variables, $ekoProductId);

        if ($idCustomization <= 0) {
            return $this->refus(
                'La configuration n\'a pas pu être retenue.',
                ReponseJson::PANNE
            );
        }

        (new PrixConfigure())->memoriser($idCustomization, $idProduct, $quantite, $centimes, $variables, $delai);

        $ht = $centimes / 100;
        $ttc = $this->avecTaxe($ht, $idProduct);
        $precision = ReglesBoutique::precision();

        // ⚠️ RESPECTER LE RÉGIME DU GROUPE, comme le fait déjà le chemin de
        // sous-traitance. Ce contrôleur appliquait la taxe SANS CONDITION : un
        // client d'un groupe professionnel, qui voit tout le reste de la
        // boutique en hors taxe, aurait lu du TTC ici et nulle part ailleurs.
        $horsTaxe = ReglesBoutique::afficheHorsTaxe();
        $unitaire = $horsTaxe ? $ht : $ttc;

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
            // Le régime, pour que l'écran écrive « HT » ou « TTC » et ne
            // laisse pas le visiteur le deviner.
            'hors_taxe' => $horsTaxe,
            // Au professionnel qui lit du HT, on montre AUSSI le prix public :
            // c'est ce que son propre client paiera, et il en a besoin pour
            // établir son devis.
            'public_texte' => $horsTaxe ? $this->enLettres(ReglesBoutique::montant($ttc, $precision)) : '',
            // « Livraison estimée vendredi 21 août » plutôt que « 5 jour(s) ».
            // Le calcul vit dans DateLivraison, partagé avec le panier : deux
            // règles de jours ouvrés annonceraient deux dates pour la même
            // commande.
            'date_texte' => $this->dateDeLivraison($delai),
            // Ce que le marchand a écrit au back-office pour CETTE fiche.
            // Le chemin de sous-traitance les transmet depuis toujours ; celui
            // d'atelier les laissait dans la base.
            'note_delai' => (string) ServicesProduit::reglage('delai_note', $idProduct),
            'mention_prix' => (string) ServicesProduit::reglage('mention_prix', $idProduct),
            'reassurances' => ServicesProduit::reassurances($idProduct),
            // Les prestations que le marchand ajoute par-dessus la fabrication.
            // Le catalogue sort à chaque chiffrage : l'écran d'atelier n'avait
            // aucun moyen de les connaître, alors que les réglages existaient
            // déjà en base et que la sous-traitance les servait depuis toujours.
            'services' => ServicesProduit::services($idProduct),
            // Ce qui a été ajouté, en toutes lettres, pour que le récapitulatif
            // puisse le montrer sur sa propre ligne.
            'dont_prestations' => $supplementCents > 0
                ? $this->enLettres(ReglesBoutique::montant(
                    $horsTaxe ? $supplementCents / 100 : $this->avecTaxe($supplementCents / 100, $idProduct),
                    $precision
                ))
                : '',
            // Les avertissements du moteur de prix. Ils ne sont PAS cosmétiques :
            // « découpé en lés à raccorder à la pose » et « laize inconnue, calcul
            // mené sur 1620 mm » changent ce que le client recevra. Les taire, c'est
            // vendre autre chose que ce qui sera produit — et le découvrir à la
            // livraison. Ils sortent donc au même titre que le prix.
            'warnings' => $this->avertissements($donnees),
        ];
    }

    /**
     * La date de livraison estimée, en toutes lettres.
     *
     * L'atelier rend un NOMBRE de jours ouvrés, là où la sous-traitance rend
     * un libellé « J+N » qu'il faut d'abord décoder. Le calcul, lui, est le
     * même et vit au même endroit — sans quoi la fiche et le panier
     * annonceraient deux dates pour la même commande.
     */
    private function dateDeLivraison(?int $jours): string
    {
        if ($jours === null || $jours <= 0) {
            return '';
        }

        return DateLivraison::dans(
            $jours,
            (string) ($this->context->language->locale ?? 'fr-FR')
        );
    }

    /**
     * Les avertissements du moteur, TRIÉS : ce qui regarde le client, et le reste.
     *
     * ⚠️ MESURÉ le 2026-08-18 contre l'API réelle, sur les trois produits
     * d'atelier existants. Le tableau `warnings` mélange DEUX natures que rien
     * ne distingue dans sa forme, sauf la catégorie entre crochets qui ouvre
     * chaque message :
     *
     *   [Lés] 2000 × 1500 mm dépasse laize de 1310 mm : la pièce sera livrée en
     *         2 lés de 1002,5 × 1500 mm, recouvrement de 5 mm compris à
     *         raccorder à la pose.
     *         → CONTRACTUEL. Le client doit le lire AVANT de commander : ce
     *           qu'il recevra n'est pas d'un seul tenant.
     *
     *   [Matière lamination] Condition d'inclusion invalide : Variable
     *         "lamination" is not valid around position 1 for expression
     *         `lamination == true`.
     *         → DÉFAUT DU MOTEUR. Présent sur CHAQUE calcul des trois produits,
     *           y compris un 800 × 600 sans la moindre particularité.
     *
     * Tout afficher mettrait de la syntaxe de formule sur une fiche produit.
     * Ne rien afficher tairait une information contractuelle. On trie donc.
     *
     * Le tri se fait par LISTE BLANCHE de catégories, jamais par liste noire de
     * motifs : une catégorie inconnue est par défaut INTERNE — journalisée, pas
     * affichée. Le jour où l'ERP en ajoute une destinée au client, il faut
     * l'inscrire ici sciemment. C'est le sens du geste : on nomme ce qui peut
     * s'afficher.
     *
     * @param  array<string, mixed>  $donnees
     * @return list<string>
     */
    private function avertissements(array $donnees): array
    {
        $client = [];
        $internes = [];

        foreach ((array) ($donnees['warnings'] ?? []) as $brut) {
            if (!is_scalar($brut)) {
                continue;
            }

            $texte = trim((string) $brut);

            if ($texte === '') {
                continue;
            }

            if (self::regardeLeClient($texte)) {
                // 300 caractères : au-delà, ce n'est plus lu avant de commander.
                $client[] = Tools::substr($texte, 0, self::LONGUEUR_AVERTISSEMENT);
            } else {
                $internes[] = $texte;
            }
        }

        // Un défaut du moteur ne se voit nulle part si personne ne l'écrit :
        // le client ne le lit pas, et le chiffrage a l'air normal. Il part donc
        // au journal, où une supervision peut le trouver.
        if ($internes !== []) {
            \PrestaShopLogger::addLog(
                'ekosyncimprimerie — avertissements internes du moteur de prix, non affichés : '
                . mb_substr(implode(' | ', $internes), 0, 600),
                2,
                null,
                'Cart',
                (int) ($this->context->cart->id ?? 0)
            );
        }

        return array_slice($client, 0, self::NOMBRE_AVERTISSEMENTS);
    }

    /**
     * Cet avertissement est-il destiné au client ?
     *
     * Il l'est s'il s'ouvre sur une catégorie de la liste blanche. Sans crochet
     * ouvrant, on ne sait pas ce qu'on lit — donc c'est interne.
     */
    private static function regardeLeClient(string $texte): bool
    {
        if (!preg_match('/^\[([^\]]{1,40})\]/u', $texte, $m)) {
            return self::debutDestineAuClient($texte);
        }

        return in_array(Tools::strtolower(trim($m[1])), self::CATEGORIES_CLIENT, true);
    }

    /**
     * Les messages destinés au client que l'ERP émet SANS catégorie.
     *
     * ⚠️ TEMPORAIRE, et c'est une entorse assumée à la règle du dessus.
     * L'ERP émet « Laize inconnue pour la matière principale : imposition
     * calculée sur 1620 mm par défaut. Le métrage et la chute annoncés sont
     * indicatifs… » sans crochets, alors que tous ses autres messages en
     * portent. La règle générale — pas de catégorie, donc interne — le ferait
     * taire, alors qu'il dit au client que le métrage annoncé n'est pas ferme.
     *
     * À RETIRER dès que l'ERP préfixe ce message : la catégorie doit être
     * portée par l'émetteur, pas devinée par le lecteur. C'est une dette, elle
     * est nommée.
     *
     * ponytail: rattrapage littéral, à supprimer quand [Laize] existe côté ERP.
     */
    private static function debutDestineAuClient(string $texte): bool
    {
        $minuscule = Tools::strtolower($texte);

        foreach (self::DEBUTS_CLIENT as $debut) {
            if (strncmp($minuscule, $debut, Tools::strlen($debut)) === 0) {
                return true;
            }
        }

        return false;
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
    private function ecrireConfiguration(Cart $panier, int $idProduct, int $champ, array $variables, int $ekoProductId = 0): int
    {
        // ⚠️ CETTE MÉTHODE AVAIT SA PROPRE COPIE DE LA REQUÊTE, et les deux
        // divergeaient : celle-ci ne filtrait PAS `is_deleted`. Un champ
        // supprimé au back-office restait donc élu ici, et la configuration
        // partait dans un champ que PrestaShop n'affiche plus nulle part.
        //
        // Deux écrivains pour une même donnée en donnent tôt ou tard deux
        // réponses. Il n'y en a plus qu'un, et il élit le champ par son NOM —
        // le tri par identifiant désignait « le plus ancien champ texte », ce
        // qui cessera d'être la configuration dès qu'un commentaire existera.
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
            $this->configurationLisible($variables, $ekoProductId, $idProduct),
            true
        );

        return is_numeric($id) ? (int) $id : 0;
    }

    /**
     * Les variables qu'un produit d'atelier DÉCLARE, indexées par clé.
     *
     * L'appel est mis en cache par le client HTTP du module : le second
     * chiffrage d'une même fiche ne le repaie pas.
     *
     * @return array<string, array<string, mixed>>
     */
    private function modeleVariables(int $ekoProductId): array
    {
        if ($ekoProductId <= 0) {
            return [];
        }

        /** @var Ekosyncimprimerie $module */
        $module = $this->module;
        $r = $module->client()->appeler('GET', '/api/v1/printing/products/' . $ekoProductId . '/variables');

        $modele = [];

        foreach (($r['ok'] ? ($r['donnees']['data'] ?? []) : []) as $v) {
            if (is_array($v) && isset($v['key'])) {
                $modele[(string) $v['key']] = $v;
            }
        }

        return $modele;
    }

    /**
     * Compléter la saisie du client par les défauts DÉCLARÉS du produit.
     *
     * ⚠️ MESURÉ le 2026-08-18. Le moteur de l'ERP construit son contexte
     * d'évaluation à partir des SEULES variables reçues — il ne lit jamais
     * celles que le produit déclare. Une clé absente fait donc lever la
     * condition qui la référence, et le bloc de rattrapage EXCLUT la ligne :
     *
     *     [Tâche] Condition d'inclusion invalide : Variable "lamination"
     *     is not valid around position 1 for expression `lamination == true`
     *
     * Sur les produits d'aujourd'hui l'exclusion tombe juste, parce que le
     * défaut déclaré vaut lui aussi `false` — mesuré : 35,87 € avec ou sans la
     * clé. Mais une variable au défaut VRAI serait silencieusement retirée du
     * prix, et le client paierait moins que ce qu'il reçoit. On n'attend pas
     * ce jour-là.
     *
     * On n'INVENTE rien : une variable sans défaut utilisable — une liste de
     * choix à `null` — reste absente. Poser une valeur à sa place reviendrait
     * à choisir une matière pour le client.
     *
     * @param  array<string, mixed>  $variables
     * @param  array<string, array<string, mixed>>  $modele
     * @return array<string, mixed>
     */
    private function completerVariables(array $variables, array $modele): array
    {
        foreach ($modele as $cle => $def) {
            if (array_key_exists($cle, $variables)) {
                continue;
            }

            $defaut = $def['default'] ?? null;

            if ($defaut !== null) {
                $variables[$cle] = $defaut;
            } elseif (($def['type'] ?? '') === 'boolean') {
                $variables[$cle] = false;
            }
        }

        // ⚠️ TYPER AVANT D'ENVOYER, d'après ce que le produit DÉCLARE.
        //
        // Ce qui arrive du navigateur est toujours une CHAÎNE : « 0 », « 1 »,
        // « 1500 ». Le moteur, lui, évalue des conditions comme
        // `lamination == true`. La chaîne « 0 » n'est pas `false`, et selon
        // l'évaluateur elle peut valoir vrai — le client paierait alors une
        // option qu'il a laissée décochée.
        //
        // Le modèle du produit dit le type de chaque clé : on s'en sert plutôt
        // que de deviner à la forme de la valeur.
        foreach ($variables as $cle => $valeur) {
            $type = $modele[$cle]['type'] ?? null;

            if ($type === 'boolean') {
                $variables[$cle] = filter_var($valeur, FILTER_VALIDATE_BOOLEAN);
            } elseif ($type === 'integer') {
                $variables[$cle] = (int) $valeur;
            }
        }

        return $variables;
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
    /**
     * Les prestations retenues, en toutes lettres.
     *
     * Le choix stocke le NOM de l'option (« BAT numérique »), pas son prix :
     * on relit le libellé du service depuis les réglages pour écrire
     * « BAT numérique : BAT numérique » plutôt qu'une clé technique. Une
     * option gratuite est écrite comme les autres — « Sans BAT » est une
     * décision du client, elle doit se lire sur le dossier d'atelier.
     *
     * @param  array<string,string>  $choix
     * @return list<string>
     */
    private function prestationsLisibles(array $choix, int $idProduct): array
    {
        if ($choix === [] || $idProduct <= 0) {
            return [];
        }

        $lignes = [];

        foreach (ServicesProduit::services($idProduct) as $service) {
            $voulu = (string) ($choix[$service['cle']] ?? '');

            if ($voulu === '') {
                continue;
            }

            // « BAT numérique : BAT numérique » : quand l'option porte le nom
            // du service, la répétition n'apprend rien et alourdit le dossier
            // que lit l'atelier. On n'écrit alors que le nom.
            $libelle = (string) $service['label'];

            $lignes[] = $voulu === $libelle ? $voulu : $libelle . ' : ' . $voulu;
        }

        return $lignes;
    }

    private function configurationLisible(array $variables, int $ekoProductId, int $idProduct = 0): string
    {
        if ($variables === []) {
            return '';
        }

        $modele = $this->modeleVariables($ekoProductId);

        $morceaux = [];

        foreach ($variables as $cle => $valeur) {
            // ⚠️ Les prestations sont un TABLEAU, pas une valeur de critère.
            // Sans ce branchement, le `(string) $valeur` plus bas écrivait
            // « Prestations : Array » au panier — et c'est ce texte qui part
            // en `long_description` sur la ligne poussée à l'atelier.
            if ($cle === 'services') {
                foreach ($this->prestationsLisibles((array) $valeur, $idProduct) as $ligne) {
                    $morceaux[] = $ligne;
                }

                continue;
            }

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

        // ⚠️ DEUX correctifs ici, 2026-08-13.
        //
        // 1. Un critère PAR LIGNE, comme le fait déjà le chemin catalogue.
        //    Le séparateur « · » produisait au panier un pavé de texte
        //    continu là où l'autre chemin rendait une liste propre : deux
        //    présentations pour la même donnée, selon le contrôleur qui
        //    l'avait écrite.
        //
        // 2. La limite annoncée à 255 était FAUSSE. Mesurée en base, la
        //    colonne est `varchar(1024)`. Une configuration de grand format
        //    dépasse allègrement 255 caractères : les derniers critères
        //    partaient à la poubelle sans un mot, et la commande arrivait
        //    en production amputée de sa fin.
        //
        // On coupe désormais sur une frontière de ligne : mieux vaut perdre
        // un critère entier — visible — qu'en couper un au milieu.
        $texte = implode("\n", $morceaux);

        if (mb_strlen($texte) <= self::LONGUEUR_CONFIGURATION) {
            return $texte;
        }

        $garde = [];
        $total = 0;

        foreach ($morceaux as $morceau) {
            $ajout = ($garde === [] ? 0 : 1) + mb_strlen($morceau);

            if ($total + $ajout > self::LONGUEUR_CONFIGURATION) {
                break;
            }

            $garde[] = $morceau;
            $total += $ajout;
        }

        return implode("\n", $garde);
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

    /**
     * Un refus — rendu au visiteur, ET dit à la supervision.
     *
     * Le statut vaut 422 par défaut : une demande comprise mais inexploitable.
     * Un appelant ne passe autre chose que lorsque l'échec n'appartient PAS au
     * visiteur — `ReponseJson::PANNE` quand la boutique a fauté,
     * `ReponseJson::AMONT` quand l'ERP n'a pas répondu.
     *
     * @return never
     */
    /**
     * Le choix de prestations soumis par l'écran.
     *
     * On ne retient QUE les clés que le module déclare : une clé inconnue
     * n'entre pas dans le calcul du supplément. Le montant, lui, n'est jamais
     * lu du navigateur — il est relu des réglages par `supplement()`.
     *
     * @return array<string,string>
     */
    private function choixServices(): array
    {
        $brut = Tools::getValue('services');

        if (!is_array($brut)) {
            return [];
        }

        $sortie = [];

        foreach (ServicesProduit::SERVICES as $cle) {
            if (isset($brut[$cle]) && is_scalar($brut[$cle])) {
                $sortie[$cle] = (string) $brut[$cle];
            }
        }

        return $sortie;
    }

    private function refus(string $message, int $statut = ReponseJson::REFUS)
    {
        ReponseJson::rendre(['ok' => false, 'message' => $message], $statut);
    }

}
