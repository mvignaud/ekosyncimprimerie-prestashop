<?php

/**
 * EKO Sync — Imprimerie
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

declare(strict_types=1);

use Eko\SyncImprimerie\Configurateur\Personnalisation;
use Eko\SyncImprimerie\Configurateur\ReponseJson;

/**
 * Les deux gestes qui manquaient sur une ligne de panier : commenter, dupliquer.
 *
 * ─── LE COMMENTAIRE EST UN VRAI CHAMP DE PERSONNALISATION ──────────────────
 *
 * Et non une table à part. C'est ce qui le fait redescendre GRATUITEMENT là où
 * il doit aller : le back-office affiche les champs d'une commande sous forme
 * « nom : valeur », la facture aussi. Une table maison aurait demandé du code
 * à chacun de ces endroits — et n'en aurait couvert aucun le jour où
 * quelqu'un regarde la commande depuis un écran qu'on n'a pas prévu.
 *
 * ─── ⚠️ DEUX CHEMINS D'ÉCRITURE, ET UN SEUL EST SÛR SELON L'ÉTAT ───────────
 *
 * `Cart::addTextFieldToProduct()` ne vaut que tant que la personnalisation est
 * `in_cart = 0`. Passé ce point, `_addCustomization()` en crée une SECONDE —
 * donc une deuxième ligne de panier, facturée en double. Sur une ligne déjà
 * au panier on écrit donc directement dans `customized_data`.
 *
 * Pas d'`INSERT … ON DUPLICATE KEY` : on efface puis on insère. La clé
 * primaire de la table est le triplet (id_customization, type, index) —
 * `delete` + `insert` ne suppose rien de plus.
 *
 * ─── ET LA DUPLICATION NE PEUT PAS S'APPUYER SUR LE CŒUR ───────────────────
 *
 * `Cart::duplicateProduct()` est un talon : il rend `false` sans rien faire ni
 * rien journaliser. Tout est donc recopié à la main — et surtout les lignes
 * `ekosync_prix`, sans lesquelles la copie ressortirait à 0,00 € : le hook de
 * prix LIT cette table, il ne recalcule jamais. Le délai de livraison y vit
 * aussi (`lead_days`), il suit donc du même mouvement.
 *
 * ⚠️ Les dépôts de fichiers ne sont PAS recopiés. Côté E-KO un dépôt porte UNE
 * référence externe ; deux lignes pointant le même dépôt ne pourraient jamais
 * se rattacher toutes les deux, et la seconde resterait en transit sans que
 * rien ne le dise.
 */
class EkosyncimprimerieLigneModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ajax = true;

    public function postProcess()
    {
        // ⚠️ `ReponseJson::rendre()` et rien d'autre : `ajaxRender()` laisse
        // PrestaShop poursuivre vers `initContent()`, qui cherche un gabarit
        // que ce contrôleur n'a pas — la requête finissait alors en HTTP 500
        // avec un corps pourtant correct. Le même défaut a été corrigé le même
        // jour sur `prix.php` et `catalogue.php` ; c'est leur remède.
        $reponse = $this->agir();

        // Un refus est un 422, pas un 200 « avec ok:false » ni un 500 : dans
        // les journaux du serveur, une saisie incomplète doit rester
        // discernable d'un vrai incident.
        ReponseJson::rendre(
            $reponse,
            ($reponse['ok'] ?? false) === true ? 200 : ReponseJson::REFUS
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function agir(): array
    {
        // ⚠️ POST OBLIGATOIRE. `Tools::getValue()` lit indifféremment GET et
        // POST : sans ce garde, une page tierce faisant
        // `<img src=".../ligne?geste=commenter&id_customization=41207&texte=">`
        // effacerait la consigne de fabrication d'un client au simple
        // chargement de sa page, et `geste=dupliquer` lui ajouterait une ligne
        // facturable. L'identifiant est un auto-incrément global : le deviner
        // ne demande aucun talent.
        //
        // Un jeton ne remplacerait pas ce garde : `Tools::getToken(false)` est
        // un hachage de (client, mot de passe, page) — identique pour TOUS les
        // visiteurs non connectés, et le panier est ouvert aux invités.
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            return $this->refus('Méthode refusée.');
        }

        $panier = $this->context->cart;

        if (!Validate::isLoadedObject($panier)) {
            return $this->refus('Panier introuvable.');
        }

        $idCustomization = (int) Tools::getValue('id_customization');

        if ($idCustomization <= 0) {
            return $this->refus('Ligne non désignée.');
        }

        // ⚠️ LA SEULE BARRIÈRE QUI COMPTE. L'identifiant est un entier : sans
        // ce contrôle, il suffirait de le faire varier pour écrire un
        // commentaire — ou dupliquer une ligne — dans le panier d'autrui.
        $ligne = $this->ligneDuPanier($panier, $idCustomization);

        if ($ligne === null) {
            return $this->refus('Cette ligne n’appartient pas à votre panier.');
        }

        return match (Tools::getValue('geste')) {
            'commenter' => $this->commenter($panier, $ligne),
            'dupliquer' => $this->dupliquer($panier, $ligne),
            default => $this->refus('Geste inconnu.'),
        };
    }

    /**
     * La ligne de personnalisation, si elle appartient bien à ce panier.
     *
     * @return array<string,mixed>|null
     */
    private function ligneDuPanier(Cart $panier, int $idCustomization): ?array
    {
        // ⚠️ LA QUANTITÉ VIENT DE `cart_product`, PAS DE `customization`.
        //
        // `customization.quantity` est DÉPRÉCIÉE depuis PrestaShop 9
        // (`classes/Customization.php:24` : « Use the quantity from the table
        // cart_product instead ») et n'a plus aucun écrivain : `_addCustomization()`
        // ne l'insère pas, `updateQty()` ne la met pas à jour. Elle vaut donc 0
        // sur toutes les lignes.
        //
        // La lire ici donnait une copie à 1 exemplaire au lieu du tirage
        // commandé — et pire : les lignes `ekosync_prix` recopiées portent la
        // quantité SOURCE (500, par exemple), tandis que le hook de prix
        // interroge la table avec la quantité de la copie (1). Aucune
        // correspondance, aucun prix mémorisé, retour au tarif CATALOGUE.
        //
        // C'est exactement le défaut que le configurateur documente comme
        // « grave, et silencieux » : chez un imprimeur la quantité EST une
        // donnée de configuration, elle détermine le prix au tarif fournisseur.
        $ligne = Db::getInstance()->getRow(
            'SELECT c.`id_customization`, c.`id_product`, c.`id_product_attribute`,'
            . ' c.`id_address_delivery`, c.`in_cart`,'
            . ' cp.`quantity` AS `quantite_panier`'
            . ' FROM `' . _DB_PREFIX_ . 'customization` c'
            . ' LEFT JOIN `' . _DB_PREFIX_ . 'cart_product` cp'
            . ' ON cp.`id_cart` = c.`id_cart`'
            . ' AND cp.`id_product` = c.`id_product`'
            . ' AND cp.`id_product_attribute` = c.`id_product_attribute`'
            . ' AND cp.`id_customization` = c.`id_customization`'
            . ' WHERE c.`id_customization` = ' . $idCustomization
            . ' AND c.`id_cart` = ' . (int) $panier->id
        );

        return is_array($ligne) && $ligne !== [] ? $ligne : null;
    }

    /**
     * Écrit — ou efface — le commentaire de la ligne.
     *
     * @param array<string,mixed> $ligne
     *
     * @return array<string,mixed>
     */
    private function commenter(Cart $panier, array $ligne): array
    {
        $texte = trim((string) Tools::getValue('texte'));

        // ⚠️ Tronqué, jamais refusé. Perdre un commentaire entier — donc
        // potentiellement une consigne de fabrication — parce qu'il dépasse
        // serait disproportionné. La limite est celle de la colonne, mesurée.
        if (mb_strlen($texte) > Personnalisation::LONGUEUR_VALEUR) {
            $texte = mb_substr($texte, 0, Personnalisation::LONGUEUR_VALEUR);
        }

        $idProduct = (int) $ligne['id_product'];
        $idCustomization = (int) $ligne['id_customization'];

        $champ = Personnalisation::champTexte($idProduct, Personnalisation::CHAMP_COMMENTAIRE);

        if ($champ <= 0) {
            return $this->refus('Ce produit n’accepte pas de commentaire.');
        }

        $db = Db::getInstance();
        $prefixe = _DB_PREFIX_;
        $type = (int) Product::CUSTOMIZE_TEXTFIELD;

        // On efface d'abord, dans les deux cas : un commentaire vidé doit
        // disparaître, pas rester avec une chaîne vide qui s'afficherait au
        // back-office comme un champ renseigné à blanc.
        $db->execute(
            "DELETE FROM `{$prefixe}customized_data`"
            . ' WHERE `id_customization` = ' . $idCustomization
            . ' AND `type` = ' . $type
            . ' AND `index` = ' . (int) $champ
        );

        if ($texte !== '') {
            $ok = $db->execute(
                "INSERT INTO `{$prefixe}customized_data`"
                . ' (`id_customization`, `type`, `index`, `value`, `id_module`, `price`, `weight`)'
                . ' VALUES (' . $idCustomization
                . ', ' . $type
                . ', ' . (int) $champ
                . ", '" . pSQL($texte) . "'"
                // ⚠️ Zéro, et non `null` : `price` et `weight` sont NOT NULL,
                // et `Db::insert()` avec `null` écrit une chaîne vide plutôt
                // qu'un NULL — un défaut déjà payé sur ce projet.
                . ', 0, 0, 0)'
            );

            if (!$ok) {
                return $this->refus('Le commentaire n’a pas pu être enregistré.');
            }
        }

        // Le panier doit se resouvenir qu'il a changé, sans quoi les écrans
        // qui s'appuient sur sa date de modification serviraient du périmé.
        $panier->update();

        return ['ok' => true, 'texte' => $texte, 'id_customization' => $idCustomization];
    }

    /**
     * Duplique la ligne, avec sa configuration, son commentaire et ses prix.
     *
     * @param array<string,mixed> $ligne
     *
     * @return array<string,mixed>
     */
    private function dupliquer(Cart $panier, array $ligne): array
    {
        $db = Db::getInstance();
        $prefixe = _DB_PREFIX_;

        $source = (int) $ligne['id_customization'];
        $idProduct = (int) $ligne['id_product'];
        $idProductAttribute = (int) $ligne['id_product_attribute'];
        $quantite = max(1, (int) ($ligne['quantite_panier'] ?? 0));

        $ok = $db->execute(
            "INSERT INTO `{$prefixe}customization`"
            . ' (`id_cart`, `id_product`, `id_product_attribute`, `id_address_delivery`, `quantity`, `in_cart`)'
            . ' VALUES (' . (int) $panier->id
            . ', ' . $idProduct
            . ', ' . $idProductAttribute
            . ', ' . (int) $ligne['id_address_delivery']
            // ⚠️ `quantity` reste à 0 : la colonne est DÉPRÉCIÉE en PS 9 et
            // plus personne ne l'écrit — pas même le cœur. La vraie quantité
            // vit dans `cart_product`, et c'est `updateQty()` qui l'y pose.
            // `in_cart = 0` pour la même raison : c'est `updateQty()` qui
            // fait entrer la ligne au panier.
            . ', 0, 0)'
        );

        if (!$ok) {
            return $this->refus('La ligne n’a pas pu être dupliquée.');
        }

        $copie = (int) $db->Insert_ID();

        if ($copie <= 0) {
            return $this->refus('La copie n’a pas d’identifiant.');
        }

        // La configuration ET le commentaire, d'un seul mouvement : ce sont
        // les mêmes lignes de `customized_data`, et une copie qui perdrait sa
        // configuration ne serait plus chiffrable.
        $db->execute(
            "INSERT INTO `{$prefixe}customized_data`"
            . ' (`id_customization`, `type`, `index`, `value`, `id_module`, `price`, `weight`)'
            . " SELECT {$copie}, `type`, `index`, `value`, `id_module`, `price`, `weight`"
            . " FROM `{$prefixe}customized_data` WHERE `id_customization` = " . $source
        );

        // ⚠️ SANS CECI LA COPIE RESSORT À 0,00 €. Le hook de prix LIT
        // `ekosync_prix`, il ne recalcule jamais — c'est délibéré, PrestaShop
        // l'exécute jusqu'à quatre fois par ligne de panier. Le délai de
        // livraison vit dans la même table (`lead_days`) et suit donc.
        $db->execute(
            "INSERT INTO `{$prefixe}ekosync_prix`"
            . ' (`id_customization`, `quantity`, `id_product`, `price_ht_cents`, `variables`, `lead_days`, `date_upd`)'
            . " SELECT {$copie}, `quantity`, `id_product`, `price_ht_cents`, `variables`, `lead_days`, `date_upd`"
            . " FROM `{$prefixe}ekosync_prix` WHERE `id_customization` = " . $source
        );

        $ajout = $panier->updateQty($quantite, $idProduct, $idProductAttribute ?: null, $copie, 'up');

        if ($ajout === false || $ajout === -1) {
            // On ne laisse pas derrière nous une personnalisation orpheline :
            // elle réapparaîtrait au prochain rendu du panier, sans quantité,
            // et personne ne saurait d'où elle vient.
            $db->execute("DELETE FROM `{$prefixe}customized_data` WHERE `id_customization` = " . $copie);
            $db->execute("DELETE FROM `{$prefixe}ekosync_prix` WHERE `id_customization` = " . $copie);
            $db->execute("DELETE FROM `{$prefixe}customization` WHERE `id_customization` = " . $copie);

            return $this->refus('La copie n’a pas pu être ajoutée au panier.');
        }

        return ['ok' => true, 'id_customization' => $copie];
    }

    /**
     * @return array<string,mixed>
     */
    private function refus(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }
}
