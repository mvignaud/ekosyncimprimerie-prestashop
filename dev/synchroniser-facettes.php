<?php

/**
 * EKO Sync — Imprimerie
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

/**
 * Les critères de l'ERP recopiés en CARACTÉRISTIQUES PrestaShop.
 *
 *     php dev/synchroniser-facettes.php <facettes.json>
 *     php dev/synchroniser-facettes.php --retirer
 *
 * ─── POURQUOI DES CARACTÉRISTIQUES, ET PAS DES ATTRIBUTS ───────────────────
 *
 * Les deux sont filtrables par la navigation à facettes, mais un ATTRIBUT
 * n'existe qu'à travers des déclinaisons : il faudrait fabriquer une
 * combinaison par configuration, soit 19 066 lignes de `product_attribute`
 * pour ce catalogue. Aucune ne servirait — le prix ne vient pas d'elles — et
 * chacune alourdirait tout ce que PrestaShop fait sur les produits.
 *
 * Une CARACTÉRISTIQUE décrit le produit sans rien engendrer. C'est exactement
 * ce qu'on veut dire : « ce produit se fait en A4, A5 et DL ».
 *
 * ⚠️ VÉRIFIÉ AVANT D'ÉCRIRE : la table `feature_product` de PrestaShop 9 a une
 * clé primaire à TROIS colonnes (id_feature, id_product, id_feature_value).
 * Plusieurs valeurs par caractéristique et par produit sont donc permises. Ce
 * n'était pas acquis — la documentation et l'écran d'administration n'en
 * laissent voir qu'une.
 *
 * ⚠️ CONSÉQUENCE, À DIRE AU MARCHAND : l'écran produit du back-office ne gère
 * qu'une valeur par caractéristique. Y toucher les caractéristiques d'un
 * produit synchronisé les réduirait à une seule. Ce script les repose.
 *
 * ─── POURQUOI UN FICHIER, ENCORE ───────────────────────────────────────────
 *
 * Même raison que pour l'injection du catalogue : sur cet hébergement
 * mutualisé, la ligne de commande n'a pas le réseau sortant. Les facettes sont
 * donc extraites là où l'API est joignable, déposées ici, et lues sans réseau.
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

/** Le préfixe des caractéristiques posées par ce script. */
const MARQUE = 'EKO ';

$db = Db::getInstance();
$idLang = (int) Configuration::get('PS_LANG_DEFAULT');
$argument = (string) ($argv[1] ?? '');

/**
 * Les caractéristiques que CE script a posées.
 *
 * Reconnues à leur préfixe : une caractéristique créée à la main par le
 * marchand ne doit pas être emportée par un `--retirer`.
 *
 * @return array<string, int> nom -> id
 */
function nôtres(int $idLang): array
{
    $sortie = [];

    foreach (Db::getInstance()->executeS(
        'SELECT f.id_feature, fl.name FROM ' . _DB_PREFIX_ . 'feature f'
        . ' JOIN ' . _DB_PREFIX_ . 'feature_lang fl ON fl.id_feature = f.id_feature'
        . ' AND fl.id_lang = ' . $idLang
        . ' WHERE fl.name LIKE "' . pSQL(MARQUE) . '%"'
    ) ?: [] as $f) {
        $sortie[(string) $f['name']] = (int) $f['id_feature'];
    }

    return $sortie;
}

if ($argument === '--retirer') {
    $ids = nôtres($idLang);

    foreach ($ids as $id) {
        (new Feature($id))->delete();
    }

    printf("%d caractéristique(s) retirée(s).\n", count($ids));
    exit(0);
}

if ($argument === '' || !is_file($argument)) {
    exit(
        "Usage : php dev/synchroniser-facettes.php <facettes.json>\n"
        . "        php dev/synchroniser-facettes.php --retirer\n\n"
        . "Le fichier porte, par code produit, ce que rend\n"
        . "GET /api/v1/printoclock/products/{code}/facets.\n"
    );
}

$facettes = json_decode((string) file_get_contents($argument), true);

if (!is_array($facettes) || $facettes === []) {
    exit("Le fichier ne contient aucune facette exploitable.\n");
}

/** Les fiches liées à la sous-traitance, par référence ERP. */
$fiches = [];

foreach ($db->executeS(
    'SELECT id_product, eko_reference FROM ' . _DB_PREFIX_ . 'ekosync_produit'
    . ' WHERE source = "' . pSQL(LiaisonProduit::SOURCE_PRINTOCLOCK) . '"'
) ?: [] as $l) {
    $fiches[(string) $l['eko_reference']] = (int) $l['id_product'];
}

printf("%d fiche(s) liée(s), %d produit(s) dans le fichier.\n\n", count($fiches), count($facettes));

$caracteristiques = nôtres($idLang);
$valeurs = [];
$posees = 0;
$ignorees = 0;

foreach ($facettes as $code => $produit) {
    // ⚠️ La clé est le code de l'ERP NU. `POC-` est le préfixe de la référence
    // PRESTASHOP, celle qu'on lit sur la fiche — pas celle que la table de
    // liaison enregistre. Chercher `POC-FLY` là où est écrit `FLY` faisait
    // ignorer les quatre-vingt-quatre produits, en le disant poliment.
    $idProduct = $fiches[(string) $code] ?? 0;

    if ($idProduct === 0) {
        printf("  ignoré : %s n'a pas de fiche liée\n", $code);
        ++$ignorees;
        continue;
    }

    $libelles = (array) ($produit['labels'] ?? []);

    foreach ((array) ($produit['steps'] ?? []) as $etape) {
        $intitule = trim((string) ($etape['label'] ?? ''));
        $liste = (array) ($etape['values'] ?? []);

        if ($intitule === '' || $liste === []) {
            continue;
        }

        $nom = MARQUE . $intitule;

        if (!isset($caracteristiques[$nom])) {
            $f = new Feature();
            $f->name = [$idLang => $nom];

            if (!$f->add()) {
                printf("  échec : la caractéristique « %s » n'a pas pu être créée\n", $nom);
                continue;
            }

            $caracteristiques[$nom] = (int) $f->id;
        }

        $idFeature = $caracteristiques[$nom];

        // On repart de zéro pour CE produit et CETTE caractéristique : un
        // format retiré du catalogue du fournisseur doit disparaître de la
        // fiche, pas y rester parce que personne ne l'a effacé.
        $db->execute(
            'DELETE FROM ' . _DB_PREFIX_ . 'feature_product'
            . ' WHERE id_feature = ' . $idFeature . ' AND id_product = ' . $idProduct
        );

        foreach ($liste as $valeurCode) {
            $valeurCode = (string) $valeurCode;
            // Le NOM lisible quand l'ERP le connaît, le code sinon : c'est ce
            // texte que le visiteur lira dans le filtre.
            $texte = trim((string) ($libelles[$valeurCode]['name'] ?? $valeurCode));

            if ($texte === '') {
                continue;
            }

            $cle = $idFeature . '|' . mb_strtolower($texte);

            if (!isset($valeurs[$cle])) {
                $existante = (int) $db->getValue(
                    'SELECT fv.id_feature_value FROM ' . _DB_PREFIX_ . 'feature_value fv'
                    . ' JOIN ' . _DB_PREFIX_ . 'feature_value_lang fvl'
                    . ' ON fvl.id_feature_value = fv.id_feature_value AND fvl.id_lang = ' . $idLang
                    . ' WHERE fv.id_feature = ' . $idFeature
                    . ' AND fvl.value = "' . pSQL($texte) . '"'
                );

                if ($existante === 0) {
                    $fv = new FeatureValue();
                    $fv->id_feature = $idFeature;
                    $fv->custom = false;
                    $fv->value = [$idLang => mb_substr($texte, 0, 255)];

                    if (!$fv->add()) {
                        continue;
                    }

                    $existante = (int) $fv->id;
                }

                $valeurs[$cle] = $existante;
            }

            $db->execute(
                'INSERT IGNORE INTO ' . _DB_PREFIX_ . 'feature_product (id_feature, id_product, id_feature_value)'
                . ' VALUES (' . $idFeature . ', ' . $idProduct . ', ' . $valeurs[$cle] . ')'
            );
            ++$posees;
        }
    }
}

printf(
    "\n%d valeur(s) rattachée(s), %d caractéristique(s), %d produit(s) ignoré(s).\n",
    $posees,
    count($caracteristiques),
    $ignorees
);
printf("\nPour revenir en arrière : php dev/synchroniser-facettes.php --retirer\n");
