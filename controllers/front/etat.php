<?php

/**
 * EKO Sync — Imprimerie
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

declare(strict_types=1);

use Eko\SyncImprimerie\Configurateur\CorrespondanceEtats;

/**
 * Le point d'entrée par lequel E-KO fait avancer une commande de la boutique.
 *
 * ─── POURQUOI E-KO POUSSE, ET NE SE FAIT PAS INTERROGER ────────────────────
 *
 * L'atelier fait avancer un dossier quand il l'a réellement fait avancer :
 * l'information naît là-bas. Une boutique qui viendrait la chercher toutes les
 * dix minutes enverrait mille requêtes par jour pour trois changements, et le
 * client apprendrait son expédition avec dix minutes de retard.
 *
 * ⚠️ Ce point d'entrée CHANGE UN STATUT ET ENVOIE UN E-MAIL. Il est donc
 * traité comme ce qu'il est : une porte ouverte sur l'internet qui écrit dans
 * les commandes. Trois gardes, dans cet ordre :
 *
 *   1. un secret partagé, comparé en TEMPS CONSTANT — une comparaison
 *      ordinaire fuit la longueur du préfixe correct, et un attaquant patient
 *      reconstitue le jeton caractère par caractère ;
 *   2. un état d'arrivée pris dans une LISTE BLANCHE — il ne doit pas être
 *      possible de faire passer une commande en « Remboursé » ;
 *   3. l'idempotence — E-KO peut réémettre, le réseau peut doubler ; poser
 *      deux fois le même statut enverrait deux fois le même e-mail.
 */
class EkosyncimprimerieEtatModuleFrontController extends ModuleFrontController
{
    /** @var bool réponse JSON, pas une page */
    public $content_only = true;

    /** @var bool aucune session client ici : l'appelant est un serveur */
    public $auth = false;

    /** @var bool le contrôleur ne doit pas exiger de client connecté */
    public $ssl = true;

    public function initContent()
    {
        if (strtoupper((string) $_SERVER['REQUEST_METHOD']) !== 'POST') {
            $this->repondre(405, ['erreur' => 'methode_non_autorisee']);
        }

        if (!$this->secretValide()) {
            // Volontairement avare : ni « jeton absent », ni « jeton faux ».
            // Distinguer les deux apprend à l'appelant où il en est.
            $this->repondre(401, ['erreur' => 'non_autorise']);
        }

        $corps = $this->corpsJson();

        $reference = trim((string) ($corps['reference'] ?? ''));
        $etatEko = trim((string) ($corps['etat'] ?? ''));

        if ($reference === '' || $etatEko === '') {
            $this->repondre(422, ['erreur' => 'reference_ou_etat_manquant']);
        }

        $idEtat = CorrespondanceEtats::statutBoutique($etatEko);

        if ($idEtat === null) {
            // Un état non retenu n'est PAS une erreur : E-KO doit pouvoir
            // avancer dans son workflow — `created`, `invoiced`, `closed` —
            // sans que la boutique s'y oppose. On accuse réception.
            $this->repondre(200, ['resultat' => 'etat_non_suivi', 'etat' => $etatEko]);
        }

        $idOrder = (int) Db::getInstance()->getValue(
            'SELECT id_order FROM `' . _DB_PREFIX_ . 'orders`'
            . " WHERE reference = '" . pSQL($reference) . "'"
            . ' ORDER BY id_order DESC'
        );

        if ($idOrder <= 0) {
            $this->repondre(404, ['erreur' => 'commande_introuvable', 'reference' => $reference]);
        }

        $commande = new Order($idOrder);

        if (!Validate::isLoadedObject($commande)) {
            $this->repondre(404, ['erreur' => 'commande_illisible']);
        }

        // ─── Idempotence ───────────────────────────────────────────────────
        if ((int) $commande->getCurrentState() === $idEtat) {
            $this->repondre(200, [
                'resultat' => 'deja_a_jour',
                'reference' => $reference,
                'id_etat' => $idEtat,
            ]);
        }

        $historique = new OrderHistory();
        $historique->id_order = $idOrder;
        $historique->changeIdOrderState($idEtat, $commande);

        // `addWithemail` rend `false` si l'e-mail part mal — mais le statut,
        // lui, est déjà écrit. On distingue donc les deux dans la réponse :
        // l'atelier doit savoir que la commande a bien avancé même si le
        // message n'est pas parti.
        $mailOk = (bool) $historique->addWithemail(true);

        $this->repondre(200, [
            'resultat' => 'applique',
            'reference' => $reference,
            'etat' => $etatEko,
            'id_etat' => $idEtat,
            'email' => $mailOk,
        ]);
    }

    /**
     * Le secret partagé est-il celui attendu ?
     *
     * Il se lit dans l'en-tête `Authorization: Bearer …`, comme celui que le
     * module présente à E-KO dans l'autre sens — un seul mécanisme pour les
     * deux directions, c'est un mécanisme de moins à se rappeler.
     */
    private function secretValide(): bool
    {
        $attendu = (string) Configuration::get('EKOSYNC_JETON_ENTRANT');

        // Pas de secret configuré = porte fermée. Le contraire — accepter tout
        // tant que rien n'est réglé — serait une porte grande ouverte le temps
        // d'une installation.
        if (strlen($attendu) < 32) {
            return false;
        }

        $entete = (string) ($_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '');

        if (!preg_match('/^Bearer\s+(\S+)$/i', $entete, $m)) {
            return false;
        }

        return hash_equals($attendu, $m[1]);
    }

    /** @return array<string,mixed> */
    private function corpsJson(): array
    {
        $brut = (string) file_get_contents('php://input');

        if ($brut === '') {
            return [];
        }

        $donnees = json_decode($brut, true);

        return is_array($donnees) ? $donnees : [];
    }

    /** @param array<string,mixed> $donnees */
    private function repondre(int $code, array $donnees): void
    {
        // Le tampon de PrestaShop contient déjà du HTML de page : sans ce
        // nettoyage il précéderait le JSON, qui deviendrait illisible.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($donnees, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
