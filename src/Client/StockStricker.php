<?php

/**
 * EKO Sync — Imprimerie
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

declare(strict_types=1);

namespace Eko\SyncImprimerie\Client;

/**
 * Le stock d'un fournisseur, rapatrié d'E-KO vers la boutique.
 *
 * ─── POURQUOI CETTE CLASSE EXISTE ─────────────────────────────────────────
 *
 * E-KO interroge le fournisseur et tient son stock à jour. La boutique, elle,
 * ne le lisait jamais : relevé le 2026-08-27, sa dernière écriture datait du
 * jour de l'import du catalogue — trois jours plus tôt. Mille neuf cent
 * soixante-dix déclinaisons sur trois mille cent quatre-vingt-une étaient
 * fausses, et l'écart se creusait d'heure en heure. Une pièce affichée à
 * 5 751 exemplaires n'en avait plus que 2 295.
 *
 * Le module affichait pourtant le stock fidèlement : il lit `stock_available`,
 * la table de PrestaShop. Elle était juste… et périmée.
 *
 * ─── ⚠️ `StockAvailable::setQuantity`, JAMAIS UN `UPDATE` ────────────────
 *
 * PrestaShop range DEUX choses dans cette table : une ligne par déclinaison,
 * et une ligne de TOTAL par fiche (`id_product_attribute` à zéro). Un UPDATE
 * en SQL brut sur les déclinaisons laisserait ce total figé — et c'est lui que
 * le thème lit pour écrire « en stock » ou « épuisé ». La fiche annoncerait
 * donc une disponibilité que ses déclinaisons démentent.
 *
 * ─── ⚠️ UNE ABSENCE N'EST PAS UNE RUPTURE ────────────────────────────────
 *
 * Une déclinaison qu'E-KO ne nomme pas n'est PAS remise à zéro. L'absence
 * d'une clé dans une réponse est une absence d'information, pas un stock nul :
 * la mettre à zéro déréférencerait un article vendable sur la foi d'un
 * silence — une réponse tronquée, une pagination écourtée, et tout le
 * catalogue passerait « épuisé » sans qu'aucune erreur ne soit levée.
 */
class StockStricker
{
    /** Au-delà, c'est que la pagination boucle : on s'arrête plutôt que tourner. */
    private const PAGES_MAX = 40;

    /**
     * Rapatrie le stock et n'écrit que ce qui a changé.
     *
     * @return array{lues: int, boutique: int, identiques: int, ecrites: int, inconnues: int, restantes: int, secondes: float, erreur: string|null}
     */
    public static function rapatrier(\Module $module, bool $ecrire): array
    {
        $debut = microtime(true);
        $db = \Db::getInstance();
        $p = _DB_PREFIX_;
        $idShop = (int) \Context::getContext()->shop->id;

        $etat = [
            'lues' => 0, 'boutique' => 0, 'identiques' => 0,
            'ecrites' => 0, 'inconnues' => 0, 'restantes' => 0,
            'secondes' => 0.0, 'erreur' => null,
        ];

        // ─── 1. Le stock d'E-KO, page par page ───────────────────────────
        $stock = [];
        for ($page = 1; $page <= self::PAGES_MAX; $page++) {
            $r = $module->client()->appeler(
                'GET',
                '/api/v1/stricker/stock?per_page=1000&page='.$page,
                null,
                true // sans cache : un stock mis en cache est un stock faux
            );

            if (empty($r['ok'])) {
                $etat['erreur'] = 'page '.$page.' : '.(string) ($r['erreur'] ?? 'sans message');

                return $etat + ['secondes' => round(microtime(true) - $debut, 1)];
            }

            $lot = (array) ($r['donnees']['data'] ?? []);
            if ($lot === []) {
                break;
            }

            foreach ($lot as $sku => $q) {
                $stock[(string) $sku] = (int) $q;
            }

            if ($page >= (int) ($r['donnees']['last_page'] ?? 1)) {
                break;
            }
        }

        $etat['lues'] = count($stock);

        // Une réponse vide ne veut pas dire « tout est épuisé ».
        if ($stock === []) {
            $etat['erreur'] = "E-KO n'a rendu aucun stock : rien n'est écrit";
            $etat['secondes'] = round(microtime(true) - $debut, 1);

            return $etat;
        }

        // ─── 2. Ce que la boutique porte ─────────────────────────────────
        $lignes = (array) $db->executeS(
            "SELECT pa.id_product_attribute AS ipa, pa.id_product AS ip,
                    pa.reference AS sku, IFNULL(sa.quantity, 0) AS qte
               FROM `{$p}product_attribute` pa
               JOIN `{$p}product` pr ON pr.id_product = pa.id_product
               LEFT JOIN `{$p}stock_available` sa
                      ON sa.id_product_attribute = pa.id_product_attribute
                     AND sa.id_shop = ".$idShop."
              WHERE pr.reference LIKE 'ST-%'"
        );

        $etat['boutique'] = count($lignes);
        $aEcrire = [];

        foreach ($lignes as $l) {
            $sku = (string) $l['sku'];

            if (!array_key_exists($sku, $stock)) {
                $etat['inconnues']++;

                continue;
            }

            if ((int) $l['qte'] === $stock[$sku]) {
                $etat['identiques']++;

                continue;
            }

            $aEcrire[] = [
                'ipa' => (int) $l['ipa'],
                'ip' => (int) $l['ip'],
                'apres' => $stock[$sku],
            ];
        }

        if (!$ecrire) {
            $etat['ecrites'] = count($aEcrire);
            $etat['secondes'] = round(microtime(true) - $debut, 1);

            return $etat;
        }

        foreach ($aEcrire as $e) {
            \StockAvailable::setQuantity($e['ip'], $e['ipa'], $e['apres'], $idShop);
            $etat['ecrites']++;
        }

        // ⚠️ ON RELIT CE QU'ON VIENT D'ÉCRIRE. Le compteur de boucle dit ce
        // qu'on a TENTÉ ; seule la relecture dit ce qui est en base.
        foreach ($aEcrire as $e) {
            $lu = (int) $db->getValue(
                "SELECT quantity FROM `{$p}stock_available`
                  WHERE id_product_attribute = ".$e['ipa']." AND id_shop = ".$idShop
            );
            if ($lu !== $e['apres']) {
                $etat['restantes']++;
            }
        }

        $etat['secondes'] = round(microtime(true) - $debut, 1);

        return $etat;
    }
}
