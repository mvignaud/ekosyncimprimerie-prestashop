<?php

/**
 * EKO Sync — Imprimerie
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

declare(strict_types=1);

use Eko\SyncImprimerie\Configurateur\FichierClient;

/**
 * « Mes fichiers » — le dépôt différé, depuis le compte client.
 *
 * ─── CE QUE CET ÉCRAN RÉPARE ───────────────────────────────────────────────
 *
 * Le panier propose « je chargerai mes fichiers plus tard ». Jusqu'ici cette
 * promesse n'était tenue nulle part : une fois la commande passée, le panier
 * courant est vide, et le client n'avait plus aucun endroit où déposer. Il
 * fallait écrire un courriel, et quelqu'un rattachait à la main.
 *
 * ─── ⚠️ `auth = true`, ET CE N'EST PAS UNE FORMALITÉ ───────────────────────
 *
 * Le contrôleur de dépôt, lui, vit avec `auth = false` — c'est nécessaire : un
 * visiteur non connecté doit pouvoir déposer depuis son propre panier, que la
 * session identifie. Ici l'écran part de l'inverse : il ÉNUMÈRE les commandes
 * d'un client. Sans authentification, un identifiant de commande — un entier —
 * suffirait à lire les fichiers de production d'autrui.
 *
 * ─── L'ÉTAT « EN ATTENTE » NE SE STOCKE PAS ────────────────────────────────
 *
 * Une ligne est en attente de fichier quand elle n'a AUCUNE ligne dans
 * `ekosync_fichier`. On ne range donc nulle part un drapeau « différé » : la
 * case du panier n'écrit rien, et un drapeau finirait tôt ou tard par
 * contredire la présence réelle des fichiers.
 */
class EkosyncimprimerieMesfichiersModuleFrontController extends ModuleFrontController
{
    /** @var bool cet écran est nominatif */
    public $auth = true;

    /** @var string */
    public $php_self = 'module-ekosyncimprimerie-mesfichiers';

    public function initContent()
    {
        parent::initContent();

        $client = $this->context->customer;

        $this->context->smarty->assign([
            'eko_commandes' => $this->commandes((int) $client->id),
            'eko_url_depot' => $this->context->link->getModuleLink(
                'ekosyncimprimerie',
                'fichier',
                [],
                true
            ),
        ]);

        $this->setTemplate('module:ekosyncimprimerie/views/templates/front/mesfichiers.tpl');
    }

    /**
     * Le fil d'Ariane, pour ne pas laisser le client dans un cul-de-sac.
     *
     * @return array<string,mixed>
     */
    public function getBreadcrumbLinks()
    {
        $fil = parent::getBreadcrumbLinks();

        $fil['links'][] = [
            'title' => $this->module->l('Mes fichiers', 'mesfichiers'),
            'url' => $this->context->link->getModuleLink('ekosyncimprimerie', 'mesfichiers'),
        ];

        return $fil;
    }

    /**
     * Les commandes du client, avec leurs lignes et l'état de leurs fichiers.
     *
     * @return array<int,array<string,mixed>>
     */
    private function commandes(int $idCustomer): array
    {
        if ($idCustomer <= 0) {
            return [];
        }

        /** @var Ekosyncimprimerie $module */
        $module = $this->module;

        $sorties = [];

        // ⚠️ On part des COMMANDES du client, pas d'un identifiant reçu : la
        // liste ne peut donc contenir que ce qui lui appartient. C'est la même
        // garantie que le contrôleur de dépôt obtient par la chaîne
        // personnalisation → panier → commande → client.
        foreach (Order::getCustomerOrders($idCustomer) as $entete) {
            $commande = new Order((int) $entete['id_order']);

            if (!Validate::isLoadedObject($commande)) {
                continue;
            }

            $depots = FichierClient::duPanier((int) $commande->id_cart);
            $lignes = [];

            foreach ($commande->getProducts() as $produit) {
                $idCustomization = (int) ($produit['id_customization'] ?? 0);

                // Une ligne sans personnalisation n'est pas un imprimé
                // configuré : il n'y a pas de fichier à lui rattacher.
                if ($idCustomization <= 0) {
                    continue;
                }

                $lignes[] = [
                    'id_customization' => $idCustomization,
                    'nom' => (string) ($produit['product_name'] ?? ''),
                    'quantite' => (int) ($produit['product_quantity'] ?? 0),
                    'configuration' => $module->criteresDeLignePublic($idCustomization),
                    'fichiers' => $depots[$idCustomization] ?? [],
                ];
            }

            if ($lignes === []) {
                continue;
            }

            $sorties[] = [
                'id_order' => (int) $commande->id,
                'reference' => (string) $commande->reference,
                'date' => Tools::displayDate($commande->date_add),
                'etat' => $this->etat($commande),
                'lignes' => $lignes,
                // Ce qui décide de l'accent visuel : une commande dont tout est
                // déposé n'a plus besoin d'attirer l'œil.
                'en_attente' => count(array_filter(
                    $lignes,
                    static fn (array $l): bool => $l['fichiers'] === []
                )),
            ];
        }

        return $sorties;
    }

    private function etat(Order $commande): string
    {
        $etat = $commande->getCurrentOrderState();

        if (!Validate::isLoadedObject($etat)) {
            return '';
        }

        $nom = $etat->name;

        return is_array($nom)
            ? (string) ($nom[(int) $this->context->language->id] ?? reset($nom))
            : (string) $nom;
    }
}
