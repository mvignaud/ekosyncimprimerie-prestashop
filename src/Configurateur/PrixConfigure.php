<?php

/**
 * EKO Sync — Imprimerie
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

/**
 * Le prix d'une configuration, tel que l'ERP l'a calculé.
 *
 * ─── Pourquoi une table, et pas un appel dans le hook ──────────────────────
 *
 * PrestaShop demande le prix d'un produit BEAUCOUP plus souvent qu'on ne le
 * croit : jusqu'à huit fois pour une seule fiche produit, quatre fois par ligne
 * de panier. Appeler l'ERP depuis le hook de prix, avec un client HTTP à quinze
 * secondes de délai, rendrait la boutique inutilisable au premier incident
 * réseau.
 *
 * Le prix est donc calculé une fois, quand le visiteur change une option, par
 * un contrôleur qui appelle l'ERP et ENREGISTRE le résultat. Le hook, lui, ne
 * fait que lire — jamais d'entrée/sortie, jamais d'attente.
 *
 * ─── La clé ────────────────────────────────────────────────────────────────
 *
 * Un prix vaut pour UNE configuration ET UNE quantité : le chiffrage d'atelier
 * est dégressif, et 100 exemplaires ne coûtent pas cent fois un exemplaire. La
 * clé est donc le couple (customization, quantité). Se tromper là-dessus
 * afficherait le prix de la quantité précédente à chaque changement.
 *
 * `id_customization` est l'identité que PrestaShop donne à une configuration en
 * cours, et il TRAVERSE tout le parcours : la fiche produit (customization en
 * attente, `in_cart = 0`), le panier (`in_cart = 1`), puis la commande. C'est
 * ce qui permet au même prix de survivre du premier clic à la facture.
 */

declare(strict_types=1);

namespace Eko\SyncImprimerie\Configurateur;

class PrixConfigure
{
    /**
     * Enregistre le prix rendu par l'ERP pour une configuration et une quantité.
     *
     * @param  array<string,mixed>  $variables  la configuration soumise à l'ERP
     */
    public function memoriser(
        int $idCustomization,
        int $idProduct,
        int $quantite,
        int $prixHtCents,
        array $variables,
        ?int $delaiJours = null
    ): bool {
        if ($idCustomization <= 0 || $quantite <= 0) {
            return false;
        }

        $this->table();

        return (bool) \Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'ekosync_prix` '
            . '(`id_customization`, `id_product`, `quantity`, `price_ht_cents`, `variables`, `lead_days`, `date_upd`) '
            . 'VALUES ('
            . (int) $idCustomization . ', '
            . (int) $idProduct . ', '
            . (int) $quantite . ', '
            . (int) $prixHtCents . ', '
            . '"' . pSQL((string) json_encode($variables, JSON_UNESCAPED_UNICODE)) . '", '
            . ($delaiJours === null ? 'NULL' : (int) $delaiJours) . ', '
            . 'NOW()) '
            . 'ON DUPLICATE KEY UPDATE '
            . '`price_ht_cents` = ' . (int) $prixHtCents . ', '
            . '`variables` = "' . pSQL((string) json_encode($variables, JSON_UNESCAPED_UNICODE)) . '", '
            . '`lead_days` = ' . ($delaiJours === null ? 'NULL' : (int) $delaiJours) . ', '
            . '`date_upd` = NOW()'
        );
    }

    /**
     * Le prix HT en centimes pour ce couple, ou `null` si on ne l'a pas.
     *
     * `null` n'est pas une erreur : c'est le cas d'un produit qu'on ne configure
     * pas, ou d'une quantité que le visiteur vient de changer sans qu'un
     * chiffrage soit revenu. L'appelant doit alors laisser PrestaShop faire —
     * surtout pas inventer un prix.
     */
    public function lire(int $idCustomization, int $quantite): ?int
    {
        if ($idCustomization <= 0 || $quantite <= 0) {
            return null;
        }

        $valeur = \Db::getInstance()->getValue(
            'SELECT `price_ht_cents` FROM `' . _DB_PREFIX_ . 'ekosync_prix`'
            . ' WHERE `id_customization` = ' . (int) $idCustomization
            . ' AND `quantity` = ' . (int) $quantite
        );

        return ($valeur === false || $valeur === null || $valeur === '') ? null : (int) $valeur;
    }

    /** La configuration enregistrée pour cette customization, quantité comprise. */
    public function configuration(int $idCustomization, int $quantite): ?array
    {
        $ligne = \Db::getInstance()->getRow(
            'SELECT `variables`, `lead_days`, `price_ht_cents` FROM `' . _DB_PREFIX_ . 'ekosync_prix`'
            . ' WHERE `id_customization` = ' . (int) $idCustomization
            . ' AND `quantity` = ' . (int) $quantite
        );

        if (!is_array($ligne) || $ligne === []) {
            return null;
        }

        $variables = json_decode((string) $ligne['variables'], true);

        return [
            'variables' => is_array($variables) ? $variables : [],
            'lead_days' => $ligne['lead_days'] !== null ? (int) $ligne['lead_days'] : null,
            'price_ht_cents' => (int) $ligne['price_ht_cents'],
        ];
    }

    /**
     * Ce produit a-t-il une configuration en cours pour ce panier ?
     *
     * Le hook de prix se déclenche aussi pour les vignettes d'une liste et les
     * blocs « produits associés ». Sans cette question, la vignette d'un produit
     * configurable afficherait le prix personnel du visiteur hors contexte.
     */
    public function customizationEnCours(\Cart $panier, int $idProduct): int
    {
        if (!\Validate::isLoadedObject($panier) || $idProduct <= 0) {
            return 0;
        }

        // `true` en 3e argument : les customizations PAS ENCORE au panier —
        // c'est exactement ce que fait le contrôleur produit de PrestaShop.
        $en_cours = $panier->getProductCustomization($idProduct, null, true);

        if (!is_array($en_cours)) {
            return 0;
        }

        foreach ($en_cours as $c) {
            $id = (int) ($c['id_customization'] ?? 0);

            if ($id > 0) {
                return $id;
            }
        }

        return 0;
    }

    /**
     * Crée la table au premier usage.
     *
     * Comme la table des tiers, et pour la même raison : sur certains
     * hébergements `Module::install()` meurt avant d'avoir rien créé, et le
     * module se retrouve enregistré mais amputé sans que l'échec se voie.
     *
     * La clé primaire est le COUPLE (customization, quantité) : c'est ce qui
     * fait qu'un changement de quantité ne réécrit pas le prix de la
     * précédente, et qu'un `ON DUPLICATE KEY` rafraîchit la bonne ligne.
     */
    private function table(): void
    {
        \Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ekosync_prix` ('
            . '`id_customization` INT UNSIGNED NOT NULL,'
            . '`quantity` INT UNSIGNED NOT NULL,'
            . '`id_product` INT UNSIGNED NOT NULL,'
            . '`price_ht_cents` INT NOT NULL,'
            . '`variables` TEXT NULL,'
            . '`lead_days` INT UNSIGNED NULL,'
            . '`date_upd` DATETIME NOT NULL,'
            . 'PRIMARY KEY (`id_customization`, `quantity`),'
            . 'KEY `id_product` (`id_product`)'
            . ') ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
        );
    }

    /**
     * Pourquoi ce panier ne peut pas être commandé — ou null s'il le peut.
     *
     * ─── L'INVARIANT, ET LES TROIS FAÇONS DE LE VIOLER ────────────────────
     *
     * Un produit LIÉ à E-KO ne doit jamais être commandé à un prix qui ne
     * vient pas de E-KO. Trois routes y menaient, et le contrôle précédent
     * n'en voyait qu'une :
     *
     *   1. le prix mémorisé a vieilli au-delà du seuil ;
     *   2. la ligne n'a AUCUN prix pour sa quantité actuelle ;
     *   3. la ligne n'a aucune personnalisation — ajoutée sans passer par le
     *      configurateur.
     *
     * ⚠️ Le cas 2 échappait au contrôle PAR CONSTRUCTION. Son ancienne
     * requête joignait `cart_product` à `ekosync_prix` sur le couple
     * (id_customization, quantity) :
     *
     *     ON cp.id_customization = ep.id_customization
     *    AND cp.quantity        = ep.quantity
     *
     * Une ligne dont le client a changé la quantité au panier ne rejoint donc
     * plus rien — elle devient INVISIBLE au garde, alors que c'est exactement
     * celle qu'il faut retenir. `MAX()` sur les lignes restantes rendait un âge
     * rassurant, le garde laissait passer, et
     * `hookActionProductPriceCalculation()` renonçait de son côté faute de
     * prix mémorisé : PrestaShop facturait alors son prix de fiche. Sur une
     * fiche créée par le pont, ce prix vaut 0,00 €.
     *
     * La jointure est donc devenue une LEFT JOIN, et l'absence de
     * correspondance n'est plus un silence mais un motif.
     *
     * Mesuré sur la production le 2026-08-19 avant déploiement : 12 lignes de
     * panier portent un produit lié, 9 ont un prix frais, 0 en manquait pour
     * sa quantité, 3 étaient sans personnalisation (paniers invités
     * abandonnés). Ce garde ne retient donc aucun client réel aujourd'hui —
     * il ferme la porte avant que quelqu'un ne la pousse.
     *
     * @return null|'perime'|'sans_prix'
     */
    public function motifDeRefus(int $idCart, int $joursValidite): ?string
    {
        if ($idCart <= 0) {
            return null;
        }

        $lignes = \Db::getInstance()->executeS(
            'SELECT cp.`id_customization`, ep.`price_ht_cents`,'
            . ' TIMESTAMPDIFF(DAY, ep.`date_upd`, NOW()) AS `age`'
            . ' FROM `' . _DB_PREFIX_ . 'cart_product` cp'
            // La jointure sur ekosync_produit borne le contrôle aux fiches
            // LIÉES : un article ordinaire de la boutique n'a rien à voir ici.
            . ' JOIN `' . _DB_PREFIX_ . 'ekosync_produit` epr'
            . ' ON epr.`id_product` = cp.`id_product`'
            . ' LEFT JOIN `' . _DB_PREFIX_ . 'ekosync_prix` ep'
            . ' ON ep.`id_customization` = cp.`id_customization`'
            . ' AND ep.`quantity` = cp.`quantity`'
            . ' WHERE cp.`id_cart` = ' . (int) $idCart
        );

        if (!is_array($lignes) || $lignes === []) {
            return null;
        }

        $ageMax = null;

        foreach ($lignes as $l) {
            // Sans personnalisation, ou sans prix pour cette quantité : dans
            // les deux cas, rien ne relie cette ligne à un chiffrage de l'ERP.
            if ((int) $l['id_customization'] <= 0 || $l['price_ht_cents'] === null) {
                return 'sans_prix';
            }

            $age = (int) $l['age'];

            if ($ageMax === null || $age > $ageMax) {
                $ageMax = $age;
            }
        }

        return ($ageMax !== null && $ageMax >= $joursValidite) ? 'perime' : null;
    }
}
