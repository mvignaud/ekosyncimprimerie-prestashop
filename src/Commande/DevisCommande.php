<?php

/**
 * EKO Sync — Imprimerie
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

/**
 * Une commande de la boutique, traduite en devis pour l'ERP.
 *
 * ─── POURQUOI UN DEVIS, ET NON UNE COMMANDE ─────────────────────────────
 *
 * Le devis naît en BROUILLON : ni engagement, ni écriture comptable, ni
 * document envoyé au client. Un paiement par virement peut donc arriver
 * trois jours plus tard sans que rien n'ait été promis entre-temps, et une
 * commande abandonnée laisse un brouillon qui expire seul.
 *
 * ─── LA RÉFÉRENCE EXTERNE, ET POURQUOI ELLE PORTE UN PRÉFIXE ────────────
 *
 * Chaque ligne configurée porte `<prefixe>-C<id_customization>`.
 *
 * L'identifiant de configuration est le SEUL qui traverse le panier jusqu'à
 * la commande sans changer : PrestaShop le recopie dans la ligne de commande
 * (`OrderDetail::setDetail()`), alors qu'il SUPPRIME et RECRÉE les lignes
 * quand un opérateur modifie une commande — l'identifiant de ligne, lui,
 * changerait, et le fichier déjà déposé deviendrait orphelin.
 *
 * Le préfixe n'est pas décoratif. Cet identifiant est un compteur LOCAL à la
 * base d'une boutique : deux boutiques poseront un jour la même valeur. Or
 * la colonne qui l'accueille dans l'ERP n'a aucune contrainte d'unicité, et
 * le rattachement des fichiers cherche par simple égalité. Sans préfixe,
 * deux boutiques du même locataire s'échangeraient les fichiers de leurs
 * clients — le pire défaut possible sur cette fonction.
 *
 * ─── CE QU'ON N'ENVOIE PAS, ET POURQUOI ─────────────────────────────────
 *
 * L'API accepte `lines.*.variables` puis le JETTE : le service ne le lit
 * nulle part et la colonne n'existe pas. La configuration part donc en
 * `long_description`, qui est bien persistée. Y mettre `variables` aurait
 * produit un envoi qui semble réussir et perd la moitié de son contenu.
 */

declare(strict_types=1);

namespace Eko\SyncImprimerie\Commande;

use Eko\SyncImprimerie\Configurateur\PrixConfigure;

final class DevisCommande
{
    /** Longueur maximale de la référence externe, côté ERP. */
    private const REF_MAX = 64;

    /** Longueur maximale d'une description de ligne, côté ERP. */
    private const DESCRIPTION_MAX = 1000;

    /** Longueur maximale du détail d'une ligne, côté ERP. */
    private const DETAIL_MAX = 20000;

    /** Au-delà, l'ERP refuse le devis entier. */
    private const LIGNES_MAX = 200;

    public function __construct(private readonly PrixConfigure $prix) {}

    /**
     * La référence externe d'une ligne configurée, ou une chaîne vide.
     *
     * Une ligne sans configuration n'en porte pas : lui en inventer une
     * ferait attendre un fichier qui n'existera jamais.
     */
    public function reference(string $prefixe, int $idCustomization): string
    {
        if ($idCustomization <= 0) {
            return '';
        }

        $prefixe = preg_replace('/[^A-Za-z0-9_-]/', '', $prefixe) ?? '';
        $prefixe = $prefixe !== '' ? $prefixe : 'PS';

        return mb_substr($prefixe . '-C' . $idCustomization, 0, self::REF_MAX);
    }

    /**
     * La clé d'idempotence d'une commande.
     *
     * Dérivée de la commande, JAMAIS tirée au hasard : deux envois de la même
     * commande doivent porter la même clé, sinon l'ERP crée deux devis et
     * l'opérateur ne sait pas lequel honorer.
     */
    public function cleIdempotence(string $prefixe, int $idOrder): string
    {
        return mb_substr(($prefixe !== '' ? $prefixe : 'PS') . '-O' . $idOrder, 0, self::REF_MAX);
    }

    /**
     * Les lignes du devis, à partir des produits d'une commande.
     *
     * @param  list<array<string, mixed>>  $produits  le retour de Order::getProducts()
     * @return list<array<string, mixed>>
     */
    public function lignes(array $produits, string $prefixe): array
    {
        $lignes = [];

        foreach ($produits as $produit) {
            if (count($lignes) >= self::LIGNES_MAX) {
                break;
            }

            $quantite = (int) ($produit['product_quantity'] ?? 0);
            $idCustomization = (int) ($produit['id_customization'] ?? 0);

            $ligne = [
                'description' => mb_substr(trim((string) ($produit['product_name'] ?? '')), 0, self::DESCRIPTION_MAX),
                'quantity' => $quantite,
                // Le prix vient de la LIGNE DE COMMANDE, jamais d'un recalcul :
                // la boutique a déjà encaissé sur cette base, et un devis qui
                // annoncerait autre chose serait faux avant d'être lu.
                'unit_price_ht' => round((float) ($produit['unit_price_tax_excl'] ?? 0), 4),
                'unit' => 'u',
                'line_type' => 'product',
            ];

            if ($ligne['description'] === '') {
                $ligne['description'] = 'Article';
            }

            $reference = $this->reference($prefixe, $idCustomization);

            if ($reference !== '') {
                $ligne['external_ref'] = $reference;

                $configuration = $this->prix->configuration($idCustomization, $quantite);

                if ($configuration !== null) {
                    $ligne['long_description'] = $this->detail($configuration);

                    if ($configuration['lead_days'] !== null) {
                        $ligne['delay_days'] = max(0, min(400, (int) $configuration['lead_days']));
                    }
                }
            }

            $lignes[] = $ligne;
        }

        return $lignes;
    }

    /**
     * La configuration, écrite pour être LUE par un opérateur d'atelier.
     *
     * @param  array{variables: array<string, mixed>, lead_days: int|null, price_ht_cents: int}  $configuration
     */
    private function detail(array $configuration): string
    {
        $morceaux = [];

        foreach ($configuration['variables'] as $cle => $valeur) {
            if (is_array($valeur)) {
                $valeur = implode(', ', array_map(static fn ($v): string => (string) $v, $valeur));
            }

            if (is_bool($valeur)) {
                $valeur = $valeur ? 'oui' : 'non';
            }

            $valeur = trim((string) $valeur);

            if ($valeur === '') {
                continue;
            }

            $morceaux[] = (string) $cle . ' : ' . $valeur;
        }

        return mb_substr(implode("\n", $morceaux), 0, self::DETAIL_MAX);
    }
}
