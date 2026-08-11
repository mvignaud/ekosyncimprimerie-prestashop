<?php

/**
 * EKO Sync — Imprimerie
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

/**
 * Quelle fiche boutique correspond à quel produit d'atelier.
 *
 * Sans cette correspondance, le module ne peut rien chiffrer : il ne sait pas
 * quel produit de l'ERP interroger pour une fiche donnée. C'est le premier
 * geste du marchand, et le seul réglage vraiment obligatoire.
 *
 * ─── Pourquoi une table et pas un champ du produit ─────────────────────────
 *
 * Un champ ajouté à `product` obligerait à modifier une table du cœur, que
 * chaque montée de version de PrestaShop réécrit. Une table à part se retire
 * proprement à la désinstallation, et n'entre en collision avec rien.
 *
 * La correspondance est UN pour UN dans les deux sens : une fiche boutique ne
 * peut chiffrer qu'avec un produit d'atelier, et deux fiches ne doivent pas
 * pointer le même — sans quoi le marchand croit vendre deux choses là où
 * l'atelier n'en fabrique qu'une, et les statistiques mentent. La contrainte
 * est posée en base plutôt que vérifiée en PHP : elle tient même si une
 * écriture passe à côté du module.
 */

declare(strict_types=1);

namespace Eko\SyncImprimerie\Configurateur;

class LiaisonProduit
{
    /** Le produit d'atelier lié à cette fiche, ou `null`. */
    public function pour(int $idProduct): ?int
    {
        if ($idProduct <= 0) {
            return null;
        }

        $this->table();

        $valeur = \Db::getInstance()->getValue(
            'SELECT `eko_product_id` FROM `' . _DB_PREFIX_ . 'ekosync_produit`'
            . ' WHERE `id_product` = ' . (int) $idProduct
        );

        return ($valeur === false || $valeur === null || $valeur === '') ? null : (int) $valeur;
    }

    /**
     * Lie une fiche à un produit d'atelier, ou rompt la liaison si l'identifiant
     * vaut zéro.
     *
     * @return array{ok: bool, message: string}
     */
    public function lier(int $idProduct, int $ekoProductId): array
    {
        if ($idProduct <= 0) {
            return ['ok' => false, 'message' => 'Fiche produit inconnue.'];
        }

        $this->table();

        if ($ekoProductId <= 0) {
            \Db::getInstance()->execute(
                'DELETE FROM `' . _DB_PREFIX_ . 'ekosync_produit` WHERE `id_product` = ' . (int) $idProduct
            );

            return ['ok' => true, 'message' => 'Liaison retirée : cette fiche ne sera plus chiffrée par l\'ERP.'];
        }

        // Deux fiches sur le même produit d'atelier : on refuse plutôt que
        // d'écraser. Le marchand croirait vendre deux choses distinctes.
        $autre = (int) \Db::getInstance()->getValue(
            'SELECT `id_product` FROM `' . _DB_PREFIX_ . 'ekosync_produit`'
            . ' WHERE `eko_product_id` = ' . (int) $ekoProductId
            . ' AND `id_product` <> ' . (int) $idProduct
        );

        if ($autre > 0) {
            return [
                'ok' => false,
                'message' => sprintf(
                    'Ce produit d\'atelier est déjà lié à la fiche #%d. Une fiche par produit d\'atelier.',
                    $autre
                ),
            ];
        }

        $ok = (bool) \Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'ekosync_produit` (`id_product`, `eko_product_id`, `date_upd`) '
            . 'VALUES (' . (int) $idProduct . ', ' . (int) $ekoProductId . ', NOW()) '
            . 'ON DUPLICATE KEY UPDATE `eko_product_id` = ' . (int) $ekoProductId . ', `date_upd` = NOW()'
        );

        return [
            'ok' => $ok,
            'message' => $ok
                ? 'Fiche liée au produit d\'atelier : son prix viendra désormais de l\'ERP.'
                : 'La liaison n\'a pas pu être enregistrée.',
        ];
    }

    /**
     * Toutes les liaisons, pour l'écran de contrôle du module.
     *
     * @return list<array{id_product: int, eko_product_id: int}>
     */
    public function toutes(): array
    {
        $this->table();

        $lignes = \Db::getInstance()->executeS(
            'SELECT `id_product`, `eko_product_id` FROM `' . _DB_PREFIX_ . 'ekosync_produit`'
            . ' ORDER BY `id_product`'
        ) ?: [];

        return array_map(
            static fn (array $l): array => [
                'id_product' => (int) $l['id_product'],
                'eko_product_id' => (int) $l['eko_product_id'],
            ],
            $lignes
        );
    }

    /**
     * Crée la table au premier usage.
     *
     * L'unicité porte sur les DEUX colonnes, chacune de son côté : la clé
     * primaire empêche une fiche d'être liée deux fois, l'index unique empêche
     * un produit d'atelier de l'être. Poser la règle en base plutôt qu'en PHP
     * la rend vraie même pour une écriture qui contournerait le module.
     */
    private function table(): void
    {
        \Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ekosync_produit` ('
            . '`id_product` INT UNSIGNED NOT NULL,'
            . '`eko_product_id` INT UNSIGNED NOT NULL,'
            . '`date_upd` DATETIME NOT NULL,'
            . 'PRIMARY KEY (`id_product`),'
            . 'UNIQUE KEY `eko_product` (`eko_product_id`)'
            . ') ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
        );
    }
}
