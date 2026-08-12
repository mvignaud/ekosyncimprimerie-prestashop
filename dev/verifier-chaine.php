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
 * Éprouve la chaîne complète du configurateur sur une boutique réelle.
 *
 *     php dev/verifier-chaine.php <id_produit_atelier>
 *
 * Pourquoi un script et pas une suite de tests : chaque maillon touche la base,
 * le panier, la session ou l'ERP. Les simuler reviendrait à tester des doublures
 * — et c'est exactement ce qui a laissé passer les trois défauts trouvés le jour
 * de l'écriture : un visiteur sans panier, un contrôleur mort dans
 * `initContent()`, et une adresse `null` refusée par le calculateur de taxe.
 * Aucun n'était visible sans une vraie boutique.
 *
 * Le script CRÉE son décor, le mesure, puis le SUPPRIME — y compris en cas
 * d'échec. Il ne touche à rien d'autre : il refuse de tourner sur une boutique
 * qui a déjà des produits.
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

use Eko\SyncImprimerie\Configurateur\LiaisonProduit;
use Eko\SyncImprimerie\Configurateur\PrixConfigure;

$ekoProductId = (int) ($argv[1] ?? 0);

if ($ekoProductId <= 0) {
    exit("Usage : php dev/verifier-chaine.php <id_produit_atelier>\n");
}

$db = Db::getInstance();

// ─── Garde-fou ─────────────────────────────────────────────────────────────
// Un script qui crée et supprime des produits n'a rien à faire sur une
// boutique en exploitation. On refuse plutôt que d'espérer.
$existants = (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'product');

if ($existants > 0) {
    exit(sprintf(
        "Refus : cette boutique a déjà %d produit(s).\n"
        . "Ce script crée et supprime des produits ; il ne doit tourner que sur\n"
        . "une boutique de recette au catalogue vide.\n",
        $existants
    ));
}

$echecs = 0;
$idProduct = $idCart = $idCustomization = 0;

/** Affiche un contrôle et compte les échecs. */
function verifier(string $quoi, bool $ok, string $detail = ''): void
{
    global $echecs;
    $echecs += $ok ? 0 : 1;
    printf("  %-6s %s%s\n", $ok ? '[ ok ]' : '[ÉCHEC]', $quoi, $detail === '' ? '' : ' — ' . $detail);
}

try {
    echo "1. Décor\n";

    $p = new Product();
    $p->name = [1 => 'Contrôle de chaîne EKO Sync'];
    $p->link_rewrite = [1 => 'controle-de-chaine-eko-sync'];
    $p->price = 19.99;
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

    verifier('fiche jetable créée', $idProduct > 0, 'prix catalogue 19,99 €');

    echo "\n2. Liaison vers l'atelier\n";

    $liaison = new LiaisonProduit();
    $r = $liaison->lier($idProduct, LiaisonProduit::SOURCE_ATELIER, (string) $ekoProductId);
    verifier('liaison posée', $r['ok'], $r['message']);
    verifier('liaison relue', $liaison->produitAtelier($idProduct) === $ekoProductId);

    $doublon = $liaison->lier($idProduct + 999999, LiaisonProduit::SOURCE_ATELIER, (string) $ekoProductId);
    verifier('un second produit sur le même atelier est refusé', $doublon['ok'] === false);

    echo "\n3. Chiffrage par l'ERP\n";

    $module = Module::getInstanceByName('ekosyncimprimerie');
    verifier('module chargé', $module instanceof Ekosyncimprimerie);

    $reponse = $module->client()->appeler('POST', '/api/v1/printing/pricing/calculate', [
        'product_id' => $ekoProductId,
        'quantity' => 4,
        'variables' => [],
    ]);

    if ($reponse['ok']) {
        verifier('l\'ERP répond', true, 'HTTP ' . $reponse['code']);

        $centimes = (int) ($reponse['donnees']['data']['unit_price_ht_cents'] ?? 0);
        verifier('un prix unitaire est rendu', $centimes > 0, $centimes . ' centimes HT');
    } else {
        // Distinguer « la chaîne est cassée » de « ce shell ne joint pas
        // l'ERP » : sur certains hébergements mutualisés, la ligne de commande
        // n'a pas le réseau sortant du serveur web. Ce n'est pas un défaut du
        // module, et le taire ferait chercher au mauvais endroit.
        $reseau = true;
        $centimes = 4200;

        printf(
            "  [note ] l'ERP est injoignable depuis ce shell — %s\n"
            . "         Le maillon ERP n'est donc PAS vérifié ici ; le reste de la\n"
            . "         chaîne l'est, avec un prix de référence de 42,00 € HT.\n"
            . "         Sur un hébergement mutualisé, essayer depuis le serveur web.\n",
            $reponse['erreur']
        );
    }

    echo "\n4. Mémorisation\n";

    $panier = new Cart();
    $panier->id_lang = (int) Configuration::get('PS_LANG_DEFAULT');
    $panier->id_currency = (int) Configuration::get('PS_CURRENCY_DEFAULT');
    $panier->id_shop = (int) Context::getContext()->shop->id;
    $panier->add();
    $idCart = (int) $panier->id;

    $idCustomization = (int) $panier->addTextFieldToProduct(
        $idProduct,
        (int) $champ->id,
        Product::CUSTOMIZE_TEXTFIELD,
        '{"controle":true}',
        true
    );
    verifier('configuration identifiée', $idCustomization > 0, '#' . $idCustomization);

    $prix = new PrixConfigure();
    $prix->memoriser($idCustomization, $idProduct, 4, $centimes, ['controle' => true], 5);
    verifier('prix mémorisé pour la quantité 4', $prix->lire($idCustomization, 4) === $centimes);
    verifier(
        'aucun prix pour une quantité non chiffrée',
        $prix->lire($idCustomization, 7) === null,
        'la clé est le COUPLE configuration + quantité'
    );

    echo "\n5. Le prix s'impose dans PrestaShop\n";

    $sp = null;
    $rendu = (float) Product::getPriceStatic(
        $idProduct, false, null, 6, null, false, true, 4,
        false, null, $idCart, null, $sp, true, true, null, true, $idCustomization
    );
    verifier(
        'la quantité chiffrée rend le prix de l\'ERP',
        abs($rendu - $centimes / 100) < 0.005,
        sprintf('%.2f € attendu, %.2f € rendu', $centimes / 100, $rendu)
    );

    $sp2 = null;
    $natif = (float) Product::getPriceStatic(
        $idProduct, false, null, 6, null, false, true, 7,
        false, null, $idCart, null, $sp2, true, true, null, true, $idCustomization
    );
    verifier(
        'une quantité non chiffrée laisse la main à PrestaShop',
        abs($natif - 19.99) < 0.01,
        sprintf('%.2f € — aucun prix inventé', $natif)
    );
} catch (Throwable $e) {
    $echecs++;
    printf("\n  [ÉCHEC] exception : %s\n", $e->getMessage());
} finally {
    echo "\n6. Ménage\n";

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

    if ($idCart > 0) {
        (new Cart($idCart))->delete();
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

    if ($echecs === 0) {
        printf("\n%s\n", empty($reseau)
            ? '✓ La chaîne tient de bout en bout, ERP compris.'
            : '✓ La chaîne PrestaShop tient. Le maillon ERP n\'a PAS été éprouvé (voir la note).');
    } else {
        printf("\n✗ %d contrôle(s) en échec.\n", $echecs);
    }

    exit($echecs === 0 ? 0 : 1);
}
