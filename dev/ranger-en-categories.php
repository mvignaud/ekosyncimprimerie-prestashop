<?php

/**
 * Range les fiches de sous-traitance dans des catégories PrestaShop.
 *
 *     php ranger-en-categories.php <familles.json>
 *
 * ─── POURQUOI LA FAMILLE, ET PAS L'ARBORESCENCE ────────────────────────────
 *
 * L'ERP prévoit une table `printoclock_categories` avec `parent_id`, `depth` et
 * `path` : une VRAIE arborescence, celle du fournisseur. Elle est vide à ce
 * jour, et le rattachement des produits l'est aussi.
 *
 * La `family` — « Imprimerie », « PLV & Stands » — est en revanche renseignée
 * pour les 84 produits. C'est grossier mais VRAI, et c'est ce qui permet aux
 * blocs « produits de la même catégorie » d'avoir enfin quelque chose à dire.
 *
 * Quand l'arborescence arrivera, ces deux catégories resteront le premier
 * niveau : les niveaux fins viendront se ranger dessous, sans rien casser.
 *
 * ─── CE QUE CE SCRIPT NE FAIT PAS ──────────────────────────────────────────
 *
 * Il ne touche PAS à la visibilité des fiches. Elles restent « nulle part »
 * tant que personne ne décide de les exposer : ranger un produit dans une
 * catégorie et le publier au catalogue sont deux décisions distinctes, et la
 * seconde se voit par les clients.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Ligne de commande uniquement.\n");
}

$racine = dirname(__FILE__);

while ($racine !== '/' && !is_file($racine . '/config/config.inc.php')) {
    $racine = dirname($racine);
}

if (!is_file($racine . '/config/config.inc.php')) {
    exit("À lancer depuis une installation PrestaShop.\n");
}

require $racine . '/config/config.inc.php';

$argument = (string) ($argv[1] ?? '');

if ($argument === '--retirer') {
    $db = Db::getInstance();
    $retirees = 0;

    foreach ($db->executeS(
        'SELECT c.id_category FROM ' . _DB_PREFIX_ . 'category c'
        . ' JOIN ' . _DB_PREFIX_ . 'category_lang cl ON cl.id_category = c.id_category'
        . ' WHERE cl.meta_title = "eko-famille"'
    ) ?: [] as $c) {
        (new Category((int) $c['id_category']))->delete();
        ++$retirees;
    }

    printf("%d catégorie(s) retirée(s).\n", $retirees);
    exit(0);
}

if ($argument === '' || !is_file($argument)) {
    exit("Usage : php ranger-en-categories.php <familles.json>\n       php ranger-en-categories.php --retirer\n");
}

$produits = json_decode((string) file_get_contents($argument), true);

if (!is_array($produits) || $produits === []) {
    exit("Fichier illisible.\n");
}

$db = Db::getInstance();
$idLang = (int) Configuration::get('PS_LANG_DEFAULT');
$racineCategorie = (int) Configuration::get('PS_HOME_CATEGORY');

/** Les fiches liées, par référence ERP. */
$fiches = [];

foreach ($db->executeS(
    'SELECT id_product, eko_reference FROM ' . _DB_PREFIX_ . 'ekosync_produit WHERE source = "printoclock"'
) ?: [] as $l) {
    $fiches[(string) $l['eko_reference']] = (int) $l['id_product'];
}

printf("%d fiche(s) liée(s).\n\n", count($fiches));

$categories = [];
$ranges = 0;
$sans = 0;

foreach ($produits as $p) {
    $code = trim((string) ($p['code'] ?? ''));
    $famille = trim((string) ($p['family'] ?? ''));
    $idProduct = $fiches[$code] ?? 0;

    if ($idProduct === 0 || $famille === '') {
        ++$sans;
        continue;
    }

    if (!isset($categories[$famille])) {
        // `meta_title` sert de MARQUE : c'est à lui que `--retirer` reconnaît
        // ce que ce script a créé, sans jamais toucher aux catégories que le
        // marchand a faites lui-même.
        $existante = (int) $db->getValue(
            'SELECT c.id_category FROM ' . _DB_PREFIX_ . 'category c'
            . ' JOIN ' . _DB_PREFIX_ . 'category_lang cl ON cl.id_category = c.id_category'
            . ' AND cl.id_lang = ' . $idLang
            . ' WHERE cl.name = "' . pSQL($famille) . '" AND cl.meta_title = "eko-famille"'
        );

        if ($existante === 0) {
            $c = new Category();
            $c->id_parent = $racineCategorie;
            $c->active = true;
            $c->name = [$idLang => $famille];
            $c->link_rewrite = [$idLang => Tools::str2url($famille) ?: 'famille-' . count($categories)];
            $c->meta_title = [$idLang => 'eko-famille'];

            if (!$c->add()) {
                printf("  échec : la catégorie « %s » n'a pas pu être créée\n", $famille);
                continue;
            }

            $existante = (int) $c->id;
            printf("  catégorie créée : %s (#%d)\n", $famille, $existante);
        }

        $categories[$famille] = $existante;
    }

    $idCategory = $categories[$famille];

    // `addToCategories` conserve les rattachements existants ; on pose ensuite
    // la catégorie par défaut, qui décide de l'URL et du fil d'Ariane.
    $produit = new Product($idProduct);
    $produit->addToCategories([$idCategory, $racineCategorie]);

    $db->execute(
        'UPDATE ' . _DB_PREFIX_ . 'product SET id_category_default = ' . $idCategory
        . ' WHERE id_product = ' . $idProduct
    );
    $db->execute(
        'UPDATE ' . _DB_PREFIX_ . 'product_shop SET id_category_default = ' . $idCategory
        . ' WHERE id_product = ' . $idProduct
    );

    ++$ranges;
}

foreach ($categories as $nom => $id) {
    Category::regenerateEntireNtree();
    break;
}

printf("\n%d fiche(s) rangée(s) dans %d catégorie(s), %d sans famille ou sans fiche.\n", $ranges, count($categories), $sans);
printf("\nLes fiches restent en visibilité « nulle part » : les ranger ne les publie pas.\n");
printf("Pour revenir en arrière : php ranger-en-categories.php --retirer\n");
