<?php

/**
 * EKO Sync — Imprimerie
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

/**
 * Combien de tarifs sur mesure un visiteur peut faire CALCULER.
 *
 * ─── ⚠️ POURQUOI CETTE CLASSE EXISTE ───────────────────────────────────────
 *
 * Un tarif sur mesure ne se lit pas : il se CALCULE, en jouant le calculateur
 * du fournisseur dans un navigateur sans interface. Dix-sept secondes de
 * machine, sur le serveur qui héberge aussi l'ERP et sa file de travaux.
 *
 * Or l'adresse du relais est PUBLIQUE et ne porte aucun jeton. Mesuré : une
 * simple boucle `curl` depuis une adresse anonyme occupait la file en
 * permanence — de l'ordre de deux cents calculs à l'heure — et chaque cote
 * fantaisiste écrit durablement dans la table des tarifs. Rien ne bornait quoi
 * que ce soit : ni l'adresse, ni la session, ni le produit.
 *
 * ─── CE QU'ON COMPTE, ET CE QU'ON NE COMPTE PAS ────────────────────────────
 *
 * On ne compte QUE les calculs réellement déclenchés. Une cote déjà mesurée
 * répond instantanément et ne coûte rien : la compter punirait le visiteur qui
 * explore les formats proposés, c'est-à-dire l'usage normal.
 *
 * Le compteur est donc incrémenté APRÈS coup, quand l'ERP a répondu « en
 * attente ». Un visiteur obtient ainsi son quota de calculs, puis se voit
 * proposer un devis — jamais une page muette.
 *
 * ─── POURQUOI UNE TABLE, ET PAS LE CACHE ───────────────────────────────────
 *
 * Le cache de PrestaShop n'est pas garanti persistant d'une requête à l'autre
 * sur un mutualisé : un compteur qui s'oublie ne compte rien. Une table tient,
 * elle se purge d'elle-même, et elle survit à un redémarrage de PHP.
 */

declare(strict_types=1);

namespace Eko\SyncImprimerie\Configurateur;

class DebitDevis
{
    /**
     * Combien de calculs par fenêtre, et pour quelle durée.
     *
     * Six calculs par quart d'heure : un visiteur qui cherche vraiment sa cote
     * en essaie deux ou trois, rarement plus. Au-delà, ce n'est plus une
     * recherche, et le devis reste ouvert.
     */
    private const CALCULS_MAX = 6;

    private const FENETRE_SECONDES = 900;

    /** Ce visiteur peut-il encore faire calculer un tarif ? */
    public function autorise(string $empreinte): bool
    {
        $this->table();
        $this->purger();

        return $this->compte($empreinte) < self::CALCULS_MAX;
    }

    /** Un calcul vient d'être déclenché pour ce visiteur. */
    public function compter(string $empreinte): void
    {
        $this->table();

        \Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'ekosync_debit_devis`'
            . ' (`empreinte`, `fenetre`, `calculs`) VALUES ('
            . '"' . pSQL($empreinte) . '", ' . $this->fenetre() . ', 1)'
            . ' ON DUPLICATE KEY UPDATE `calculs` = `calculs` + 1'
        );
    }

    /**
     * De quoi reconnaître un visiteur, sans le pister.
     *
     * ⚠️ L'ADRESSE EST HACHÉE, jamais rangée en clair. Une table de débit n'a
     * pas besoin de savoir QUI : elle a besoin de savoir si c'est le même. Et
     * une adresse IP est une donnée personnelle — la conserver en clair pour
     * un compteur serait la conserver sans raison.
     */
    public function empreinte(): string
    {
        return substr(hash('sha256', (string) \Tools::getRemoteAddr() . '|ekosync-devis'), 0, 32);
    }

    private function compte(string $empreinte): int
    {
        return (int) \Db::getInstance()->getValue(
            'SELECT `calculs` FROM `' . _DB_PREFIX_ . 'ekosync_debit_devis`'
            . ' WHERE `empreinte` = "' . pSQL($empreinte) . '" AND `fenetre` = ' . $this->fenetre()
        );
    }

    /** Le numéro de la fenêtre courante : un entier qui change tout seul. */
    private function fenetre(): int
    {
        return (int) floor(time() / self::FENETRE_SECONDES);
    }

    /**
     * Les fenêtres passées ne servent plus à rien.
     *
     * Une purge à chaque appel coûterait une écriture par requête. Une chance
     * sur cinquante suffit : la table ne dépasse jamais quelques centaines de
     * lignes, et personne n'attend qu'elle soit vide.
     */
    private function purger(): void
    {
        if (random_int(1, 50) !== 1) {
            return;
        }

        \Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'ekosync_debit_devis`'
            . ' WHERE `fenetre` < ' . ($this->fenetre() - 1)
        );
    }

    /** Le schéma est vérifié UNE FOIS par requête, comme pour les liaisons. */
    private static bool $vue = false;

    private function table(): void
    {
        if (self::$vue) {
            return;
        }

        self::$vue = true;

        \Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ekosync_debit_devis` ('
            . '`empreinte` CHAR(32) NOT NULL,'
            . '`fenetre` INT UNSIGNED NOT NULL,'
            . '`calculs` SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'PRIMARY KEY (`empreinte`, `fenetre`),'
            . 'KEY `fenetre` (`fenetre`)'
            . ') ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
        );
    }
}
