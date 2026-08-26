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
     * Le nom du champ qui porte la CONFIGURATION de la ligne.
     *
     * ⚠️ CE NOM EST LA SEULE VÉRITÉ QUI DÉSIGNE LE CHAMP, et il le restera.
     *
     * Le module a longtemps élu son champ par `ORDER BY id_customization_field
     * ASC` — « le plus ancien des champs texte de ce produit ». Tant qu'il n'y
     * en avait qu'un, cela marchait par accident. Dès qu'un second existe
     * (le commentaire du client), le tri décide seul lequel des deux reçoit
     * quoi, et rien ne le signale : la configuration s'écrit dans le
     * commentaire, ou l'inverse, selon l'ordre de création — qui change si un
     * champ est recréé sur un produit.
     *
     * Le nom, lui, ne dépend d'aucun ordre.
     *
     * Il est aussi vu par le client : au panier, sur la confirmation et sur la
     * FACTURE. « Configuration » est le mot du développeur ; celui de
     * l'acheteur est « votre configuration ».
     */
    public const CHAMP_CONFIGURATION = 'Votre configuration';

    /** Le nom du champ qui porte le COMMENTAIRE libre du client. */
    public const CHAMP_COMMENTAIRE = 'Commentaire';

    /**
     * L'identifiant d'un champ texte de ce produit, désigné par son NOM.
     *
     * Rend `0` si aucun champ de ce nom n'existe — sans en créer. C'est la
     * lecture ; la création passe par `champTexte()`.
     *
     * ⚠️ La jointure porte sur `customization_field_lang`, où le nom vit. Le
     * `id_lang` n'est PAS filtré à dessein : le nom est le même dans toutes
     * les langues (il est posé identique à la création), et filtrer sur la
     * langue du contexte ferait échouer la recherche depuis un contexte sans
     * langue — une tâche planifiée, une ligne de commande.
     */
    public static function champNomme(int $idProduct, string $nom): int
    {
        if ($idProduct <= 0 || $nom === '') {
            return 0;
        }

        return (int) \Db::getInstance()->getValue(
            'SELECT cf.`id_customization_field`'
            . ' FROM `' . _DB_PREFIX_ . 'customization_field` cf'
            . ' JOIN `' . _DB_PREFIX_ . 'customization_field_lang` cfl'
            . ' ON cfl.`id_customization_field` = cf.`id_customization_field`'
            . ' WHERE cf.`id_product` = ' . (int) $idProduct
            . ' AND cf.`type` = ' . (int) \Product::CUSTOMIZE_TEXTFIELD
            // ⚠️ Un champ supprimé au back-office n'est pas effacé, il est
            // marqué. L'oublier fait réécrire dans un champ que PrestaShop
            // n'affiche plus nulle part — la donnée part, l'écran reste vide.
            . ' AND cf.`is_deleted` = 0'
            . " AND cfl.`name` = '" . pSQL($nom) . "'"
            . ' ORDER BY cf.`id_customization_field` ASC'
        );
    }

    /**
     * Le champ texte de personnalisation du produit, créé s'il n'existe pas.
     *
     * Rend `0` si le champ n'a pas pu être créé — l'appelant doit alors
     * refuser plutôt que de laisser croire qu'une configuration a été retenue.
     *
     * ⚠️ LE NOM EST UN PARAMÈTRE, et non plus une chaîne enfouie. C'est ce qui
     * permet au commentaire d'être un SECOND champ de ce produit sans que les
     * deux se confondent jamais.
     */
    public static function champTexte(int $idProduct, string $nom = self::CHAMP_CONFIGURATION): int
    {
        if ($idProduct <= 0 || $nom === '') {
            return 0;
        }

        $existant = self::champNomme($idProduct, $nom);

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

        $noms = [];

        foreach (\Language::getLanguages(false) as $langue) {
            // ⚠️ LE MÊME NOM DANS TOUTES LES LANGUES. C'est un identifiant
            // autant qu'un libellé : le traduire ici rendrait le champ
            // introuvable depuis une autre langue que celle de sa création.
            // La traduction visible se fait à l'affichage, pas en base.
            $noms[(int) $langue['id_lang']] = $nom;
        }

        $champ->name = $noms;

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
        // ⚠️ `text_fields` EST UN COMPTEUR, PAS UN DRAPEAU. Il valait `1` en
        // dur, ce qui était juste tant que le module ne posait qu'un champ.
        // Depuis qu'une ligne peut porter la configuration ET un commentaire,
        // écrire 1 laisse le cœur croire qu'il n'y a qu'un champ texte alors
        // qu'il y en a deux — MESURÉ en production : produit 45, compteur 1,
        // réel 2.
        //
        // On le compte, on ne le suppose pas.
        $combien = (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'customization_field`'
            . ' WHERE `id_product` = ' . (int) $idProduct
            . ' AND `type` = ' . (int) \Product::CUSTOMIZE_TEXTFIELD
            . ' AND `is_deleted` = 0'
        );

        $combien = max(1, $combien);

        foreach (['product', 'product_shop'] as $table) {
            \Db::getInstance()->execute(
                'UPDATE `' . _DB_PREFIX_ . $table . '` SET `customizable` = 1,'
                . ' `text_fields` = ' . $combien
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
     * La longueur RÉELLE de `customized_data.value`, mesurée en base.
     *
     * ⚠️ CE FICHIER ANNONÇAIT 255 ET `prix.php` 1024, dans le même dépôt.
     * `DESCRIBE pre5616_customized_data` le 2026-08-13 : `varchar(1024)`.
     *
     * Le chiffre bas n'était pas seulement faux, il COÛTAIT : une
     * configuration longue — grand format, papier détaillé, plusieurs
     * façonnages — perdait les trois quarts de ce qui tenait, sans erreur ni
     * avertissement, et la ligne partait ainsi jusqu'à la facture.
     *
     * La constante est ici parce que c'est ici que vit le champ. `prix.php`,
     * qui portait sa propre copie, s'y réfère désormais : deux limites pour
     * une même colonne finissent toujours par diverger, et c'est la plus
     * basse qui décide en silence.
     */
    public const LONGUEUR_VALEUR = 1024;

    /**
     * On coupe sur un séparateur plutôt qu'au caractère près : une
     * configuration qui se termine par « Finition : Bri » se lit plus mal
     * qu'une configuration à qui il manque visiblement la fin.
     */
    private static function tronquer(string $texte): string
    {
        if (mb_strlen($texte) <= self::LONGUEUR_VALEUR) {
            return $texte;
        }

        $coupe = mb_substr($texte, 0, self::LONGUEUR_VALEUR);
        $dernier = mb_strrpos($coupe, ' · ');

        return $dernier === false ? $coupe : mb_substr($coupe, 0, $dernier) . ' …';
    }
}
