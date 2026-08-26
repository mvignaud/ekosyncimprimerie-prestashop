<?php
/**
 * Rend visibles au catalogue les fiches de sous-traitance.
 *
 *     php rendre-visibles.php            → visibilité « partout »
 *     php rendre-visibles.php --cacher   → retour à « nulle part »
 *
 * ⚠️ Ce n'est pas un réglage technique : c'est une PUBLICATION. Les fiches
 * entrent dans les listes de catégorie, dans la recherche interne et dans le
 * plan du site — donc chez Google. Réversible, mais ce qui aura été indexé
 * entre-temps mettra du temps à en sortir.
 */
declare(strict_types=1);
$racine = __DIR__;
while ($racine !== '/' && !is_file($racine . '/config/config.inc.php')) { $racine = dirname($racine); }
require $racine . '/config/config.inc.php';

$cacher = in_array('--cacher', $argv, true);
$cible = $cacher ? 'none' : 'both';
$db = Db::getInstance();
$p = _DB_PREFIX_;

$ids = array_map(
    static fn (array $l): int => (int) $l['id_product'],
    $db->executeS("SELECT id_product FROM {$p}ekosync_produit WHERE source = 'printoclock'") ?: []
);

if ($ids === []) { exit("Aucune fiche liée.\n"); }
$liste = implode(',', $ids);

// Les deux tables : le front lit `product_shop`, et une seule des deux mise à
// jour laisse la boutique dire une chose et son contraire.
foreach (['product', 'product_shop'] as $table) {
    $db->execute("UPDATE {$p}{$table} SET visibility = '" . pSQL($cible) . "' WHERE id_product IN ({$liste})");
}
// `indexed` commande la recherche interne : visible sans être indexé, un
// produit reste introuvable par le moteur de la boutique.
$db->execute("UPDATE {$p}product SET indexed = " . ($cacher ? '0' : '1') . " WHERE id_product IN ({$liste})");
$db->execute("UPDATE {$p}product_shop SET indexed = " . ($cacher ? '0' : '1') . " WHERE id_product IN ({$liste})");

printf("%d fiche(s) passée(s) en visibilité « %s ».\n", count($ids), $cible === 'both' ? 'partout' : 'nulle part');
printf("Contrôle : %s en 'both', %s en 'none'.\n",
    $db->getValue("SELECT COUNT(*) FROM {$p}product_shop WHERE id_product IN ({$liste}) AND visibility = 'both'"),
    $db->getValue("SELECT COUNT(*) FROM {$p}product_shop WHERE id_product IN ({$liste}) AND visibility = 'none'"));
printf("\nPour revenir en arrière : php rendre-visibles.php --cacher\n");
