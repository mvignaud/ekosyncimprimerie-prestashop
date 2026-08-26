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
 * Les gabarits déposés à la main, pour les formats que le calcul ne sait pas
 * produire.
 *
 * ─── POURQUOI PAR FORMAT, ET NON PAR PRODUIT ───────────────────────────────
 *
 * « Boîte cadeau pour bouteille » porte deux formats : 8 × 8 × 33 cm et
 * 9,5 × 9,5 × 32 cm. Ce sont deux découpes différentes. Un gabarit rangé sur
 * la fiche produit servirait le mauvais plan à un client sur deux — et rien
 * ne le signalerait, puisqu'un PDF s'ouvre toujours.
 *
 * La clé est donc le couple (produit, format).
 *
 * ─── OÙ LES FICHIERS SONT RANGÉS ───────────────────────────────────────────
 *
 * Dans `upload/`, jamais dans `modules/ekosyncimprimerie/` : le dossier d'un
 * module est effacé à sa réinstallation, et un gabarit dessiné à la main ne se
 * régénère pas. `upload/` est par ailleurs interdit d'accès direct par le
 * `.htaccess` de PrestaShop — les fichiers ne sortent que par le contrôleur,
 * qui décide quoi servir.
 */
final class DepotGabarit
{
    /** Le dossier de rangement, sous `upload/`. */
    private const DOSSIER = 'ekosync_gabarits';

    /** Poids maximal accepté, en octets. */
    public const TAILLE_MAX = 32 * 1024 * 1024;

    /**
     * Les types acceptés, par extension.
     *
     * Le PDF est la norme ; l'EPS et l'AI circulent encore chez les
     * fabricants de PLV, qui envoient leurs découpes au format de leur
     * logiciel de dessin. On les accepte plutôt que d'obliger à une
     * conversion qui ferait perdre les traits de coupe.
     */
    public const EXTENSIONS = ['pdf', 'eps', 'ai'];

    /** Crée la table si elle manque. Idempotent. */
    public static function installer(): bool
    {
        return (bool) \Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ekosync_gabarit` ('
            . ' `id_gabarit` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' `id_product` INT UNSIGNED NOT NULL,'
            . ' `format_cle` CHAR(32) NOT NULL,'
            . ' `format_libelle` VARCHAR(255) NOT NULL,'
            . ' `fichier` VARCHAR(255) NOT NULL,'
            . ' `taille` INT UNSIGNED NOT NULL DEFAULT 0,'
            . ' `date_upd` DATETIME NOT NULL,'
            . ' PRIMARY KEY (`id_gabarit`),'
            // Un seul gabarit par couple : redéposer remplace, et ne peut pas
            // créer un doublon dont on servirait l'un ou l'autre au hasard.
            . ' UNIQUE KEY `produit_format` (`id_product`, `format_cle`)'
            . ') ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * La clé stable d'un libellé de format.
     *
     * Elle est calculée sur le libellé NORMALISÉ — casse et espaces multiples
     * écartés — pour que « A4 - 3 volets » et « A4  -  3 Volets » désignent le
     * même gabarit. Sans quoi une correction de casse en base ferait
     * disparaître un fichier déposé.
     */
    public static function cle(string $libelle): string
    {
        $normalise = preg_replace('/\s+/u', ' ', trim($libelle)) ?? $libelle;

        return md5(mb_strtolower($normalise));
    }

    /** Le chemin absolu du dossier de rangement, créé au besoin. */
    private static function dossier(): string
    {
        $chemin = rtrim(_PS_UPLOAD_DIR_, '/') . '/' . self::DOSSIER;

        if (!is_dir($chemin)) {
            @mkdir($chemin, 0755, true);
        }

        return $chemin;
    }

    /**
     * Le gabarit déposé pour ce couple, ou `null`.
     *
     * @return array{fichier:string,chemin:string,taille:int,libelle:string,date:string}|null
     */
    public static function lire(int $idProduct, string $libelleFormat): ?array
    {
        if ($idProduct <= 0) {
            return null;
        }

        $ligne = \Db::getInstance()->getRow(
            'SELECT `fichier`, `taille`, `format_libelle`, `date_upd`'
            . ' FROM `' . _DB_PREFIX_ . 'ekosync_gabarit`'
            . ' WHERE `id_product` = ' . $idProduct
            . " AND `format_cle` = '" . pSQL(self::cle($libelleFormat)) . "'"
        );

        if (!is_array($ligne) || ($ligne['fichier'] ?? '') === '') {
            return null;
        }

        $chemin = self::dossier() . '/' . $ligne['fichier'];

        // ⚠️ La ligne en base ne prouve pas que le fichier existe. Une
        // restauration partielle, une purge de `upload/`, et l'on servirait un
        // 404 en croyant servir un gabarit. On vérifie le disque.
        if (!is_file($chemin)) {
            return null;
        }

        return [
            'fichier' => (string) $ligne['fichier'],
            'chemin' => $chemin,
            'taille' => (int) $ligne['taille'],
            'libelle' => (string) $ligne['format_libelle'],
            'date' => (string) $ligne['date_upd'],
        ];
    }

    /**
     * Range un fichier reçu, et remplace le précédent s'il y en avait un.
     *
     * @param array<string,mixed> $fichier une entrée de `$_FILES`
     *
     * @return array{ok:bool,message:string}
     */
    public static function deposer(int $idProduct, string $libelleFormat, array $fichier): array
    {
        if ($idProduct <= 0 || trim($libelleFormat) === '') {
            return self::echec('Produit ou format manquant.');
        }

        $erreur = (int) ($fichier['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($erreur !== UPLOAD_ERR_OK) {
            return self::echec(self::messageErreur($erreur));
        }

        // ─── Validations d'entrée, AVANT le garde de sécurité ──────────────
        //
        // L'ordre a été inversé le 2026-08-13, pour deux raisons.
        //
        // 1. Le message. Un fichier de 40 Mo refusé par `is_uploaded_file`
        //    aurait dit « fichier non reçu » — vrai, mais inexploitable : le
        //    marchand aurait cherché une panne réseau au lieu de réduire son
        //    fichier.
        // 2. La testabilité. Le garde placé en tête rendait les contrôles de
        //    taille et d'extension INATTEIGNABLES en ligne de commande : ils
        //    passaient pour vérifiés alors qu'ils ne l'avaient jamais été.
        //
        // La sécurité n'y perd rien : ces contrôles ne lisent que des
        // métadonnées, et RIEN n'est écrit avant `is_uploaded_file`.
        $taille = (int) ($fichier['size'] ?? 0);

        if ($taille <= 0) {
            return self::echec('Fichier vide.');
        }

        if ($taille > self::TAILLE_MAX) {
            return self::echec(sprintf(
                'Fichier trop lourd (%s Mo). Maximum : %s Mo.',
                number_format($taille / 1048576, 1, ',', ' '),
                (int) (self::TAILLE_MAX / 1048576)
            ));
        }

        $extension = mb_strtolower((string) pathinfo((string) ($fichier['name'] ?? ''), PATHINFO_EXTENSION));

        if (!in_array($extension, self::EXTENSIONS, true)) {
            return self::echec('Extension refusée. Acceptées : ' . implode(', ', self::EXTENSIONS) . '.');
        }

        $temporaire = (string) ($fichier['tmp_name'] ?? '');

        // ⚠️ Le garde qui compte, juste avant d'écrire : sans lui, un chemin
        // fabriqué dans la requête ferait recopier n'importe quel fichier du
        // serveur — `/etc/passwd` compris — sous un nom que nous servirions
        // ensuite en téléchargement.
        if ($temporaire === '' || !is_uploaded_file($temporaire)) {
            return self::echec('Fichier non reçu par le serveur.');
        }

        // Le nom est FABRIQUÉ, jamais repris du client : un nom d'origine
        // porte des accents, des espaces, parfois « ../ ». On garde
        // l'extension, qui a été validée, et rien d'autre.
        $nom = sprintf('p%d-%s.%s', $idProduct, self::cle($libelleFormat), $extension);
        $destination = self::dossier() . '/' . $nom;

        $ancien = self::lire($idProduct, $libelleFormat);

        if (!@move_uploaded_file($temporaire, $destination)) {
            return self::echec('Écriture impossible dans le dossier de dépôt.');
        }

        @chmod($destination, 0644);

        // L'ancien fichier ne part qu'APRÈS que le nouveau soit écrit, et
        // seulement s'il porte un autre nom — un changement d'extension, par
        // exemple. Supprimer d'abord laisserait le produit sans gabarit si
        // l'écriture échouait.
        if ($ancien !== null && $ancien['fichier'] !== $nom) {
            @unlink($ancien['chemin']);
        }

        $db = \Db::getInstance();
        $ok = $db->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'ekosync_gabarit`'
            . ' (`id_product`, `format_cle`, `format_libelle`, `fichier`, `taille`, `date_upd`)'
            . ' VALUES (' . $idProduct
            . ", '" . pSQL(self::cle($libelleFormat)) . "'"
            . ", '" . pSQL($libelleFormat) . "'"
            . ", '" . pSQL($nom) . "'"
            . ', ' . $taille
            . ", '" . pSQL(date('Y-m-d H:i:s')) . "')"
            . ' ON DUPLICATE KEY UPDATE'
            . " `format_libelle` = VALUES(`format_libelle`),"
            . ' `fichier` = VALUES(`fichier`),'
            . ' `taille` = VALUES(`taille`),'
            . ' `date_upd` = VALUES(`date_upd`)'
        );

        if (!$ok) {
            @unlink($destination);

            return self::echec('Enregistrement refusé par la base.');
        }

        return ['ok' => true, 'message' => 'Gabarit enregistré.'];
    }

    /** Retire un gabarit déposé, fichier compris. */
    public static function retirer(int $idProduct, string $libelleFormat): bool
    {
        $existant = self::lire($idProduct, $libelleFormat);

        $supprime = \Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'ekosync_gabarit`'
            . ' WHERE `id_product` = ' . $idProduct
            . " AND `format_cle` = '" . pSQL(self::cle($libelleFormat)) . "'"
        );

        if ($existant !== null) {
            @unlink($existant['chemin']);
        }

        return (bool) $supprime;
    }

    /**
     * @return array{ok:false,message:string}
     */
    private static function echec(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }

    /**
     * Le message d'une erreur d'envoi PHP, en clair.
     *
     * « Erreur 1 » n'apprend rien au marchand. Or la cause la plus fréquente
     * — le fichier dépasse `upload_max_filesize` — se règle en changeant de
     * fichier, à condition de savoir que c'est la taille qui bloque.
     */
    private static function messageErreur(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Fichier trop lourd pour le serveur.',
            UPLOAD_ERR_PARTIAL => 'Envoi interrompu, fichier incomplet.',
            UPLOAD_ERR_NO_FILE => 'Aucun fichier sélectionné.',
            UPLOAD_ERR_NO_TMP_DIR => 'Dossier temporaire absent sur le serveur.',
            UPLOAD_ERR_CANT_WRITE => 'Écriture impossible sur le serveur.',
            UPLOAD_ERR_EXTENSION => 'Envoi bloqué par une extension PHP.',
            default => 'Envoi refusé (code ' . $code . ').',
        };
    }
}
