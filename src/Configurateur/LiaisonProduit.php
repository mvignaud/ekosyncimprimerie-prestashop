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

    /**
     * Le prix est lu dans la grille du second sous-traitant.
     *
     * Sa forme n'est ni celle de l'atelier ni celle de Printoclock : c'est un
     * FORMULAIRE d'une vingtaine de champs largement indépendants, que le
     * client remplit dans l'ordre qu'il veut, et non un enchaînement d'étapes
     * où chaque choix restreint la suivante. La référence porte donc quatre
     * segments — `produit:déclinaison:matière:page` — là où les deux autres
     * sources n'en ont qu'un.
     *
     * ⚠️ La MATIÈRE fait partie de la référence, et c'est voulu : une même
     * déclinaison du fournisseur alimente plusieurs fiches de la boutique —
     * Akylux, Dibond, PVC expansé sont trois pages, un seul produit chez lui.
     * Sans elle, deux fiches se disputeraient la même référence, que l'index
     * unique de la table interdit.
     */
    public const SOURCE_REALISAPRINT = 'realisaprint';

    /** @return list<string> */
    public static function sources(): array
    {
        return [self::SOURCE_ATELIER, self::SOURCE_PRINTOCLOCK, self::SOURCE_REALISAPRINT];
    }

    /**
     * La référence de ce second sous-traitant, éclatée.
     *
     * `223:798:1:panneau-akylux` donne le code que l'ERP attend — `223:798` —,
     * la matière imposée par la fiche — `1` —, et le reste, qui ne sert qu'à
     * distinguer deux pages du même produit.
     *
     * La matière vaut `''` quand la fiche n'en impose aucune : le produit n'en
     * propose alors qu'une, et la verrouiller n'aurait rien verrouillé.
     *
     * @return array{code: string, support: string}|null
     */
    public function realisaprint(int $idProduct): ?array
    {
        $l = $this->pour($idProduct);

        if ($l === null || $l['source'] !== self::SOURCE_REALISAPRINT) {
            return null;
        }

        $bouts = explode(':', $l['reference']);

        // Deux segments au minimum : sans déclinaison, l'ERP ne sait pas quel
        // formulaire servir, et un code tronqué rendrait un 404 illisible.
        if (count($bouts) < 2 || !ctype_digit($bouts[0]) || !ctype_digit($bouts[1])) {
            return null;
        }

        $support = (string) ($bouts[2] ?? '');

        return [
            'code' => $bouts[0] . ':' . $bouts[1],
            // « 0 » est la façon dont l'import écrit « aucune matière imposée ».
            'support' => ($support === '0') ? '' : $support,
        ];
    }

    /**
     * La liaison de cette fiche, ou `null`.
     *
     * @return array{source: string, reference: string}|null
     */
    /**
     * Ce qui a déjà été lu pendant CETTE requête, par identifiant de fiche.
     *
     * Une page catégorie demande la liaison de chaque vignette, et le bloc de
     * prix la redemande pour la même fiche. La réponse ne peut pas changer au
     * cours d'une requête HTTP — sauf si on l'écrit soi-même, et `lier()`
     * s'en charge alors d'oublier.
     *
     * @var array<int, array{source: string, reference: string}|null>
     */
    private static array $memoire = [];

    /** Le schéma a-t-il déjà été vérifié pendant cette requête ? */
    private static bool $schemaVu = false;

    public function pour(int $idProduct): ?array
    {
        if ($idProduct <= 0) {
            return null;
        }

        if (array_key_exists($idProduct, self::$memoire)) {
            return self::$memoire[$idProduct];
        }

        $this->table();

        $ligne = \Db::getInstance()->getRow(
            'SELECT `source`, `eko_reference` FROM `' . _DB_PREFIX_ . 'ekosync_produit`'
            . ' WHERE `id_product` = ' . (int) $idProduct
        );

        if (!is_array($ligne) || ($ligne['eko_reference'] ?? '') === '') {
            return self::$memoire[$idProduct] = null;
        }

        return self::$memoire[$idProduct] = [
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
        // Ce qui vient d'être écrit ne doit pas être masqué par ce qui a été lu
        // avant : sans cet oubli, l'écran d'administration réafficherait la
        // liaison d'avant l'enregistrement.
        unset(self::$memoire[$idProduct]);

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
        // ⚠️ UNE FOIS PAR REQUÊTE.
        //
        // Cette méthode ouvrait CHAQUE lecture de liaison par un
        // `CREATE TABLE IF NOT EXISTS` suivi d'un `SHOW COLUMNS`. Sur une page
        // catégorie de 30 vignettes, 90 requêtes là où 30 suffisent — sur un
        // MySQL mutualisé, partagé avec les autres sites du compte.
        //
        // Le schéma ne change pas PENDANT une requête HTTP. On garde donc la
        // vérification, qui assure la reprise de l'ancienne forme sans dépendre
        // d'un script de mise à jour joué, mais on ne la paie qu'une fois.
        if (self::$schemaVu) {
            return;
        }

        self::$schemaVu = true;

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
