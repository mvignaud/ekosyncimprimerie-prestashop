<?php

/**
 * EKO Sync — Imprimerie
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

/**
 * Les décisions que PrestaShop a déjà prises, et qu'il ne faut pas reprendre.
 *
 * ─── Pourquoi cette classe existe ──────────────────────────────────────────
 *
 * Le configurateur annonce un prix AVANT que le produit n'entre au panier. Si
 * les deux ne comptent pas de la même façon, le client voit un prix sur la
 * fiche et en paie un autre — le défaut le plus grave que ce module puisse
 * avoir, puisque toute sa raison d'être est que le prix affiché soit
 * exactement celui du devis.
 *
 * Or chaque décision fiscale a déjà une réponse dans PrestaShop : quel groupe
 * s'applique, quelle adresse porte la taxe, combien de décimales a la devise,
 * comment s'arrondit une ligne. Les redécider ici produit du code qui a l'air
 * juste et qui diverge sur les boutiques réglées autrement que la nôtre.
 *
 * Trois défauts de cette exacte famille ont été trouvés le jour de l'écriture,
 * tous verts sur la boutique de test et faux ailleurs :
 *
 *   — l'arrondi de ligne, figé sur une des TROIS formules de PrestaShop ;
 *   — la précision, supposée à deux décimales, donc fausse en yen ou en dinar ;
 *   — l'adresse fiscale, lue sur la facturation alors que la boutique taxait
 *     sur la livraison — le réglage par défaut de PrestaShop.
 *
 * Un module distribué ne peut se permettre aucun des trois. Chaque règle vit
 * donc ici, une seule fois, et se contrôle depuis `dev/verifier-tva.php`.
 */

declare(strict_types=1);

namespace Eko\SyncImprimerie\Configurateur;

final class ReglesBoutique
{
    /**
     * Le total d'une ligne, arrondi comme le panier l'arrondira.
     *
     * PrestaShop connaît TROIS façons d'arrondir, réglées par `PS_ROUND_TYPE`
     * et appliquées dans `src/Core/Cart/CartRow.php` (`applyRound()`) :
     *
     *   1 — par article : round(unitaire) × quantité
     *   2 — par ligne   : round(unitaire × quantité)      ← défaut PrestaShop
     *   3 — au total    : la ligne n'est pas arrondie du tout
     *
     * Les deux premières divergent dès que l'unitaire tombe entre deux
     * centimes. À 42,37 € HT et 20 % de TVA l'unitaire vaut 50,844 € : sur 500
     * exemplaires, « par article » donne 25 420,00 € et « par ligne »
     * 25 422,00 €. Deux euros entre la fiche et le panier.
     */
    public static function total(float $unitaire, int $quantite, int $precision): float
    {
        switch ((int) \Configuration::get('PS_ROUND_TYPE')) {
            case \Order::ROUND_LINE:
                return \Tools::ps_round($unitaire * $quantite, $precision);

            case \Order::ROUND_TOTAL:
                // La ligne n'est pas arrondie : l'arrondi se fera sur le total
                // du panier. Arrondir ici fabriquerait un écart là où la
                // boutique n'en fait aucun.
                return $unitaire * $quantite;

            case \Order::ROUND_ITEM:
            default:
                // `Cart::getOrderTotal()` retombe lui aussi sur « par article »
                // devant un réglage qu'il ne reconnaît pas.
                return \Tools::ps_round($unitaire, $precision) * $quantite;
        }
    }

    /**
     * Le nombre de décimales de la devise en cours.
     *
     * Deux décimales est une supposition d'euro. Le yen n'en a aucune, le
     * dinar en a trois : y arrondir fabriquerait un prix impossible. C'est
     * aussi la précision qu'emploie `Cart::getOrderTotal()`, donc la seule qui
     * garde le configurateur d'accord avec le panier.
     */
    public static function precision(): int
    {
        return (int) \Context::getContext()->getComputingPrecision();
    }

    /**
     * Arrondir un montant pour l'affichage.
     *
     * `Tools::ps_round()` et non `round()` : PrestaShop laisse le marchand
     * choisir son mode d'arrondi (`PS_PRICE_ROUND_MODE` — au plus proche, vers
     * le haut, vers le bas, ou bancaire). Le `round()` de PHP arrondit toujours
     * au plus proche en s'éloignant de zéro, et diverge donc du panier sur
     * toute boutique réglée autrement.
     */
    public static function montant(float $valeur, int $precision): float
    {
        return (float) \Tools::ps_round($valeur, $precision);
    }

    /**
     * Ce visiteur doit-il voir des prix hors taxes ?
     *
     * `Group::getCurrent()` donne le groupe qui s'applique VRAIMENT : celui du
     * client connecté, ou `PS_UNIDENTIFIED_GROUP` pour un visiteur de passage.
     *
     * Lire `customer->id_default_group` retombe sur zéro pour un anonyme, donc
     * sur « afficher TTC ». Juste par accident sur une boutique dont le groupe
     * visiteur est en TTC — faux sur toute boutique qui l'a réglé en HT, ce qui
     * est le cas courant en imprimerie B2B, précisément la clientèle visée par
     * ce module.
     */
    public static function afficheHorsTaxe(): bool
    {
        return (int) \Group::getPriceDisplayMethod((int) \Group::getCurrent()->id) === PS_TAX_EXC;
    }

    /**
     * L'adresse sur laquelle la boutique calcule sa taxe.
     *
     * `PS_TAX_ADDRESS_TYPE` décide si c'est celle de livraison ou celle de
     * facturation — livraison par défaut. Lire toujours la facturation ferait
     * diverger le prix affiché du prix facturé dès que les deux adresses ne
     * sont pas dans la même zone : Corse, outre-mer, ou simplement un client
     * européen livré ailleurs qu'à son siège.
     *
     * `Address::initialize()` et non `new Address(...)` : un visiteur qui n'a
     * pas encore d'adresse n'en a PAS, et `TaxManagerFactory` refuse `null`.
     * PrestaShop sait fabriquer l'adresse par défaut de la boutique — pays,
     * région — et c'est ce qu'il fait lui-même pour un panier anonyme.
     */
    public static function adresseFiscale(?\Cart $panier): \Address
    {
        return \Address::initialize(self::idAdresseFiscale($panier) ?: null, true);
    }

    /**
     * L'identifiant de cette adresse, isolé pour être contrôlable.
     */
    public static function idAdresseFiscale(?\Cart $panier): int
    {
        $champ = (string) \Configuration::get('PS_TAX_ADDRESS_TYPE');

        // Le cœur lit ce réglage sans le vérifier. Un module distribué n'a pas
        // ce luxe : une valeur inattendue en base ferait chercher une propriété
        // absente du panier. On retombe alors sur la livraison, défaut de
        // PrestaShop.
        if ($champ !== 'id_address_invoice' && $champ !== 'id_address_delivery') {
            $champ = 'id_address_delivery';
        }

        return $panier === null ? 0 : (int) ($panier->{$champ} ?? 0);
    }
}
