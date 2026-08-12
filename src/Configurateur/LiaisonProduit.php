<?php

/**
 * EKO Sync — Imprimerie
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

/**
 * Quelle fiche boutique correspond à quel produit E-KO — et de quelle SOURCE.
 *
 * Sans cette correspondance, le module ne peut rien chiffrer : il ne sait pas
 * quoi interroger pour une fiche donnée. C'est le premier geste du marchand, et
 * le seul réglage vraiment obligatoire.
 *
 * ─── POURQUOI UNE SOURCE, ET PAS SEULEMENT UN IDENTIFIANT ──────────────────
 *
 * E-KO sait chiffrer de DEUX façons, et elles n'ont presque rien en commun :
 *
 *   `atelier`     — le prix est CALCULÉ. Matières, temps machine, imposition,
 *                   majorations. Les options sont des champs libres (largeur,
 *                   hauteur), et l'identifiant du produit est un nombre.
 *
 *   `printoclock` — le prix est LU dans une grille rapportée du sous-traitant,
 *                   par couple (quantité, délai). Les options forment un ARBRE
 *                   de choix discrets où chaque étape restreint la suivante, et
 *                   l'identifiant est un code (« CDV »).
 *
 * Une colonne entière ne pouvait donc pas suffire, et « deviner la source à la
 * forme de l'identifiant » aurait marché jusqu'au jour où un code de
 * sous-traitance aurait été numérique.
 *
 * ─── Pourquoi une table et pas un champ du produit ─────────────────────────
 *
 * Un champ ajouté à `product` obligerait à modifier une table du cœur, que
 * chaque montée de version de PrestaShop réécrit. Une table à part se retire
 * proprement à la désinstallation, et n'entre en collision avec rien.
 *
 * La correspondance est UN pour UN dans les deux sens : une fiche boutique ne
 * chiffre qu'avec un produit E-KO, et deux fiches ne doivent pas pointer le
 * même — sans quoi le marchand croit vendre deux choses là où l'atelier n'en
 * fabrique qu'une, et les statistiques mentent. La contrainte est posée en base
 * plutôt que vérifiée en PHP : elle tient même si une écriture passe à côté du
 * module.
 */

declare(strict_types=1);

namespace Eko\SyncImprimerie\Configurateur;

class LiaisonProduit
{
    /** Le prix est calculé par l'atelier E-KO. */
    public const SOURCE_ATELIER = 'atelier';

    /** Le prix est lu dans la grille du sous-traitant Printoclock. */
    public const SOURCE_PRINTOCLOCK = 'printoclock';

    /** @return list<string> */
    public static function sources(): array
    {
        return [self::SOURCE_ATELIER, self::SOURCE_PRINTOCLOCK];
    }

    /**
     * La liaison de cette fiche, ou `null`.
     *
     * @return array{source: string, reference: string}|null
     */
    public function pour(int $idProduct): ?array
    {
        if ($idProduct <= 0) {
            return null;
        }

        $this->table();

        $ligne = \Db::getInstance()->getRow(
            'SELECT `source`, `eko_reference` FROM `' . _DB_PREFIX_ . 'ekosync_produit`'
            . ' WHERE `id_product` = ' . (int) $idProduct
        );

        if (!is_array($ligne) || ($ligne['eko_reference'] ?? '') === '') {
            return null;
        }

        return [
            'source' => (string) $ligne['source'],
            'reference' => (string) $ligne['eko_reference'],
        ];
    }

    /**
     * L'identifiant d'atelier de cette fiche, ou `null`.
     *
     * Raccourci pour le chemin le plus fréquent, et pour ne pas semer des
     * comparaisons de source dans tout le module.
     */
    public function produitAtelier(int $idProduct): ?int
    {
        $l = $this->pour($idProduct);

        return ($l !== null && $l['source'] === self::SOURCE_ATELIER && ctype_digit($l['reference']))
            ? (int) $l['reference']
            : null;
    }

    /**
     * Lie une fiche à un produit E-KO, ou rompt la liaison si la référence est
     * vide.
     *
     * @return array{ok: bool, message: string}
     */
    public function lier(int $idProduct, string $source, string $reference): array
    {
        if ($idProduct <= 0) {
            return ['ok' => false, 'message' => 'Fiche produit inconnue.'];
        }

        $this->table();

        $reference = trim($reference);

        if ($reference === '' || $reference === '0') {
            \Db::getInstance()->execute(
                'DELETE FROM `' . _DB_PREFIX_ . 'ekosync_produit` WHERE `id_product` = ' . (int) $idProduct
            );

            return ['ok' => true, 'message' => 'Liaison retirée : cette fiche ne sera plus chiffrée par l\'ERP.'];
        }

        if (!in_array($source, self::sources(), true)) {
            return ['ok' => false, 'message' => 'Source inconnue : « ' . htmlspecialchars($source) . ' ».'];
        }

        // Deux fiches sur le même produit E-KO : on refuse plutôt que
        // d'écraser. Le marchand croirait vendre deux choses distinctes.
        $autre = (int) \Db::getInstance()->getValue(
            'SELECT `id_product` FROM `' . _DB_PREFIX_ . 'ekosync_produit`'
            . ' WHERE `source` = "' . pSQL($source) . '"'
            . ' AND `eko_reference` = "' . pSQL($reference) . '"'
            . ' AND `id_product` <> ' . (int) $idProduct
        );

        if ($autre > 0) {
            return [
                'ok' => false,
                'message' => sprintf(
                    'Ce produit E-KO est déjà lié à la fiche #%d. Une fiche par produit.',
                    $autre
                ),
            ];
        }

        $ok = (bool) \Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'ekosync_produit` (`id_product`, `source`, `eko_reference`, `date_upd`) '
            . 'VALUES (' . (int) $idProduct . ', "' . pSQL($source) . '", "' . pSQL($reference) . '", NOW()) '
            . 'ON DUPLICATE KEY UPDATE `source` = "' . pSQL($source) . '",'
            . ' `eko_reference` = "' . pSQL($reference) . '", `date_upd` = NOW()'
        );

        return [
            'ok' => $ok,
            'message' => $ok
                ? 'Fiche liée : son prix viendra désormais de l\'ERP.'
                : 'La liaison n\'a pas pu être enregistrée.',
        ];
    }

    /**
     * Toutes les liaisons, pour l'écran de contrôle du module.
     *
     * @return list<array{id_product: int, source: string, reference: string}>
     */
    public function toutes(): array
    {
        $this->table();

        $lignes = \Db::getInstance()->executeS(
            'SELECT `id_product`, `source`, `eko_reference` FROM `' . _DB_PREFIX_ . 'ekosync_produit`'
            . ' ORDER BY `id_product`'
        ) ?: [];

        return array_map(
            static fn (array $l): array => [
                'id_product' => (int) $l['id_product'],
                'source' => (string) $l['source'],
                'reference' => (string) $l['eko_reference'],
            ],
            $lignes
        );
    }

    /**
     * Crée la table au premier usage, et la fait évoluer sans perdre l'existant.
     *
     * L'unicité porte sur les DEUX côtés : la clé primaire empêche une fiche
     * d'être liée deux fois, l'index unique empêche un produit E-KO de l'être.
     * Poser la règle en base plutôt qu'en PHP la rend vraie même pour une
     * écriture qui contournerait le module.
     *
     * ─── La reprise de l'ancienne forme ────────────────────────────────────
     *
     * Les premières versions ne connaissaient que l'atelier, et stockaient un
     * `eko_product_id` entier. On ajoute les deux colonnes, on RECOPIE l'ancien
     * identifiant, puis on retire l'ancienne colonne. Faire l'inverse — retirer
     * d'abord — perdrait toutes les liaisons du marchand en silence.
     */
    private function table(): void
    {
        $db = \Db::getInstance();

        $db->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ekosync_produit` ('
            . '`id_product` INT UNSIGNED NOT NULL,'
            . '`source` VARCHAR(16) NOT NULL DEFAULT "' . self::SOURCE_ATELIER . '",'
            . '`eko_reference` VARCHAR(64) NOT NULL DEFAULT "",'
            . '`date_upd` DATETIME NOT NULL,'
            . 'PRIMARY KEY (`id_product`),'
            . 'UNIQUE KEY `eko_produit` (`source`, `eko_reference`)'
            . ') ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
        );

        $colonnes = array_column($db->executeS('SHOW COLUMNS FROM `' . _DB_PREFIX_ . 'ekosync_produit`') ?: [], 'Field');

        if (in_array('source', $colonnes, true) && in_array('eko_reference', $colonnes, true)) {
            return;
        }

        // Ancienne forme : on l'élargit avant de recopier.
        if (!in_array('source', $colonnes, true)) {
            $db->execute(
                'ALTER TABLE `' . _DB_PREFIX_ . 'ekosync_produit`'
                . ' ADD COLUMN `source` VARCHAR(16) NOT NULL DEFAULT "' . self::SOURCE_ATELIER . '"'
            );
        }

        if (!in_array('eko_reference', $colonnes, true)) {
            $db->execute(
                'ALTER TABLE `' . _DB_PREFIX_ . 'ekosync_produit`'
                . ' ADD COLUMN `eko_reference` VARCHAR(64) NOT NULL DEFAULT ""'
            );
        }

        if (in_array('eko_product_id', $colonnes, true)) {
            $db->execute(
                'UPDATE `' . _DB_PREFIX_ . 'ekosync_produit`'
                . ' SET `eko_reference` = CAST(`eko_product_id` AS CHAR),'
                . ' `source` = "' . self::SOURCE_ATELIER . '"'
                . ' WHERE `eko_reference` = ""'
            );

            // L'ancien index unique portait sur la colonne qu'on retire.
            $db->execute('ALTER TABLE `' . _DB_PREFIX_ . 'ekosync_produit` DROP INDEX `eko_product`');
            $db->execute('ALTER TABLE `' . _DB_PREFIX_ . 'ekosync_produit` DROP COLUMN `eko_product_id`');
        }

        $db->execute(
            'ALTER TABLE `' . _DB_PREFIX_ . 'ekosync_produit`'
            . ' ADD UNIQUE KEY `eko_produit` (`source`, `eko_reference`)'
        );
    }
}
