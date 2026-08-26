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
 * Crée des fiches boutique à partir du catalogue de sous-traitance.
 *
 *     php dev/injecter-printoclock.php <fichier.json>
 *     php dev/injecter-printoclock.php --retirer
 *
 * ─── POURQUOI UN FICHIER, ET PAS UN APPEL À L'ERP ──────────────────────────
 *
 * Sur un hébergement mutualisé, la ligne de commande n'a PAS le réseau sortant
 * du serveur web : l'API de l'ERP y répond « connection refused » en deux
 * millisecondes. Un script d'injection qui appellerait l'API ne pourrait donc
 * tourner que depuis une requête HTTP — ce qui suppose d'exposer une page
 * capable de créer des produits.
 *
 * Le fichier lève la contradiction : le catalogue est extrait là où l'API est
 * joignable, déposé ici, et lu sans réseau. Il porte exactement ce que rend
 * `GET /api/v1/printoclock/products` — donc rien qui ne soit déjà public pour
 * la boutique.
 *
 * ─── CE QUE CES FICHES SONT, ET NE SONT PAS ────────────────────────────────
 *
 * Elles portent un prix catalogue de ZÉRO et n'ont ni image ni description.
 * Ce ne sont pas des fiches de vente : ce sont des supports de configurateur.
 * Le prix vient de la grille du sous-traitant, et rien d'autre sur la fiche
 * n'a vocation à l'annoncer.
 *
 * Elles sont créées en visibilité « nulle part » : joignables par leur URL,
 * absentes du catalogue, de la recherche et du plan du site. Sur une boutique
 * en exploitation, c'est la seule façon de les éprouver sans les exposer.
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

const PREFIXE = 'POC-';

// Une fatale dans un script PrestaShop en ligne de commande peut ne RIEN
// écrire : ni corps, ni stderr, ni journal. Ce garde la nomme.
register_shutdown_function(static function (): void {
    $e = error_get_last();

    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        printf("\n[FATALE] %s\n         %s ligne %d\n", $e['message'], $e['file'], $e['line']);
    }
});

$db = Db::getInstance();
$argument = (string) ($argv[1] ?? '');
$idLang = (int) Configuration::get('PS_LANG_DEFAULT');

/** Les fiches déjà injectées, par référence. */
function dejaLa(): array
{
    $db = Db::getInstance();
    $sortie = [];

    foreach ($db->executeS(
        'SELECT id_product, reference FROM ' . _DB_PREFIX_ . 'product'
        . ' WHERE reference LIKE "' . pSQL(PREFIXE) . '%"'
    ) ?: [] as $r) {
        $sortie[(string) $r['reference']] = (int) $r['id_product'];
    }

    return $sortie;
}

// ─── Retrait ───────────────────────────────────────────────────────────────

if ($argument === '--retirer') {
    $fiches = dejaLa();

    if ($fiches === []) {
        exit("Aucune fiche de sous-traitance à retirer.\n");
    }

    foreach ($fiches as $reference => $id) {
        $db->execute('DELETE FROM ' . _DB_PREFIX_ . 'ekosync_produit WHERE id_product = ' . $id);
        $db->execute('DELETE FROM ' . _DB_PREFIX_ . 'ekosync_prix WHERE id_product = ' . $id);
        $db->execute('DELETE FROM ' . _DB_PREFIX_ . 'customization WHERE id_product = ' . $id);

        foreach ($db->executeS('SELECT id_customization_field FROM ' . _DB_PREFIX_ . 'customization_field WHERE id_product = ' . $id) ?: [] as $f) {
            (new CustomizationField((int) $f['id_customization_field']))->delete();
        }

        (new Product($id))->delete();
        printf("  retirée : %s (#%d)\n", $reference, $id);
    }

    printf("\nCatalogue : %d produit(s).\n", (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'product'));
    exit(0);
}

// ─── Lecture du catalogue ──────────────────────────────────────────────────

if ($argument === '' || !is_file($argument)) {
    exit(
        "Usage : php dev/injecter-printoclock.php <fichier.json>\n"
        . "        php dev/injecter-printoclock.php --retirer\n\n"
        . "Le fichier porte ce que rend GET /api/v1/printoclock/products.\n"
    );
}

$produits = json_decode((string) file_get_contents($argument), true);

if (!is_array($produits) || $produits === []) {
    exit("Le fichier ne contient aucun produit exploitable.\n");
}

// PAS de `LIMIT 1` dans un `getValue()` : `Db::getRow()` en ajoute un SANS
// CONDITION depuis PrestaShop 9, la requête échoue et rend `false` en silence.
$regleDeTaxe = (int) $db->getValue(
    'SELECT id_tax_rules_group FROM ' . _DB_PREFIX_ . 'tax_rules_group WHERE active = 1 ORDER BY id_tax_rules_group'
);

$existantes = dejaLa();
$liaison = new LiaisonProduit();
$crees = 0;
$repris = 0;

printf("%d produit(s) à injecter, règle de taxe #%d.\n\n", count($produits), $regleDeTaxe);

foreach ($produits as $p) {
    $code = trim((string) ($p['code'] ?? ''));
    $nom = trim((string) ($p['name'] ?? ''));

    if ($code === '' || $nom === '') {
        printf("  ignoré : une entrée sans code ni nom\n");
        continue;
    }

    $reference = PREFIXE . $code;
    $id = $existantes[$reference] ?? 0;

    $produit = $id > 0 ? new Product($id) : new Product();

    if ($id === 0) {
        $produit->reference = $reference;
        $produit->price = 0.0;
        $produit->id_category_default = (int) Configuration::get('PS_HOME_CATEGORY');
        $produit->active = true;
        $produit->available_for_order = true;
        $produit->show_price = true;

        // Personnalisable AVEC un champ texte : c'est lui qui porte l'identité
        // de la configuration, de la fiche jusqu'à la facture.
        $produit->customizable = 1;
        $produit->text_fields = 1;
    }

    // « nulle part » : joignable par son URL, absente du catalogue, de la
    // recherche et du plan du site. Et la règle de taxe se repose à chaque
    // fois — `new Product($id)` ne la recharge pas, un `update()` l'effacerait.
    $produit->visibility = 'none';
    $produit->indexed = false;
    $produit->id_tax_rules_group = $regleDeTaxe;

    $produit->name = [$idLang => mb_substr($nom, 0, 128)];
    $produit->link_rewrite = [$idLang => Tools::str2url($nom) ?: strtolower($code)];

    $etapes = is_array($p['steps'] ?? null) ? $p['steps'] : [];
    $libelles = array_map(static fn ($e): string => (string) ($e['label'] ?? $e['code'] ?? ''), $etapes);

    $produit->description_short = [$idLang => sprintf(
        '<p>Produit configurable. %d étape%s : %s. Le prix dépend de la quantité et du délai.</p>',
        count($etapes),
        count($etapes) > 1 ? 's' : '',
        htmlspecialchars(implode(', ', array_filter($libelles)))
    )];

    if ($id > 0) {
        $produit->update();
        ++$repris;
    } else {
        $produit->add();
        ++$crees;
        $id = (int) $produit->id;
    }

    if ((int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'customization_field WHERE id_product = ' . $id) === 0) {
        $champ = new CustomizationField();
        $champ->id_product = $id;
        $champ->type = Product::CUSTOMIZE_TEXTFIELD;
        $champ->required = false;
        $champ->name = [$idLang => 'Configuration'];
        $champ->add();
    }

    // Le rattachement est OBLIGATOIRE même pour une fiche invisible :
    // `ProductController` appelle `checkAccess()`, qui interroge les droits du
    // groupe SUR LA CATÉGORIE. Sans catégorie, l'URL directe répond 403.
    $produit->addToCategories([(int) Configuration::get('PS_HOME_CATEGORY')]);
    StockAvailable::setQuantity($id, 0, 999);

    $r = $liaison->lier($id, LiaisonProduit::SOURCE_PRINTOCLOCK, $code);

    printf(
        "  %-8s #%-4d %-30s %d étape(s), %d variantes  %s\n",
        $code,
        $id,
        mb_substr($nom, 0, 30),
        count($etapes),
        (int) ($p['variants_count'] ?? 0),
        $r['ok'] ? '' : '⚠ ' . $r['message']
    );
}

printf("\n%d créée(s), %d reprise(s). Catalogue : %d produit(s).\n",
    $crees, $repris, (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'product'));

$lien = new Link();

foreach (dejaLa() as $reference => $id) {
    printf("  %s\n", $lien->getProductLink($id, null, null, null, $idLang));
}

printf("\nPour rendre la boutique à son état d'origine : php dev/injecter-printoclock.php --retirer\n");
