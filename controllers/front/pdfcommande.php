<?php

/**
 * EKO Sync — Imprimerie
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

declare(strict_types=1);

/**
 * Le document de commande du client — RELAYÉ depuis l'ERP.
 *
 * ─── ⚠️ CE CONTRÔLEUR NE FABRIQUE PLUS RIEN, ET C'EST LE POINT ─────────────
 *
 * Il composait son propre PDF avec TCPDF. Le document existait donc en deux
 * exemplaires : celui de la boutique et celui de l'ERP, avec des numéros
 * différents, des totaux calculés séparément et des mentions qui allaient
 * dériver au premier changement d'un seul côté. Un client aurait fini par
 * présenter l'un pendant que l'atelier lisait l'autre.
 *
 * Décision de Mathieu, le 2026-08-13 : « il faut que les deux PDF E-KO et PS
 * soient identiques ». La seule façon de le tenir dans la durée n'est pas de
 * synchroniser deux gabarits — ils divergeront — mais de n'en avoir qu'UN.
 * C'est celui de l'ERP, qui est le système de référence : c'est lui qui
 * numérote, qui facturera, et que l'atelier a sous les yeux.
 *
 * ─── ET SI LE DEVIS N'EXISTE PAS ENCORE ────────────────────────────────────
 *
 * On ne rend RIEN, et on le dit. Servir un document de repli composé ici
 * ramènerait exactement le problème qu'on vient de supprimer : deux documents
 * pour une commande, selon le moment où le client a cliqué.
 *
 * ─── L'URL PORTE LE CODE DU DEVIS ──────────────────────────────────────────
 *
 * `Proposal::getRouteKeyName()` rend `code` côté ERP : l'identifiant numérique
 * y donne un 404 muet. Le code est donc conservé par la poussée de commande,
 * dans `ekosync_commande`.
 */
class EkosyncimprimeriePdfcommandeModuleFrontController extends ModuleFrontController
{
    /** @var bool cette page ne s'affiche pas, elle rend un fichier */
    public $content_only = true;

    /** @var bool vrai dès qu'on a décidé de refuser */
    private $refuse = false;

    public function init()
    {
        $this->refuse = $this->commande() === null;

        if ($this->refuse) {
            $this->page_name = 'pagenotfound';
        }

        parent::init();
    }

    public function initContent()
    {
        if ($this->refuse) {
            $this->rendre(404, $this->module->l('Bon de commande indisponible.', 'pdfcommande'));
        }

        $commande = $this->commande();
        $code = $this->codeDevis((int) $commande->id);

        if ($code === '') {
            // Le devis n'est pas encore né — la poussée a échoué, ou la
            // commande ne portait aucune ligne configurée. On dit l'attente
            // plutôt que de fabriquer un second document.
            $this->rendre(409, $this->module->l(
                'Votre document est en cours de préparation. Il sera disponible ici sous peu.',
                'pdfcommande'
            ));
        }

        /** @var Ekosyncimprimerie $module */
        $module = $this->module;

        // ⚠️ Client SANS CACHE. Le document change quand l'atelier retouche le
        // devis ; servir une version retenue en cache donnerait au client un
        // document périmé, et à l'atelier la certitude qu'il a vu le bon.
        $reponse = $module->client(0)->telecharger('/api/v1/proposals/' . rawurlencode($code) . '/pdf');

        $contenu = (string) ($reponse['contenu'] ?? '');

        if (!$reponse['ok'] || $contenu === '' || !str_starts_with($contenu, '%PDF')) {
            \PrestaShopLogger::addLog(
                'ekosyncimprimerie — document de commande indisponible : devis ' . $code
                . ' — ' . mb_substr((string) ($reponse['erreur'] ?? 'sans message'), 0, 300),
                2,
                null,
                'Order',
                (int) $commande->id
            );

            $this->rendre(502, $this->module->l(
                'Votre document est momentanément indisponible. Réessayez dans un instant.',
                'pdfcommande'
            ));
        }

        $this->envoyer($contenu, $code . '.pdf');
    }

    /**
     * La redirection canonique reconstruirait l'URL sans nos paramètres, et le
     * client tournerait entre une 301 et une 404 sans voir son document.
     */
    public function canonicalRedirection($canonicalURL = '')
    {
        return;
    }

    public function getCanonicalURL(): string
    {
        return '';
    }

    private function commande(): ?Order
    {
        $client = $this->context->customer;

        if (!Validate::isLoadedObject($client) || !$client->isLogged()) {
            return null;
        }

        $id = (int) Tools::getValue('id_order');

        if ($id <= 0) {
            return null;
        }

        $commande = new Order($id);

        if (!Validate::isLoadedObject($commande)) {
            return null;
        }

        // La seule barrière qui compte : l'identifiant de commande est un
        // entier, il suffirait de le faire varier pour lire les commandes des
        // autres.
        return (int) $commande->id_customer === (int) $client->id ? $commande : null;
    }

    private function codeDevis(int $idOrder): string
    {
        return (string) Db::getInstance()->getValue(
            'SELECT `proposal_code` FROM `' . _DB_PREFIX_ . 'ekosync_commande`'
            . ' WHERE `id_order` = ' . $idOrder
            . " AND `statut` = 'ok'"
        );
    }

    private function rendre(int $statut, string $message): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code($statut);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
        exit;
    }

    private function envoyer(string $contenu, string $nom): void
    {
        // Le tampon de sortie de PrestaShop contient déjà du HTML de page à ce
        // stade. Sans ce nettoyage, il se retrouverait EN TÊTE du PDF, qui
        // deviendrait illisible — sans erreur, juste un fichier corrompu.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $nom) . '"');
        header('Content-Length: ' . strlen($contenu));
        header('Content-Transfer-Encoding: binary');
        // Un document nominatif ne se met pas en cache partagé.
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');

        echo $contenu;
        exit;
    }
}
