<?php

/**
 * EKO Sync — Imprimerie
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

declare(strict_types=1);

namespace Eko\SyncImprimerie\Configurateur;

/**
 * Le lien entre une ligne de panier et le fichier déposé sur E-KO.
 *
 * ─── POURQUOI UNE TABLE, ET NON UN CHAMP DE PERSONNALISATION ───────────────
 *
 * La tentation était d'écrire l'identifiant de dépôt dans un troisième
 * `customized_data`, à côté de la configuration et du commentaire. Trois
 * raisons l'écartent :
 *
 *   1. la lecture du module ne filtre pas `index` — un `getValue()` avec
 *      `LIMIT 1` implicite rendrait l'un des trois au hasard ;
 *   2. le champ est affiché tel quel au back-office et sur la facture : un
 *      identifiant technique y ferait tache ;
 *   3. un dépôt porte un ÉTAT qui évolue — en cours, conforme, à revoir — et
 *      un champ texte de personnalisation n'est pas fait pour être réécrit à
 *      chaque sondage.
 *
 * ─── CE QUI EST STOCKÉ, ET CE QUI NE L'EST PAS ─────────────────────────────
 *
 * Le fichier lui-même ne touche jamais la boutique : il transite en flux vers
 * le S3 d'E-KO et n'est jamais écrit ici. Cette table ne garde qu'une
 * référence, un nom lisible et le dernier verdict connu — de quoi afficher un
 * état sans redemander E-KO à chaque affichage de page.
 */
final class FichierClient
{
    /** Crée la table si elle manque. Idempotent. */
    public static function installer(): bool
    {
        return (bool) \Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ekosync_fichier` ('
            . ' `id_fichier` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' `id_customization` INT UNSIGNED NOT NULL,'
            . ' `id_cart` INT UNSIGNED NOT NULL DEFAULT 0,'
            . ' `upload_id` INT UNSIGNED NOT NULL,'
            . ' `nom` VARCHAR(255) NOT NULL,'
            . ' `taille` INT UNSIGNED NOT NULL DEFAULT 0,'
            . ' `statut` VARCHAR(16) NOT NULL DEFAULT "pending",'
            . ' `verdict` VARCHAR(16) DEFAULT NULL,'
            . ' `date_add` DATETIME NOT NULL,'
            . ' `date_upd` DATETIME NOT NULL,'
            . ' PRIMARY KEY (`id_fichier`),'
            // Une ligne de panier peut porter PLUSIEURS fichiers — un recto et
            // un verso, par exemple. La clé n'est donc pas unique sur la
            // customization, mais sur le couple avec le dépôt : redéposer le
            // même dépôt met à jour, deux dépôts distincts coexistent.
            . ' UNIQUE KEY `custo_upload` (`id_customization`, `upload_id`),'
            . ' KEY `custo` (`id_customization`),'
            . ' KEY `panier` (`id_cart`)'
            . ') ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * Enregistre un dépôt, ou met à jour celui qui existe.
     *
     * @param array<string,mixed> $donnees
     */
    public static function poser(int $idCustomization, int $idCart, int $uploadId, array $donnees): bool
    {
        if ($idCustomization <= 0 || $uploadId <= 0) {
            return false;
        }

        $maintenant = date('Y-m-d H:i:s');

        return (bool) \Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'ekosync_fichier`'
            . ' (`id_customization`, `id_cart`, `upload_id`, `nom`, `taille`, `statut`, `verdict`, `date_add`, `date_upd`)'
            . ' VALUES (' . $idCustomization
            . ', ' . $idCart
            . ', ' . $uploadId
            . ", '" . pSQL((string) ($donnees['nom'] ?? '')) . "'"
            . ', ' . (int) ($donnees['taille'] ?? 0)
            . ", '" . pSQL((string) ($donnees['statut'] ?? 'pending')) . "'"
            . ', ' . (isset($donnees['verdict']) ? "'" . pSQL((string) $donnees['verdict']) . "'" : 'NULL')
            . ", '" . pSQL($maintenant) . "'"
            . ", '" . pSQL($maintenant) . "')"
            . ' ON DUPLICATE KEY UPDATE'
            . ' `nom` = VALUES(`nom`),'
            . ' `taille` = VALUES(`taille`),'
            . ' `statut` = VALUES(`statut`),'
            . ' `verdict` = VALUES(`verdict`),'
            . ' `date_upd` = VALUES(`date_upd`)'
        );
    }

    /** Met à jour le seul état, après un sondage. */
    public static function majEtat(int $uploadId, string $statut, ?string $verdict): bool
    {
        if ($uploadId <= 0) {
            return false;
        }

        return (bool) \Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'ekosync_fichier`'
            . " SET `statut` = '" . pSQL($statut) . "',"
            . ' `verdict` = ' . ($verdict === null ? 'NULL' : "'" . pSQL($verdict) . "'") . ','
            . " `date_upd` = '" . pSQL(date('Y-m-d H:i:s')) . "'"
            . ' WHERE `upload_id` = ' . $uploadId
        );
    }

    /**
     * Les fichiers d'une ligne de panier.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function deLaLigne(int $idCustomization): array
    {
        if ($idCustomization <= 0) {
            return [];
        }

        $lignes = \Db::getInstance()->executeS(
            'SELECT `upload_id`, `nom`, `taille`, `statut`, `verdict`, `date_upd`'
            . ' FROM `' . _DB_PREFIX_ . 'ekosync_fichier`'
            . ' WHERE `id_customization` = ' . $idCustomization
            . ' ORDER BY `id_fichier` ASC'
        );

        return is_array($lignes) ? $lignes : [];
    }

    /**
     * Tous les dépôts d'un panier, rangés par ligne.
     *
     * ⚠️ PAR PANIER, ET NON PAR COMMANDE. `customization` n'a jamais porté
     * d'`id_order` : le cœur lui-même relie une commande à ses
     * personnalisations en passant par `orders.id_cart`. Une colonne
     * `id_order` ajoutée ici aurait dupliqué ce lien — et divergé le jour où
     * un panier donne deux commandes.
     *
     * @return array<int,array<int,array<string,mixed>>> id_customization => dépôts
     */
    public static function duPanier(int $idCart): array
    {
        if ($idCart <= 0) {
            return [];
        }

        $lignes = \Db::getInstance()->executeS(
            'SELECT `id_customization`, `upload_id`, `nom`, `taille`, `statut`, `verdict`, `date_upd`'
            . ' FROM `' . _DB_PREFIX_ . 'ekosync_fichier`'
            . ' WHERE `id_cart` = ' . $idCart
            . ' ORDER BY `id_customization` ASC, `id_fichier` ASC'
        );

        $parLigne = [];

        foreach (is_array($lignes) ? $lignes : [] as $l) {
            $parLigne[(int) $l['id_customization']][] = $l;
        }

        return $parLigne;
    }

    /**
     * Le dépôt appartient-il bien à cette ligne ?
     *
     * ⚠️ Garde indispensable au sondage de statut : sans lui, l'identifiant de
     * dépôt étant un simple entier, il suffirait de le faire varier pour lire
     * l'état — et le rapport — des fichiers d'autres clients.
     */
    public static function appartient(int $uploadId, int $idCustomization): bool
    {
        return (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ekosync_fichier`'
            . ' WHERE `upload_id` = ' . $uploadId
            . ' AND `id_customization` = ' . $idCustomization
        ) > 0;
    }

    /** Retire un dépôt de la boutique. Le fichier reste sur E-KO. */
    public static function retirer(int $uploadId, int $idCustomization): bool
    {
        return (bool) \Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'ekosync_fichier`'
            . ' WHERE `upload_id` = ' . $uploadId
            . ' AND `id_customization` = ' . $idCustomization
        );
    }
}
