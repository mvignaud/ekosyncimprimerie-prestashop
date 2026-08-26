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
 * La correspondance entre les états de fabrication d'E-KO et les statuts de
 * commande de la boutique.
 *
 * ─── ELLE EST PARTIELLE, ET C'EST VOULU ────────────────────────────────────
 *
 * Trois statuts de la boutique n'ont AUCUNE source dans le workflow de
 * production, et il serait faux d'en inventer une :
 *
 *   « Fichiers reçus »          -> événement d'ARRIVÉE d'un fichier client,
 *                                  pas un état de fabrication ;
 *   « Fichiers non conformes »  -> VERDICT du préflight, idem ;
 *   « En attente de collecte »  -> E-KO ne distingue rien entre `packaging`
 *                                  et `shipped`.
 *
 * Ces trois-là se posent donc autrement — par l'événement qui les provoque, ou
 * à la main tant que l'atelier n'a pas de quoi les émettre. Les faire porter
 * par un état voisin donnerait au client une information fausse, et c'est pire
 * que pas d'information.
 *
 * ─── CE QUI N'EST DÉLIBÉRÉMENT PAS RETENU ──────────────────────────────────
 *
 *   `created`     : la commande boutique existe déjà, elle est payée ;
 *   `installation`: pose sur site, sans objet pour une commande en ligne ;
 *   `invoiced`    : la boutique a émis sa facture à l'encaissement ;
 *   `closed`      : clôture interne, le client n'a rien à en savoir.
 *
 * Un état inconnu ou non retenu ne change RIEN et ne lève PAS d'erreur : E-KO
 * doit pouvoir avancer dans son workflow sans que la boutique s'y oppose.
 */
final class CorrespondanceEtats
{
    /**
     * État de fabrication E-KO => clé de configuration du statut boutique.
     *
     * On passe par une clé de configuration plutôt que par un identifiant en
     * dur : les statuts ont été créés par script, et leurs identifiants
     * dépendent de ce qui existait déjà dans la boutique. Les écrire ici
     * casserait à la première réinstallation.
     *
     * @var array<string,string>
     */
    private const TABLE = [
        'preparation' => 'EKOSYNC_ETAT_FICHIERS_TRAITES',
        'bat_pending' => 'EKOSYNC_ETAT_BAT',
        'bat_validated' => 'EKOSYNC_ETAT_FICHIERS_TRAITES',
        'in_production' => 'EKOSYNC_ETAT_PRODUCTION',
        // `finishing` — façonnage — reste « en production » pour le client :
        // la nuance entre impression et façonnage est un détail d'atelier.
        'finishing' => 'EKOSYNC_ETAT_PRODUCTION',
        'packaging' => 'EKOSYNC_ETAT_COLIS',
        'shipped' => 'EKOSYNC_ETAT_TRANSIT',
        'delivered' => 'EKOSYNC_ETAT_LIVREE',
    ];

    /**
     * Les événements, qui ne viennent pas du workflow de production.
     *
     * @var array<string,string>
     */
    private const EVENEMENTS = [
        'files_received' => 'EKOSYNC_ETAT_FICHIERS_RECUS',
        'files_rejected' => 'EKOSYNC_ETAT_FICHIERS_NON_CONFORMES',
        'awaiting_pickup' => 'EKOSYNC_ETAT_COLLECTE',
    ];

    /**
     * Le statut boutique correspondant, ou `null` si l'état n'est pas retenu.
     */
    public static function statutBoutique(string $etatEko): ?int
    {
        $cle = self::TABLE[$etatEko] ?? self::EVENEMENTS[$etatEko] ?? null;

        if ($cle === null) {
            return null;
        }

        $id = (int) \Configuration::get($cle);

        // Une clé de configuration absente rend 0. On refuse plutôt que de
        // renvoyer l'état 0, qui n'existe pas et ferait échouer l'historique
        // avec un message incompréhensible.
        return $id > 0 ? $id : null;
    }

    /** Tous les états reconnus, pour la documentation et les contrôles. */
    public static function etatsReconnus(): array
    {
        return array_merge(array_keys(self::TABLE), array_keys(self::EVENEMENTS));
    }

    /**
     * Retrouve les identifiants des statuts par leur NOM français et les range
     * en configuration. Idempotent.
     *
     * @return array<string,int> clé de configuration => identifiant trouvé
     */
    public static function reperer(): array
    {
        $noms = [
            'EKOSYNC_ETAT_FICHIERS_RECUS' => 'Fichiers reçus',
            'EKOSYNC_ETAT_FICHIERS_TRAITES' => 'Fichiers traités',
            'EKOSYNC_ETAT_PRODUCTION' => 'Production en cours',
            'EKOSYNC_ETAT_FICHIERS_NON_CONFORMES' => 'Fichiers non conformes (à renvoyer)',
            'EKOSYNC_ETAT_BAT' => 'BAT à valider',
            'EKOSYNC_ETAT_COLIS' => 'Préparation de colis',
            'EKOSYNC_ETAT_COLLECTE' => 'En attente de collecte transporteur',
            'EKOSYNC_ETAT_TRANSIT' => 'En cours de transit',
            'EKOSYNC_ETAT_LIVREE' => 'Livrée',
        ];

        $trouves = [];
        $idLang = (int) \Configuration::get('PS_LANG_DEFAULT');

        foreach ($noms as $cle => $nom) {
            $id = (int) \Db::getInstance()->getValue(
                'SELECT os.id_order_state FROM `' . _DB_PREFIX_ . 'order_state` os'
                . ' JOIN `' . _DB_PREFIX_ . 'order_state_lang` osl'
                . '   ON osl.id_order_state = os.id_order_state AND osl.id_lang = ' . $idLang
                . " WHERE osl.name = '" . pSQL($nom) . "' AND os.deleted = 0"
                . ' ORDER BY os.id_order_state ASC'
            );

            if ($id > 0) {
                \Configuration::updateValue($cle, $id);
                $trouves[$cle] = $id;
            }
        }

        return $trouves;
    }
}
