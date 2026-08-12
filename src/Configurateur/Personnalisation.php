<?php

/**
 * EKO Sync — Imprimerie
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

/**
 * L'identité d'une configuration, du premier clic jusqu'à la facture.
 *
 * ─── LE FIL QUI PORTE LE PRIX ──────────────────────────────────────────────
 *
 * Un configurateur affiche un prix que PrestaShop ne sait pas calculer. Pour
 * que ce prix survive à l'ajout au panier, il faut que la boutique ait un
 * moyen de désigner LA configuration à laquelle il se rapporte. PrestaShop
 * appelle cela une « customization » : elle naît sur la fiche produit avec
 * `in_cart = 0`, bascule à 1 au passage au panier, et son identifiant est
 * recopié dans la ligne de commande.
 *
 * ─── POURQUOI CETTE CLASSE EXISTE ──────────────────────────────────────────
 *
 * La chaîne était écrite en privé dans le contrôleur de chiffrage d'atelier.
 * Le contrôleur de sous-traitance, écrit ensuite, ne l'avait pas — et le
 * défaut ne se voyait nulle part : le configurateur affichait 68,04 €, la
 * ligne au panier valait 0,00 €. Mesuré en production.
 *
 * Deux chemins qui mènent au même panier doivent partager la même définition,
 * sans quoi le second oublie toujours quelque chose que le premier savait.
 *
 * ─── LE CHAMP DE PERSONNALISATION ──────────────────────────────────────────
 *
 * `addTextFieldToProduct()` refuse si le produit n'a pas de champ texte
 * déclaré — et rend alors `0`, sans erreur. C'était la racine : la fiche
 * n'était pas personnalisable, donc aucune configuration ne pouvait être
 * identifiée, donc aucun prix ne pouvait être retenu.
 *
 * On crée donc le champ à la demande. `is_module = true` le marque comme géré
 * par un module : PrestaShop cesse alors de le proposer au client dans le
 * formulaire natif « personnalisez votre produit ». Le visiteur configure par
 * le configurateur, pas par une zone de texte libre.
 */

declare(strict_types=1);

namespace Eko\SyncImprimerie\Configurateur;

final class Personnalisation
{
    /**
     * Le panier du visiteur, créé s'il n'en a pas encore.
     *
     * Un visiteur qui arrive sur une fiche produit n'a pas de panier :
     * PrestaShop n'en crée un qu'au premier ajout. Or le configurateur doit
     * retenir une configuration AVANT l'ajout, et une configuration a besoin
     * d'un panier pour exister.
     *
     * On en crée un exactement comme le fait PrestaShop lui-même. Un panier
     * vide ne coûte rien et ne se voit pas.
     */
    public static function panier(\Context $contexte): ?\Cart
    {
        $panier = $contexte->cart;

        if (\Validate::isLoadedObject($panier)) {
            return $panier;
        }

        $panier = new \Cart();
        $panier->id_lang = (int) $contexte->language->id;
        $panier->id_currency = (int) $contexte->currency->id;
        $panier->id_guest = (int) ($contexte->cookie->id_guest ?? 0);
        $panier->id_shop_group = (int) $contexte->shop->id_shop_group;
        $panier->id_shop = (int) $contexte->shop->id;
        $panier->id_customer = (int) ($contexte->customer->id ?? 0);

        if ($panier->id_customer > 0) {
            $panier->id_address_delivery = (int) \Address::getFirstCustomerAddressId($panier->id_customer);
            $panier->id_address_invoice = $panier->id_address_delivery;
        }

        if (!$panier->add()) {
            return null;
        }

        // Le rattacher au contexte ET au cookie : sans quoi l'appel suivant en
        // créerait un autre, et la configuration du premier serait perdue.
        $contexte->cart = $panier;
        $contexte->cookie->id_cart = (int) $panier->id;
        $contexte->cookie->write();

        return $panier;
    }

    /**
     * Le champ texte de personnalisation du produit, créé s'il n'existe pas.
     *
     * Rend `0` si le champ n'a pas pu être créé — l'appelant doit alors
     * refuser plutôt que de laisser croire qu'une configuration a été retenue.
     */
    public static function champTexte(int $idProduct): int
    {
        if ($idProduct <= 0) {
            return 0;
        }

        $existant = (int) \Db::getInstance()->getValue(
            'SELECT `id_customization_field` FROM `' . _DB_PREFIX_ . 'customization_field`'
            . ' WHERE `id_product` = ' . (int) $idProduct
            . ' AND `type` = ' . (int) \Product::CUSTOMIZE_TEXTFIELD
            . ' AND `is_deleted` = 0'
            . ' ORDER BY `id_customization_field` ASC'
        );

        if ($existant > 0) {
            return $existant;
        }

        $champ = new \CustomizationField();
        $champ->id_product = $idProduct;
        $champ->type = \Product::CUSTOMIZE_TEXTFIELD;
        $champ->required = false;
        // Géré par le module : PrestaShop ne le propose alors pas au client
        // dans son formulaire natif de personnalisation. Le visiteur configure
        // par le configurateur, pas par une zone de texte libre.
        $champ->is_module = true;

        $nom = [];

        foreach (\Language::getLanguages(false) as $langue) {
            $nom[(int) $langue['id_lang']] = 'Configuration';
        }

        $champ->name = $nom;

        if (!$champ->add()) {
            return 0;
        }

        self::rendreConfigurable($idProduct);

        return (int) $champ->id;
    }

    /**
     * Marque le produit comme personnalisable, en base et rien d'autre.
     *
     * ⚠️ Deux colonnes à mettre à jour, `product` ET `product_shop` : le front
     * lit la seconde, et un produit marqué dans la première seulement reste
     * non personnalisable à l'écran.
     *
     * ⚠️ Écriture SQL directe, délibérément. Charger le produit puis appeler
     * `update()` a déjà effacé son groupe de TVA sur ce projet : `new Product()`
     * ne recharge pas tous les champs, et les sauvegarder tous réécrit du vide
     * par-dessus. On ne touche donc que les deux colonnes concernées.
     */
    private static function rendreConfigurable(int $idProduct): void
    {
        foreach (['product', 'product_shop'] as $table) {
            \Db::getInstance()->execute(
                'UPDATE `' . _DB_PREFIX_ . $table . '` SET `customizable` = 1, `text_fields` = 1'
                . ' WHERE `id_product` = ' . (int) $idProduct
            );
        }
    }

    /**
     * Retient une configuration pour ce panier, et rend son identifiant.
     *
     * Le texte est celui que verront le panier, la commande ET LA FACTURE. Il
     * doit donc SE LIRE : il a un temps voyagé en JSON brut — `{"width":"1000"}`
     * — affiché tel quel au client sous « Votre personnalisation ».
     */
    public static function retenir(\Cart $panier, int $idProduct, string $texte): int
    {
        $champ = self::champTexte($idProduct);

        if ($champ <= 0) {
            return 0;
        }

        $id = $panier->addTextFieldToProduct(
            $idProduct,
            $champ,
            \Product::CUSTOMIZE_TEXTFIELD,
            self::tronquer($texte),
            true
        );

        return is_numeric($id) ? (int) $id : 0;
    }

    /**
     * 255 caractères : la colonne `customized_data.value` n'en prend pas plus.
     *
     * On coupe sur un séparateur plutôt qu'au caractère près : une
     * configuration qui se termine par « Finition : Bri » se lit plus mal
     * qu'une configuration à qui il manque visiblement la fin.
     */
    private static function tronquer(string $texte): string
    {
        if (mb_strlen($texte) <= 255) {
            return $texte;
        }

        $coupe = mb_substr($texte, 0, 255);
        $dernier = mb_strrpos($coupe, ' · ');

        return $dernier === false ? $coupe : mb_substr($coupe, 0, $dernier) . ' …';
    }
}
