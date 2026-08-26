<?php
declare(strict_types=1);
$racine = __DIR__;
while ($racine !== '/' && !is_file($racine . '/config/config.inc.php')) { $racine = dirname($racine); }
require $racine . '/config/config.inc.php';
$db = Db::getInstance();
$p = _DB_PREFIX_;
$l = (int) Configuration::get('PS_LANG_DEFAULT');
printf("  ATTRIBUTS   (Attributs & caractéristiques > Attributs)      : %s\n", $db->getValue("SELECT COUNT(*) FROM {$p}attribute_group"));
printf("  CARACTERISTIQUES (même écran, onglet « Caractéristiques ») : %s\n", $db->getValue("SELECT COUNT(*) FROM {$p}feature"));
printf("  dont posées par le module (préfixe EKO)                    : %s\n",
    $db->getValue("SELECT COUNT(*) FROM {$p}feature f JOIN {$p}feature_lang fl ON fl.id_feature=f.id_feature AND fl.id_lang={$l} WHERE fl.name LIKE 'EKO %'"));
printf("  valeurs rattachées à des produits                          : %s\n", $db->getValue("SELECT COUNT(*) FROM {$p}feature_product"));
echo "\n  Les cinq premières :\n";
foreach ($db->executeS("SELECT fl.name, COUNT(DISTINCT fp.id_product) n FROM {$p}feature f JOIN {$p}feature_lang fl ON fl.id_feature=f.id_feature AND fl.id_lang={$l} LEFT JOIN {$p}feature_product fp ON fp.id_feature=f.id_feature GROUP BY f.id_feature, fl.name ORDER BY n DESC LIMIT 5") ?: [] as $f) {
    printf("    %-28s %s produit(s)\n", $f['name'], $f['n']);
}
