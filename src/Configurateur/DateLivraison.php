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
 * La date de livraison estimée, à partir d'un nombre de jours ouvrés.
 *
 * ─── POURQUOI ELLE SE CALCULE, ET NE SE STOCKE PAS ─────────────────────────
 *
 * Une date rangée en base est fausse le lendemain. Un client qui laisse son
 * panier trois jours verrait une livraison annoncée pour une date déjà passée
 * — et cette date finit sur la commande. Ce qui se mémorise, c'est le DÉLAI
 * (« J+5 », cinq jours ouvrés) ; la date s'en déduit à chaque affichage.
 *
 * ─── JOURS OUVRÉS ──────────────────────────────────────────────────────────
 *
 * Le fournisseur compte en jours ouvrés : samedi et dimanche ne comptent pas.
 * Les jours fériés, eux, ne sont PAS traités — il faudrait un calendrier
 * français maintenu, et se tromper d'un jour sur un pont vaut mieux que
 * promettre une livraison le 1er mai. La mention reste une ESTIMATION.
 */
final class DateLivraison
{
    /**
     * La date atteinte après ce nombre de jours ouvrés, en toutes lettres.
     *
     * @param int    $jours  nombre de jours ouvrés ; 0 ou moins rend une chaîne vide
     * @param string $locale « fr-FR », pour le nom du jour et du mois
     */
    public static function dans(int $jours, string $locale = 'fr-FR'): string
    {
        if ($jours <= 0) {
            return '';
        }

        $date = new \DateTimeImmutable('today');

        while ($jours > 0) {
            $date = $date->modify('+1 day');

            // 6 = samedi, 7 = dimanche.
            if ((int) $date->format('N') <= 5) {
                --$jours;
            }
        }

        // ⚠️ `getCurrentLocale()` de PrestaShop met en forme des NOMBRES, pas
        // des dates : il n'a pas de `getDateTimeFormatter()`. L'appel levait
        // systématiquement, et le repli chiffré s'affichait toujours — un repli
        // qui marche trop bien cache le défaut qu'il couvre.
        if (class_exists('\IntlDateFormatter')) {
            $formateur = new \IntlDateFormatter(
                $locale,
                \IntlDateFormatter::FULL,
                \IntlDateFormatter::NONE,
                null,
                null,
                'EEEE d MMMM'
            );

            $texte = $formateur->format($date);

            if (is_string($texte) && $texte !== '') {
                return $texte;
            }
        }

        return $date->format('d/m/Y');
    }

    /**
     * Le délai d'UNE ligne de panier, en jours ouvrés.
     *
     * C'est la bonne maille : deux articles d'une même commande peuvent avoir
     * des délais différents — un roll-up en J+5 et des flyers en J+2 — et
     * n'afficher qu'une date pour l'ensemble reviendrait à annoncer au client
     * la plus lente pour tout, ou pire, la plus rapide.
     */
    public static function delaiDeLigne(int $idCustomization): int
    {
        if ($idCustomization <= 0) {
            return 0;
        }

        $valeur = \Db::getInstance()->getValue(
            'SELECT lead_days FROM `' . _DB_PREFIX_ . 'ekosync_prix`'
            . ' WHERE id_customization = ' . (int) $idCustomization
        );

        return $valeur === false || $valeur === null ? 0 : (int) $valeur;
    }

    /**
     * Le délai le plus long d'un panier, en jours ouvrés.
     *
     * Une commande part quand son article le plus lent est prêt : c'est donc
     * le MAXIMUM qui fait la date, jamais la moyenne ni le premier trouvé.
     */
    public static function delaiDuPanier(int $idCart): int
    {
        if ($idCart <= 0) {
            return 0;
        }

        // On joint sur les personnalisations RÉELLEMENT dans ce panier :
        // `ekosync_prix` conserve aussi des lignes de configurations
        // abandonnées, et les compter annoncerait un délai que rien ne commande.
        $valeur = \Db::getInstance()->getValue(
            'SELECT MAX(p.lead_days) FROM `' . _DB_PREFIX_ . 'ekosync_prix` p'
            . ' JOIN `' . _DB_PREFIX_ . 'customization` c'
            . ' ON c.id_customization = p.id_customization'
            . ' WHERE c.id_cart = ' . (int) $idCart
            . ' AND c.in_cart = 1'
        );

        return $valeur === false || $valeur === null ? 0 : (int) $valeur;
    }
}
