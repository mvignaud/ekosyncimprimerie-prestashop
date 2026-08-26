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

use Eko\SyncImprimerie\Configurateur\Personnalisation;

/**
 * La commande de la boutique devient un devis dans l'ERP.
 *
 * ─── LE MAILLON QUI MANQUAIT ───────────────────────────────────────────────
 *
 * Tout le reste de la chaîne existait : le client dépose ses fichiers, ils
 * partent sur le S3, le préflight rend son verdict, et E-KO sait rattacher un
 * dépôt à une ligne de fabrication par sa référence externe. Mais RIEN ne
 * poussait la commande. Les fichiers restaient donc en transit indéfiniment,
 * et l'atelier ne voyait jamais passer une commande de la boutique.
 *
 * ─── POURQUOI UN DEVIS, ET NON UN DOSSIER DE FABRICATION ───────────────────
 *
 * Parce que c'est l'atelier qui décide de fabriquer. Un devis accepté DONNE un
 * dossier — c'est le geste qui appelle `createFromProposal()`, lequel rattache
 * au passage les fichiers déjà déposés. Créer le dossier directement
 * court-circuiterait la seule étape où un humain regarde ce qui a été commandé.
 *
 * ─── ⚠️ CE SERVICE NE DOIT JAMAIS FAIRE ÉCHOUER UNE COMMANDE ───────────────
 *
 * Il est appelé pendant la validation de commande. Une exception qui remonte
 * ferait perdre au client une commande qu'il vient de payer, pour une panne
 * qui ne le concerne pas. Tout échec est donc consigné et rendu, jamais levé —
 * et la trace permet de rejouer.
 *
 * ─── LA RÉFÉRENCE QUI RELIE TOUT ───────────────────────────────────────────
 *
 * `external_ref` porte l'identifiant de personnalisation PrestaShop. C'est le
 * SEUL identifiant qui traverse tout le parcours : il existe avant la commande
 * (le client dépose depuis son panier), il lui survit, et il est le même des
 * deux côtés du pont.
 */
final class PousseeCommande
{
    public function __construct(
        private readonly ClientEko $client,
        private readonly ImportTiers $tiers,
    ) {
    }

    /** Crée la table de suivi si elle manque. Idempotent. */
    public static function installer(): bool
    {
        return (bool) \Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ekosync_commande` ('
            . ' `id_order` INT UNSIGNED NOT NULL,'
            . ' `proposal_id` INT UNSIGNED NULL,'
            // ⚠️ LE CODE, ET PAS SEULEMENT L'IDENTIFIANT. L'API d'E-KO lie un
            // devis par son CODE (`Proposal::getRouteKeyName()` rend `code`) :
            // l'URL du PDF s'écrit avec lui. Ne garder que l'identifiant
            // numérique obligerait à un appel de plus pour le retrouver, à
            // chaque téléchargement.
            . ' `proposal_code` VARCHAR(64) NULL,'
            . ' `statut` VARCHAR(16) NOT NULL DEFAULT "pending",'
            // Le motif d'échec, en clair. C'est lui qui permet de savoir s'il
            // faut rejouer ou corriger — un état « échec » sans motif oblige à
            // tout reprendre à la main.
            . ' `motif` VARCHAR(500) NULL,'
            . ' `tentatives` SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
            . ' `date_add` DATETIME NOT NULL,'
            . ' `date_upd` DATETIME NOT NULL,'
            . ' PRIMARY KEY (`id_order`),'
            . ' KEY `statut` (`statut`)'
            . ') ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * La prochaine commande dont la poussée a échoué et mérite une reprise.
     *
     * ─── POURQUOI UNE REPRISE EXISTE ──────────────────────────────────────
     *
     * La poussée n'avait lieu qu'à la validation de la commande. Si l'ERP
     * était injoignable à cet instant — jeton révoqué, panne, lenteur — le
     * motif partait au journal et PLUS RIEN ne se produisait. La boutique
     * encaissait, l'atelier ne voyait jamais le dossier, et personne n'était
     * prévenu. Le 2026-08-19, l'ERP a été indisponible plusieurs heures :
     * toute commande passée pendant cette fenêtre aurait été orpheline.
     *
     * Les bornes sont volontairement étroites : ce filet ne doit jamais peser
     * sur une page vue par un client.
     *
     *   - `$ageSecondes` laisse passer l'échec récent — la validation vient
     *     d'essayer, réessayer aussitôt reproduirait la même panne ;
     *   - `$maxTentatives` arrête l'acharnement : au-delà, c'est un défaut à
     *     regarder, pas un aléa de réseau ;
     *   - une seule commande par passage.
     *
     * On ne reprend QUE le statut `echec`. Un `ignore` est une décision — la
     * commande ne contenait rien à fabriquer — et la rejouer ne changerait
     * rien.
     */
    public static function enAttente(int $ageSecondes = 120, int $maxTentatives = 6): ?int
    {
        $v = \Db::getInstance()->getValue(
            'SELECT `id_order` FROM `' . _DB_PREFIX_ . 'ekosync_commande`'
            . ' WHERE `statut` = "echec"'
            . ' AND `proposal_id` IS NULL'
            . ' AND `tentatives` < ' . max(1, $maxTentatives)
            . ' AND `date_upd` < DATE_SUB(NOW(), INTERVAL ' . max(1, $ageSecondes) . ' SECOND)'
            . ' ORDER BY `date_upd` ASC'
        );

        return $v ? (int) $v : null;
    }

    /**
     * Pousse une commande, ou rend son devis s'il existe déjà.
     *
     * @return array{ok: bool, proposal_id?: int, message?: string}
     */
    public function pousser(\Order $commande): array
    {
        $idOrder = (int) $commande->id;

        if ($idOrder <= 0) {
            return ['ok' => false, 'message' => 'Commande sans identifiant.'];
        }

        // ⚠️ IDEMPOTENCE. `actionValidateOrder` peut être rejoué — un module de
        // paiement qui revalide, une reprise manuelle — et une seconde poussée
        // créerait un second devis pour la même commande. L'atelier verrait
        // deux fois le même travail.
        $deja = \Db::getInstance()->getRow(
            'SELECT `proposal_id`, `proposal_code` FROM `' . _DB_PREFIX_ . 'ekosync_commande`'
            . ' WHERE `id_order` = ' . $idOrder
            . ' AND `proposal_id` IS NOT NULL'
        );

        if (is_array($deja) && (int) ($deja['proposal_id'] ?? 0) > 0) {
            return [
                'ok' => true,
                'proposal_id' => (int) $deja['proposal_id'],
                'proposal_code' => (string) ($deja['proposal_code'] ?? ''),
            ];
        }

        $lignes = $this->lignes($commande);

        if ($lignes === []) {
            // Une commande sans ligne configurée n'intéresse pas l'atelier :
            // ce n'est pas un imprimé sur mesure. On le consigne pour ne pas
            // la reprendre à chaque passage.
            $this->consigner($idOrder, null, 'ignore', 'Aucune ligne configurée.');

            return ['ok' => true, 'message' => 'Aucune ligne configurée.'];
        }

        // ⚠️ RETROUVER OU CRÉER, plus seulement retrouver.
        //
        // `tierDe()` ne lisait qu'une correspondance locale, alimentée par
        // l'import descendant. Un client qui commande en ligne sans exister
        // dans l'ERP n'en avait donc pas, et sa commande mourait ici : la
        // boutique encaissait, l'atelier ne voyait rien. Mesuré sur la
        // commande #11 le 2026-08-20.
        //
        // `tierPour()` cherche d'abord la correspondance, puis l'adresse dans
        // l'ERP — un client a pu commander au comptoir avant d'ouvrir un
        // compte, et créer un doublon couperait son historique — et ne crée
        // qu'en dernier recours.
        $client = new \Customer((int) $commande->id_customer);
        $resolution = $this->tiers->tierPour($client);

        if (!($resolution['ok'] ?? false)) {
            return $this->echec($idOrder, (string) ($resolution['message'] ?? 'Tiers E-KO introuvable.'));
        }

        $tier = (int) $resolution['tier_id'];

        $reponse = $this->client->appeler('POST', '/api/v1/proposals', [
            'tier_id' => $tier,
            // La référence de la commande, telle que le client la connaît :
            // c'est par elle que l'atelier et le client parleront du même
            // objet au téléphone.
            'reference' => (string) $commande->reference,
            'title' => 'Commande boutique ' . $commande->reference,
            'currency' => $this->devise($commande),
            'issue_date' => date('Y-m-d', strtotime((string) $commande->date_add) ?: time()),
            'lines' => $lignes,
        ]);

        if (!$reponse['ok']) {
            $detail = $reponse['donnees']['message'] ?? ($reponse['erreur'] ?? '');

            return $this->echec($idOrder, $detail !== '' ? (string) $detail : 'Refus de l’ERP.');
        }

        $proposalId = (int) ($reponse['donnees']['data']['id'] ?? 0);
        $proposalCode = (string) ($reponse['donnees']['data']['code'] ?? '');

        if ($proposalId <= 0) {
            return $this->echec($idOrder, 'L’ERP n’a pas rendu d’identifiant de devis.');
        }

        if ($proposalCode === '') {
            // Sans code, le document ne serait pas téléchargeable : c'est lui
            // que l'URL du PDF porte. Mieux vaut le dire tout de suite que de
            // découvrir un 404 le jour où un client clique.
            return $this->echec($idOrder, 'L’ERP n’a pas rendu de code de devis.');
        }

        $this->consigner($idOrder, $proposalId, 'ok', null, $proposalCode);

        return ['ok' => true, 'proposal_id' => $proposalId, 'proposal_code' => $proposalCode];
    }

    /**
     * Les lignes de la commande, en lignes de devis.
     *
     * @return array<int,array<string,mixed>>
     */
    private function lignes(\Order $commande): array
    {
        $module = \Module::getInstanceByName('ekosyncimprimerie');
        $lignes = [];

        foreach ($commande->getProducts() as $produit) {
            $idCustomization = (int) ($produit['id_customization'] ?? 0);

            // Sans personnalisation, ce n'est pas un imprimé configuré : rien
            // à fabriquer, rien à rattacher.
            if ($idCustomization <= 0) {
                continue;
            }

            $criteres = ($module && method_exists($module, 'criteresDeLignePublic'))
                ? $module->criteresDeLignePublic($idCustomization)
                : [];

            $lignes[] = [
                'line_type' => 'product',
                'description' => mb_substr((string) ($produit['product_name'] ?? 'Imprimé'), 0, 1000),
                // ⚠️ LA CONFIGURATION ET LE COMMENTAIRE VONT DANS LA LONGUE
                // DESCRIPTION, pas dans les variables. C'est elle que le
                // dossier de fabrication recopie tel quel, et donc ce que
                // l'atelier lit sur sa fiche suiveuse.
                'long_description' => $this->detail($idCustomization, $criteres),
                'quantity' => max(1, (int) ($produit['product_quantity'] ?? 1)),
                'unit_price_ht' => (float) ($produit['unit_price_tax_excl'] ?? 0),
                'vat_rate' => $this->tauxTva($produit),
                // Le fil qui relie le fichier déjà déposé à sa future ligne de
                // fabrication. Sans lui, tout le reste de la chaîne est inerte.
                'external_ref' => (string) $idCustomization,
            ];
        }

        return $lignes;
    }

    /**
     * La configuration lisible, suivie du commentaire s'il y en a un.
     *
     * @param array<string,string> $criteres
     */
    private function detail(int $idCustomization, array $criteres): string
    {
        $bouts = [];

        foreach ($criteres as $cle => $valeur) {
            $bouts[] = $cle . ' : ' . $valeur;
        }

        $texte = implode("\n", $bouts);

        $commentaire = $this->commentaire($idCustomization);

        if ($commentaire !== '') {
            // Séparé et ANNONCÉ : une consigne du client noyée dans la liste
            // des critères se lit comme une option de fabrication.
            $texte .= ($texte === '' ? '' : "\n\n") . 'Consigne du client : ' . $commentaire;
        }

        return mb_substr($texte, 0, 20000);
    }

    private function commentaire(int $idCustomization): string
    {
        return (string) \Db::getInstance()->getValue(
            'SELECT cd.`value` FROM `' . _DB_PREFIX_ . 'customized_data` cd'
            . ' JOIN `' . _DB_PREFIX_ . 'customization_field` cf'
            . ' ON cf.`id_customization_field` = cd.`index` AND cf.`is_deleted` = 0'
            . ' JOIN `' . _DB_PREFIX_ . 'customization_field_lang` cfl'
            . ' ON cfl.`id_customization_field` = cd.`index`'
            . ' WHERE cd.`id_customization` = ' . $idCustomization
            . ' AND cd.`type` = ' . (int) \Product::CUSTOMIZE_TEXTFIELD
            . " AND cfl.`name` = '" . pSQL(Personnalisation::CHAMP_COMMENTAIRE) . "'"
            . ' ORDER BY cd.`index` ASC'
        );
    }

    /**
     * @param array<string,mixed> $produit
     */
    private function tauxTva(array $produit): float
    {
        $ht = (float) ($produit['total_price_tax_excl'] ?? 0);
        $ttc = (float) ($produit['total_price_tax_incl'] ?? 0);

        if ($ht <= 0) {
            return 0.0;
        }

        // Déduit des montants FIGÉS de la commande, et non relu du catalogue :
        // le taux d'un produit peut changer après coup, la commande non.
        return round((($ttc - $ht) / $ht) * 100, 2);
    }

    private function devise(\Order $commande): string
    {
        $devise = new \Currency((int) $commande->id_currency);

        return \Validate::isLoadedObject($devise) ? (string) $devise->iso_code : 'EUR';
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function echec(int $idOrder, string $motif): array
    {
        $this->consigner($idOrder, null, 'echec', $motif);

        \PrestaShopLogger::addLog(
            'ekosyncimprimerie — commande non poussée vers E-KO : ' . mb_substr($motif, 0, 400),
            2,
            null,
            'Order',
            $idOrder
        );

        return ['ok' => false, 'message' => $motif];
    }

    private function consigner(int $idOrder, ?int $proposalId, string $statut, ?string $motif, ?string $code = null): void
    {
        $maintenant = date('Y-m-d H:i:s');

        \Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'ekosync_commande`'
            . ' (`id_order`, `proposal_id`, `proposal_code`, `statut`, `motif`, `tentatives`, `date_add`, `date_upd`)'
            . ' VALUES (' . $idOrder
            . ', ' . ($proposalId === null ? 'NULL' : $proposalId)
            . ', ' . ($code === null || $code === '' ? 'NULL' : "'" . pSQL($code) . "'")
            . ", '" . pSQL($statut) . "'"
            . ', ' . ($motif === null ? 'NULL' : "'" . pSQL(mb_substr($motif, 0, 500)) . "'")
            . ", 1, '" . pSQL($maintenant) . "', '" . pSQL($maintenant) . "')"
            . ' ON DUPLICATE KEY UPDATE'
            . ' `proposal_id` = VALUES(`proposal_id`),'
            . ' `proposal_code` = VALUES(`proposal_code`),'
            . ' `statut` = VALUES(`statut`),'
            . ' `motif` = VALUES(`motif`),'
            . ' `tentatives` = `tentatives` + 1,'
            . ' `date_upd` = VALUES(`date_upd`)'
        );
    }
}
