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
 * Monte une fiche de démonstration liée à un vrai produit d'atelier.
 *
 *     php dev/demo-produit.php            # liste les produits d'atelier
 *     php dev/demo-produit.php <id>       # crée la fiche et la lie
 *     php dev/demo-produit.php --retirer  # supprime la fiche de démonstration
 *
 * À quoi ça sert : voir le configurateur tourner sur de vraies options et de
 * vrais prix, plutôt que sur un décor fabriqué. C'est le seul moyen d'éprouver
 * le maillon ERP, que les contrôles en ligne de commande ne joignent pas sur
 * un hébergement mutualisé — le réseau sortant n'existe que côté serveur web.
 *
 * ⚠️ La fiche créée ici est un VRAI produit du catalogue. Tant qu'elle existe,
 * `verifier-chaine.php` et `verifier-tva.php` refusent de tourner : ils exigent
 * un catalogue vide. `--retirer` rend la boutique à son état d'origine.
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

use Eko\SyncImprimerie\Configurateur\LiaisonProduit;

const REFERENCE = 'EKO-DEMO-CONFIGURATEUR';

// Une fatale dans un script PrestaShop en ligne de commande peut ne RIEN
// écrire : ni corps, ni stderr, ni journal. Le seul témoin est alors le code
// de sortie 255, qui ne dit pas où. Ce garde le dit.
register_shutdown_function(static function (): void {
    $e = error_get_last();

    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        printf("\n[FATALE] %s\n         %s ligne %d\n", $e['message'], $e['file'], $e['line']);
    }
});

$db = Db::getInstance();
$argument = (string) ($argv[1] ?? '');

/** La fiche de démonstration, si elle existe déjà. */
$existante = (int) $db->getValue(
    'SELECT id_product FROM ' . _DB_PREFIX_ . 'product WHERE reference = "' . pSQL(REFERENCE) . '"'
);

// ─── Retrait ───────────────────────────────────────────────────────────────

if ($argument === '--retirer') {
    if ($existante <= 0) {
        exit("Aucune fiche de démonstration à retirer.\n");
    }

    $db->execute('DELETE FROM ' . _DB_PREFIX_ . 'ekosync_produit WHERE id_product = ' . $existante);
    $db->execute(
        'DELETE p, d FROM ' . _DB_PREFIX_ . 'ekosync_prix p'
        . ' LEFT JOIN ' . _DB_PREFIX_ . 'customized_data d ON d.id_customization = p.id_customization'
        . ' WHERE p.id_product = ' . $existante
    );
    $db->execute('DELETE FROM ' . _DB_PREFIX_ . 'customization WHERE id_product = ' . $existante);

    foreach ($db->executeS('SELECT id_customization_field FROM ' . _DB_PREFIX_ . 'customization_field WHERE id_product = ' . $existante) ?: [] as $f) {
        (new CustomizationField((int) $f['id_customization_field']))->delete();
    }

    (new Product($existante))->delete();

    printf(
        "Fiche de démonstration retirée. Catalogue : %d produit(s).\n",
        (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'product')
    );

    exit(0);
}

// ─── Le module et sa liaison à l'ERP ───────────────────────────────────────

/** @var Ekosyncimprimerie|false $module */
$module = Module::getInstanceByName('ekosyncimprimerie');

if (!$module instanceof Ekosyncimprimerie) {
    exit("Le module n'est pas installé sur cette boutique.\n");
}

// L'ERP n'est joignable que depuis le serveur web sur bien des hébergements
// mutualisés : la ligne de commande n'y a pas le réseau sortant. Le script
// n'en fait donc PAS une condition — il s'en sert quand il peut, pour nommer
// la fiche et montrer ses options, et travaille sans quand il ne peut pas.
$catalogue = $module->client()->appeler('GET', '/api/v1/printing/products', ['per_page' => 50]);
$produits = $catalogue['ok'] ? ($catalogue['donnees']['data'] ?? []) : [];
$horsLigne = !$catalogue['ok'];

// ─── Liste ─────────────────────────────────────────────────────────────────

if ($argument === '') {
    if ($horsLigne) {
        printf("L'ERP est injoignable depuis ce shell : %s\n", $catalogue['erreur']);
        exit(
            "Impossible de lister le catalogue d'ici. Relancer depuis un contexte qui a le\n"
            . "réseau sortant, ou passer directement l'identifiant du produit d'atelier :\n"
            . "  php dev/demo-produit.php <id>\n"
        );
    }

    printf("%d produit(s) d'atelier chez l'ERP :\n\n", count($produits));

    foreach ($produits as $p) {
        printf("  #%-6d %s\n", (int) ($p['id'] ?? 0), (string) ($p['name'] ?? '(sans nom)'));
    }

    exit("\nRelancer avec l'identifiant choisi : php dev/demo-produit.php <id>\n");
}

$ekoProductId = (int) $argument;

if ($ekoProductId <= 0) {
    exit("Identifiant de produit d'atelier attendu.\n");
}

// ─── Création ──────────────────────────────────────────────────────────────

// Les variables du produit AVANT de créer quoi que ce soit : une fiche liée à
// un produit sans option afficherait un configurateur vide.
$vars = $module->client()->appeler('GET', '/api/v1/printing/products/' . $ekoProductId . '/variables');
$variables = $vars['ok'] ? ($vars['donnees']['data'] ?? []) : [];

if ($vars['ok'] && $variables === []) {
    // Là on SAIT qu'il n'y a pas d'options : refuser est juste.
    exit("Ce produit d'atelier n'a aucune option : le configurateur n'aurait rien à afficher.\n");
}

$nomEko = '';

foreach ($produits as $p) {
    if ((int) ($p['id'] ?? 0) === $ekoProductId) {
        $nomEko = (string) ($p['name'] ?? '');
        break;
    }
}

// PAS de `LIMIT 1` dans un `getValue()` : `Db::getRow()` en ajoute un
// SANS CONDITION depuis PrestaShop 9 (classes/db/Db.php:634), là où les
// versions précédentes vérifiaient d'abord s'il y en avait déjà un. Le SQL
// devient « LIMIT 1 LIMIT 1 », la requête échoue, et `getValue()` rend
// `false` — donc zéro après conversion, sans une ligne d'erreur.
// La règle de taxe se pose à CHAQUE fois, création comme reprise.
//
// `id_tax_rules_group` n'est pas une colonne de `product` : PrestaShop la
// range dans `product_tax_rules_group_shop`, et `new Product($id)` ne la
// recharge PAS. Elle vaut donc zéro sur un objet relu, et `update()` efface
// alors l'association sans rien dire — la fiche se retrouve hors taxe.
$regleDeTaxe = (int) $db->getValue(
    'SELECT id_tax_rules_group FROM ' . _DB_PREFIX_ . 'tax_rules_group WHERE active = 1 ORDER BY id_tax_rules_group'
);

if ($existante > 0) {
    $produit = new Product($existante);
    $produit->visibility = 'none';
    $produit->indexed = false;
    $produit->id_tax_rules_group = $regleDeTaxe;
    printf("Reprise de la fiche existante #%d.\n", $existante);
} else {
    $produit = new Product();
    $produit->reference = REFERENCE;
    $produit->price = 0.0;
    $produit->id_category_default = (int) Configuration::get('PS_HOME_CATEGORY');
    $produit->id_tax_rules_group = $regleDeTaxe;
    $produit->active = true;

    // « nulle part » : la fiche répond par son URL directe, mais n'apparaît ni
    // au catalogue, ni dans la recherche, ni au plan du site. C'est ce qu'il
    // faut pour montrer le configurateur sur une boutique en exploitation sans
    // exposer un produit de démonstration aux visiteurs ni aux moteurs.
    //
    // `active = false` ne conviendrait pas : PrestaShop répond alors 404 à tout
    // le monde sauf à un employé muni d'un jeton de prévisualisation, et le
    // configurateur ne serait pas éprouvé dans les conditions d'un vrai client.
    $produit->visibility = 'none';
    $produit->indexed = false;
    $produit->available_for_order = true;
    $produit->show_price = true;

    // Personnalisable AVEC un champ texte : c'est ce champ qui porte l'identité
    // de la configuration, de la fiche jusqu'à la facture.
    $produit->customizable = 1;
    $produit->text_fields = 1;
}

$idLang = (int) Configuration::get('PS_LANG_DEFAULT');
$titre = $nomEko !== '' ? $nomEko : 'Produit d\'atelier #' . $ekoProductId;

$produit->name = [$idLang => mb_substr($titre, 0, 128)];
// `Tools::str2url()` et non `Tools::link_rewrite()` : cette dernière a été
// retirée de PrestaShop 9. Elle n'existe plus, et l'appeler tue le script
// sans écrire une ligne — ni stderr, ni journal.
$produit->link_rewrite = [$idLang => Tools::str2url($titre) ?: 'demo-configurateur'];
$produit->description_short = [$idLang => '<p>Le prix de cette fiche est calculé par l\'atelier E-KO. Choisissez vos options ci-dessous.</p>'];

if ($existante > 0) {
    $produit->update();
} else {
    $produit->add();
}

$idProduct = (int) $produit->id;

if ($idProduct <= 0) {
    exit("PrestaShop a refusé la création de la fiche.\n");
}

$dejaChamp = (int) $db->getValue(
    'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'customization_field WHERE id_product = ' . $idProduct
);

if ($dejaChamp === 0) {
    $champ = new CustomizationField();
    $champ->id_product = $idProduct;
    $champ->type = Product::CUSTOMIZE_TEXTFIELD;
    $champ->required = false;
    $champ->name = [$idLang => 'Configuration'];
    $champ->add();
}

// Le rattachement à une catégorie est OBLIGATOIRE, même pour une fiche qu'on
// veut invisible : `ProductController` appelle `Product::checkAccess()`, qui
// interroge les droits du groupe SUR LA CATÉGORIE. Sans catégorie, aucun
// groupe n'a accès et la page répond 403 — y compris par son URL directe.
//
// C'est `visibility = 'none'` qui masque, pas l'absence de catégorie : la
// fiche reste hors du catalogue, de la recherche et du plan du site tout en
// étant rattachée.
$produit->addToCategories([(int) Configuration::get('PS_HOME_CATEGORY')]);
StockAvailable::setQuantity($idProduct, 0, 999);

// Vérifier que la règle de taxe a bien pris : une fiche muette sur la TVA
// afficherait un TTC égal au HT, ce qui a tout l'air d'un prix juste.
$regleReelle = (int) Product::getIdTaxRulesGroupByIdProduct($idProduct);

if ($regleReelle !== $regleDeTaxe) {
    printf(
        "⚠ La règle de taxe #%d n'a pas été enregistrée (la fiche en porte #%d).\n",
        $regleDeTaxe,
        $regleReelle
    );
}

$liaison = (new LiaisonProduit())->lier($idProduct, LiaisonProduit::SOURCE_ATELIER, (string) $ekoProductId);

printf("\n%s\n", $liaison['message']);
printf("Fiche boutique #%d ← produit d'atelier #%d (%s)\n", $idProduct, $ekoProductId, $titre);
if ($vars['ok']) {
    printf("%d option(s) rendues par l'ERP :\n", count($variables));
} else {
    // Ne PAS taire ce qui n'a pas été vérifié : la fiche est posée, mais on
    // ignore d'ici si l'ERP acceptera de la chiffrer. C'est la page produit
    // qui le dira, depuis le serveur web.
    printf(
        "Options NON vérifiées : l'ERP est injoignable depuis ce shell (%s).\n"
        . "La fiche est posée ; c'est en l'ouvrant dans un navigateur qu'on saura\n"
        . "si le configurateur obtient bien ses options et son prix.\n",
        $vars['erreur']
    );
}

foreach ($variables as $v) {
    printf(
        "  %-22s %-10s %s\n",
        (string) ($v['key'] ?? '?'),
        (string) ($v['type'] ?? '?'),
        isset($v['options']) && is_array($v['options'])
            ? count($v['options']) . ' choix'
            : 'saisie libre'
    );
}

printf(
    "\nÀ voir sur : %s\n",
    (new Link())->getProductLink($idProduct, null, null, null, $idLang)
);
printf("Pour rendre la boutique à son état d'origine : php dev/demo-produit.php --retirer\n");
