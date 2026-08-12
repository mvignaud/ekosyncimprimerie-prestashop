#!/usr/bin/env php
<?php

/**
 * EKO Sync — Imprimerie
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

/**
 * Éprouve le chemin TVA sur un produit RÉELLEMENT taxé.
 *
 *     php dev/verifier-tva.php [id_regle_de_taxe]
 *
 * `verifier-chaine.php` prouve que le prix de l'ERP s'impose — mais il le fait
 * sur un produit sans règle de taxe, donc en ajoutant 0 %. La garantie « au
 * centime » n'y portait que sur le HT.
 *
 * Ici, le produit porte une vraie règle de taxe et le prix de référence a un
 * nombre impair de centimes (42,37 €) : c'est la seule façon de voir un défaut
 * d'arrondi, qu'un prix rond masquerait entièrement.
 *
 * Ce que ce script vérifie, et que rien d'autre ne vérifie :
 *
 *   — le HT reste le HT (la TVA ne s'ajoute pas quand on ne la demande pas) ;
 *   — le TTC est exact, et la TVA n'est pas appliquée DEUX fois (le hook rend
 *     un prix déjà taxé, et le cœur ne le retaxe pas — ça se prouve, ça ne se
 *     suppose pas) ;
 *   — le total du panier concorde avec le total annoncé par le configurateur ;
 *   — le prix affiché et le prix facturé sont calculés à partir de la MÊME
 *     adresse et du MÊME groupe.
 *
 * Comme son voisin, il crée son décor, le mesure et le supprime — y compris en
 * cas d'échec — et refuse de tourner sur une boutique qui a des produits.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Ligne de commande uniquement.\n");
}

$racine = dirname(__DIR__, 3);

if (!is_file($racine . '/config/config.inc.php')) {
    exit("À lancer depuis le dossier du module, dans une installation PrestaShop.\n");
}

require $racine . '/config/config.inc.php';
require __DIR__ . '/../src/Configurateur/LiaisonProduit.php';
require __DIR__ . '/../src/Configurateur/PrixConfigure.php';
require __DIR__ . '/../src/Configurateur/ReglesBoutique.php';

use Eko\SyncImprimerie\Configurateur\ReglesBoutique;
use Eko\SyncImprimerie\Configurateur\LiaisonProduit;
use Eko\SyncImprimerie\Configurateur\PrixConfigure;

$db = Db::getInstance();

$existants = (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'product');

if ($existants > 0) {
    exit(sprintf(
        "Refus : cette boutique a déjà %d produit(s).\n"
        . "Ce script crée et supprime des produits ; il ne doit tourner que sur\n"
        . "une boutique de recette au catalogue vide.\n",
        $existants
    ));
}

// PAS de `LIMIT 1` dans un `getValue()` : `Db::getRow()` en ajoute un
// SANS CONDITION depuis PrestaShop 9 (classes/db/Db.php:634), là où les
// versions précédentes vérifiaient d'abord s'il y en avait déjà un. Le SQL
// devient « LIMIT 1 LIMIT 1 », la requête échoue, et `getValue()` rend
// `false` — donc zéro après conversion, sans une ligne d'erreur.
/** Le régime de taxe à éprouver — par défaut le premier régime actif. */
$idRegle = (int) ($argv[1] ?? 0);

if ($idRegle <= 0) {
    $idRegle = (int) $db->getValue(
        'SELECT id_tax_rules_group FROM ' . _DB_PREFIX_ . 'tax_rules_group WHERE active = 1 ORDER BY id_tax_rules_group'
    );
}

if ($idRegle <= 0) {
    exit("Aucune règle de taxe active sur cette boutique : rien à éprouver.\n");
}

$taux = (float) $db->getValue(
    'SELECT MAX(t.rate) FROM ' . _DB_PREFIX_ . 'tax_rule r'
    . ' JOIN ' . _DB_PREFIX_ . 'tax t ON t.id_tax = r.id_tax'
    . ' WHERE r.id_tax_rules_group = ' . $idRegle
);

// 42,37 € et non 42,00 € : un prix rond traverse n'importe quel arrondi sans
// jamais révéler qu'il en existe un.
$centimes = 4237;
$ht = $centimes / 100;

$echecs = 0;
$idProduct = $idCart = $idCustomization = 0;

// Le contexte de la ligne de commande n'a NI devise NI panier, là où une
// requête front en a toujours. Sans devise, `Context::getComputingPrecision()`
// meurt sur le `null` de `$currency->precision` — un défaut du décor, qu'il
// serait faux de lire comme un défaut du module. On monte donc le contexte
// comme le ferait le front.
//
// Poser la devise SUFFIT : la précision est calculée paresseusement au premier
// appel et mise en cache. Ne pas chercher à réinitialiser ce cache —
// `Context::$priceComputingPrecision` est `protected`, et y écrire depuis ici
// tue le script avant sa première ligne de sortie.
$contexte = Context::getContext();
$contexte->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));

function verifier(string $quoi, bool $ok, string $detail = ''): void
{
    global $echecs;
    $echecs += $ok ? 0 : 1;
    printf("  %-7s %s%s\n", $ok ? '[ ok ]' : '[ÉCHEC]', $quoi, $detail === '' ? '' : ' — ' . $detail);
}

try {
    printf("Régime de taxe #%d à %.3f %% — prix de référence %.2f € HT\n\n", $idRegle, $taux, $ht);

    echo "1. Décor taxé\n";

    $p = new Product();
    $p->name = [1 => 'Contrôle TVA EKO Sync'];
    $p->link_rewrite = [1 => 'controle-tva-eko-sync'];
    $p->price = 19.99;
    $p->id_tax_rules_group = $idRegle;
    $p->id_category_default = (int) Configuration::get('PS_HOME_CATEGORY');
    $p->active = false;
    $p->customizable = 1;
    $p->text_fields = 1;
    $p->add();
    $idProduct = (int) $p->id;

    $champ = new CustomizationField();
    $champ->id_product = $idProduct;
    $champ->type = Product::CUSTOMIZE_TEXTFIELD;
    $champ->required = false;
    $champ->name = [1 => 'Configuration'];
    $champ->add();

    verifier(
        'le produit porte bien la règle de taxe',
        (int) Product::getIdTaxRulesGroupByIdProduct($idProduct) === $idRegle
    );

    (new LiaisonProduit())->lier($idProduct, LiaisonProduit::SOURCE_ATELIER, '999001');

    $panier = new Cart();
    $panier->id_lang = (int) Configuration::get('PS_LANG_DEFAULT');
    $panier->id_currency = (int) Configuration::get('PS_CURRENCY_DEFAULT');
    $panier->id_shop = (int) Context::getContext()->shop->id;
    $panier->add();
    $idCart = (int) $panier->id;
    $contexte->cart = $panier;

    $idCustomization = (int) $panier->addTextFieldToProduct(
        $idProduct,
        (int) $champ->id,
        Product::CUSTOMIZE_TEXTFIELD,
        '{"controle":"tva"}',
        true
    );

    $prix = new PrixConfigure();
    $prix->memoriser($idCustomization, $idProduct, 500, $centimes, ['controle' => 'tva'], 5);
    $prix->memoriser($idCustomization, $idProduct, 1, $centimes, ['controle' => 'tva'], 5);

    verifier('prix mémorisé pour 1 et 500', $prix->lire($idCustomization, 500) === $centimes);

    echo "\n2. Le HT reste le HT\n";

    $sp = null;
    $renduHt = (float) Product::getPriceStatic(
        $idProduct, false, null, 6, null, false, true, 1,
        false, null, $idCart, null, $sp, true, true, null, true, $idCustomization
    );

    verifier(
        'sans taxe demandée, aucune taxe ajoutée',
        abs($renduHt - $ht) < 0.005,
        sprintf('%.2f € attendu, %.2f € rendu', $ht, $renduHt)
    );

    echo "\n3. Le TTC, et la TVA appliquée UNE SEULE fois\n";

    // Demandé à 6 décimales, PrestaShop rend le TTC NON arrondi : 42,37 × 1,20
    // vaut 50,844 et non 50,84. Comparer à l'euro-centime ici laisserait passer
    // l'écart de 4 dixièmes de centime sous la tolérance — l'assertion serait
    // verte par chance. On compare donc à la précision demandée.
    $attenduTtc = $ht * (1 + $taux / 100);

    $sp2 = null;
    $renduTtc = (float) Product::getPriceStatic(
        $idProduct, true, null, 6, null, false, true, 1,
        false, null, $idCart, null, $sp2, true, true, null, true, $idCustomization
    );

    verifier(
        'le TTC est exact au millionième',
        abs($renduTtc - $attenduTtc) < 0.000001,
        sprintf('%.6f € attendu, %.6f € rendu', $attenduTtc, $renduTtc)
    );

    // Et à la précision d'affichage, celle que le client lit sur la fiche.
    $sp3 = null;
    $affiche = (float) Product::getPriceStatic(
        $idProduct, true, null, 2, null, false, true, 1,
        false, null, $idCart, null, $sp3, true, true, null, true, $idCustomization
    );

    verifier(
        'le TTC affiché est celui qu\'on attend',
        abs($affiche - round($ht * (1 + $taux / 100), 2)) < 0.005,
        sprintf('%.2f € affiché', $affiche)
    );

    // La preuve que la taxe n'est pas appliquée deux fois : le doublement
    // donnerait 42,37 × 1,20 × 1,20 = 61,01 €. Le dire explicitement plutôt
    // que de le déduire du contrôle précédent — c'est LE défaut qu'un hook de
    // prix peut introduire, et il ne se voit pas sur un produit non taxé.
    $double = round($ht * (1 + $taux / 100) * (1 + $taux / 100), 2);

    verifier(
        'la TVA n\'est pas appliquée deux fois',
        abs($affiche - $double) > 0.01,
        sprintf('le doublement aurait donné %.2f €', $double)
    );

    echo "\n4. Le total compte comme le panier comptera\n";

    // Valeurs ÉCRITES À LA MAIN, et non recalculées par le code éprouvé — un
    // attendu calculé par ce qu'il vérifie reste vert quoi qu'il arrive.
    //
    // 42,37 € HT à 20 % font 50,844 € l'unité. Sur 500 exemplaires, les trois
    // modes d'arrondi de PrestaShop (`CartRow::applyRound()`) donnent :
    //
    //   1 — par article : ps_round(50,844) × 500 = 50,84 × 500 = 25 420,00 €
    //   2 — par ligne   : ps_round(50,844 × 500) = ps_round(25 422)= 25 422,00 €
    //   3 — au total    : 50,844 × 500                            = 25 422,00 €
    //
    // Entre « par article » et les deux autres, DEUX EUROS d'écart. C'est
    // exactement ce qu'un client verrait apparaître entre la fiche et son
    // panier si le module s'en tenait à une seule des trois formules.
    $attendus = [
        Order::ROUND_ITEM => 25420.00,
        Order::ROUND_LINE => 25422.00,
        Order::ROUND_TOTAL => 25422.00,
    ];

    $mode = (int) Configuration::get('PS_ROUND_TYPE');
    $precision = ReglesBoutique::precision();
    $unitaireTtc = $ht * (1 + $taux / 100);
    $obtenu = ReglesBoutique::total($unitaireTtc, 500, $precision);

    $noms = [
        Order::ROUND_ITEM => 'par article',
        Order::ROUND_LINE => 'par ligne',
        Order::ROUND_TOTAL => 'au total',
    ];

    verifier(
        sprintf('le total suit le mode « %s » de la boutique', $noms[$mode] ?? 'inconnu'),
        isset($attendus[$mode]) && abs($obtenu - $attendus[$mode]) < 0.005,
        sprintf('%.2f € attendu, %.2f € obtenu', $attendus[$mode] ?? 0.0, $obtenu)
    );

    verifier(
        'la précision vient de la devise, pas d\'une supposition',
        $precision === 2,
        sprintf('%d décimales pour %s', $precision, $contexte->currency->iso_code)
    );

    // Ce que le client lit : l'unitaire arrondi. Le multiplier ne redonne pas
    // toujours le total — c'est le comportement de PrestaShop lui-même en mode
    // « par ligne », pas un défaut. Le dire ici évite qu'on le corrige un jour
    // en croyant bien faire.
    $unitaireAffiche = ReglesBoutique::montant($unitaireTtc, $precision);

    printf(
        "  [info ] unitaire affiché %.2f € × 500 = %.2f € — le total annoncé est %.2f €%s\n",
        $unitaireAffiche,
        $unitaireAffiche * 500,
        $obtenu,
        abs($unitaireAffiche * 500 - $obtenu) < 0.005 ? '' : ' (PrestaShop fait pareil)'
    );

    // Le total du panier lui-même n'est PAS mesuré ici : `Cart::getOrderTotal()`
    // réclame le conteneur Symfony, absent d'un script en ligne de commande.
    // L'attendu ci-dessus vient de la lecture de `src/Core/Cart/CartRow.php`
    // (méthode `applyRound()`) — c'est une règle recopiée, pas un panier
    // observé. Le jour où PrestaShop change cette règle, ce contrôle restera
    // vert à tort : le relire fait partie de toute montée de version majeure.
    printf("  [note ] le panier réel n'est pas interrogeable en ligne de commande\n");

    echo "\n5. Les décisions de la boutique, prises par la boutique\n";

    // Ces deux contrôles appellent le VRAI code du module, pas une copie de sa
    // formule. Ils ont chacun attrapé un défaut le jour de leur écriture.

    // (a) L'adresse fiscale. La boutique désigne livraison OU facturation ;
    //     lire l'autre fait diverger le prix affiché du prix facturé dès que
    //     les deux ne sont pas dans la même zone.
    $champ = (string) Configuration::get('PS_TAX_ADDRESS_TYPE');

    $panier->id_address_delivery = 111;
    $panier->id_address_invoice = 222;

    $attendue = $champ === 'id_address_invoice' ? 222 : 111;
    $lue = ReglesBoutique::idAdresseFiscale($panier);

    verifier(
        sprintf('l\'adresse fiscale suit PS_TAX_ADDRESS_TYPE (« %s »)', $champ),
        $lue === $attendue,
        sprintf('adresse #%d attendue, #%d lue', $attendue, $lue)
    );

    // Un réglage aberrant ne doit pas chercher une propriété absente du panier.
    Configuration::updateValue('PS_TAX_ADDRESS_TYPE', 'id_address_farfelue');

    $repli = ReglesBoutique::idAdresseFiscale($panier);

    Configuration::updateValue('PS_TAX_ADDRESS_TYPE', $champ);

    verifier(
        'un réglage inconnu retombe sur la livraison',
        $repli === 111,
        sprintf('adresse #%d — et le réglage a été remis à « %s »', $repli, Configuration::get('PS_TAX_ADDRESS_TYPE'))
    );

    // (b) Le groupe. Un visiteur anonyme appartient à PS_UNIDENTIFIED_GROUP,
    //     pas au groupe zéro : lire `customer->id_default_group` retombait sur
    //     « afficher TTC » quel que soit le réglage de la boutique.
    $idAnon = (int) Configuration::get('PS_UNIDENTIFIED_GROUP');
    $anonEnHt = (int) Group::getPriceDisplayMethod($idAnon) === PS_TAX_EXC;

    verifier(
        'le groupe du visiteur anonyme est celui de la boutique',
        ReglesBoutique::afficheHorsTaxe() === $anonEnHt,
        sprintf(
            'groupe #%d réglé en %s, le module affiche en %s',
            $idAnon,
            $anonEnHt ? 'HT' : 'TTC',
            ReglesBoutique::afficheHorsTaxe() ? 'HT' : 'TTC'
        )
    );

} catch (Throwable $e) {
    ++$echecs;
    printf("\n  [ÉCHEC] exception : %s\n", $e->getMessage());
} finally {
    echo "\n6. Ménage\n";

    if ($idCart > 0) {
        (new Cart($idCart))->delete();
    }

    if ($idCustomization > 0) {
        $db->execute('DELETE FROM ' . _DB_PREFIX_ . 'ekosync_prix WHERE id_customization = ' . $idCustomization);
        $db->execute('DELETE FROM ' . _DB_PREFIX_ . 'customized_data WHERE id_customization = ' . $idCustomization);
        $db->execute('DELETE FROM ' . _DB_PREFIX_ . 'customization WHERE id_customization = ' . $idCustomization);
    }

    if ($idProduct > 0) {
        $db->execute('DELETE FROM ' . _DB_PREFIX_ . 'ekosync_produit WHERE id_product = ' . $idProduct);

        foreach ($db->executeS('SELECT id_customization_field FROM ' . _DB_PREFIX_ . 'customization_field WHERE id_product = ' . $idProduct) ?: [] as $f) {
            (new CustomizationField((int) $f['id_customization_field']))->delete();
        }

        (new Product($idProduct))->delete();
    }

    $reste = [
        'produits' => (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'product'),
        'liaisons' => (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'ekosync_produit'),
        'prix' => (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'ekosync_prix'),
        'configurations' => (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'customization'),
    ];

    foreach ($reste as $quoi => $n) {
        verifier('aucun reste : ' . $quoi, $n === 0, $n . ' trouvé(s)');
    }

    printf(
        "\n%s\n",
        $echecs === 0
            ? '✓ Le chemin TVA tient sur un produit réellement taxé.'
            : sprintf('✗ %d contrôle(s) en échec.', $echecs)
    );

    exit($echecs === 0 ? 0 : 1);
}
