<?php

/**
 * EKO Sync — Imprimerie
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

/**
 * Reçoit le fichier d'impression du client et le relaie à l'ERP.
 *
 * ─── POURQUOI PASSER PAR LA BOUTIQUE ───────────────────────────────────────
 *
 * Le navigateur ne doit JAMAIS voir le jeton de l'ERP. Un envoi direct du
 * navigateur vers l'API imposerait de le lui confier, et il vit alors dans le
 * code de la page, lisible par n'importe qui. Le fichier transite donc par
 * ici : le client parle à sa boutique, la boutique parle à l'ERP.
 *
 * ─── EN DEUX TEMPS, ET POURQUOI ────────────────────────────────────────────
 *
 * On annonce d'abord — nom, poids, type — puis on envoie les octets. L'ERP
 * refuse ce qu'il ne veut pas AVANT le transfert : un client sur une connexion
 * lente apprend en une seconde que son fichier ne convient pas, au lieu de
 * téléverser quarante mégaoctets pour se le voir refuser à l'arrivée.
 *
 * ─── LA RÉFÉRENCE ──────────────────────────────────────────────────────────
 *
 * La MÊME que celle portée par la ligne de devis : `<préfixe>-C<configuration>`.
 * C'est par elle, et par elle seule, que le fichier retrouvera sa ligne quand
 * le dossier de fabrication naîtra. La calculer autrement ici que dans la
 * remontée de commande produirait deux références qui ne se rencontrent
 * jamais — le fichier resterait en transit sans que rien ne le signale, ce qui
 * est le pire des défauts : silencieux.
 */

use Eko\SyncImprimerie\Client\DepotEko;
use Eko\SyncImprimerie\Commande\DevisCommande;
use Eko\SyncImprimerie\Configurateur\PrixConfigure;

class EkosyncimprimerieDepotModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ajax = true;

    /**
     * Réponse depuis `postProcess()`, jamais `initContent()` : un contrôleur
     * de module qui ne rend aucun gabarit meurt dans le second, en 500 muet.
     */
    public function postProcess()
    {
        $this->ajaxRender((string) json_encode($this->deposer(), JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string, mixed>
     */
    private function deposer(): array
    {
        /** @var Ekosyncimprimerie $module */
        $module = $this->module;

        if (!(bool) Configuration::get(Ekosyncimprimerie::CLE_COMMANDES)) {
            return $this->refus('Le dépôt de fichiers n\'est pas activé sur cette boutique.');
        }

        $idProduct = (int) Tools::getValue('id_product');

        if ($idProduct <= 0) {
            return $this->refus('Produit non désigné.');
        }

        $panier = $this->context->cart;

        if (!$panier instanceof Cart || (int) $panier->id <= 0) {
            return $this->refus('Aucune configuration en cours : configurez le produit avant de déposer votre fichier.');
        }

        $idCustomization = (new PrixConfigure())->customizationEnCours($panier, $idProduct);

        if ($idCustomization <= 0) {
            return $this->refus('Aucune configuration en cours : configurez le produit avant de déposer votre fichier.');
        }

        $fichier = $this->fichierRecu();

        if (is_string($fichier)) {
            return $this->refus($fichier);
        }

        $prefixe = (string) Configuration::get(Ekosyncimprimerie::CLE_REF_PREFIXE);
        $prefixe = $prefixe !== '' ? $prefixe : 'PS' . (int) $this->context->shop->id;

        $reference = (new DevisCommande(new PrixConfigure()))->reference($prefixe, $idCustomization);

        if ($reference === '') {
            return $this->refus('Configuration non identifiée.');
        }

        $depot = new DepotEko($module->client());

        // ⚠️ Le type ANNONCÉ par le navigateur ment facilement : il se déduit
        // de l'extension du nom, que n'importe qui choisit. On lit donc le
        // type RÉEL dans les octets. L'ERP le recontrôle de son côté — mais
        // annoncer la vérité lui permet de refuser tout de suite, au lieu de
        // laisser le client téléverser pour rien.
        $mime = $this->typeReel((string) $fichier['tmp_name'], (string) $fichier['type']);

        $annonce = $depot->annoncerFichier(
            $reference,
            (string) $fichier['name'],
            (int) $fichier['size'],
            $mime
        );

        if (!$annonce['ok'] || $annonce['id'] <= 0) {
            // Le message de l'ERP est celui qui aide : « type non accepté »,
            // « fichier trop lourd ». On le rend tel quel plutôt que de le
            // remplacer par une formule générique.
            return $this->refus($annonce['erreur'] !== '' ? $annonce['erreur'] : 'Dépôt refusé.');
        }

        $envoi = $depot->pousserFichier(
            $annonce['id'],
            (string) $fichier['tmp_name'],
            (string) $fichier['name'],
            $mime
        );

        if (!$envoi['ok']) {
            return $this->refus($envoi['erreur'] !== '' ? $envoi['erreur'] : 'Envoi interrompu.');
        }

        return [
            'ok' => true,
            'nom' => (string) $fichier['name'],
            'statut' => $envoi['statut'],
            // `$this->trans()` et non `getTranslator()->trans()` : c'est la
            // forme que le catalogue sait extraire, comme dans le contrôleur
            // du catalogue. L'autre passerait le garde sans être traduite.
            'message' => $this->trans(
                'Fichier reçu. Il sera contrôlé avant fabrication.',
                [],
                'Modules.Ekosyncimprimerie.Shop'
            ),
        ];
    }

    /**
     * Le fichier reçu, ou un message expliquant pourquoi il ne l'est pas.
     *
     * ⚠️ `UPLOAD_ERR_INI_SIZE` arrive quand le fichier dépasse ce que PHP
     * accepte — un plafond de la BOUTIQUE, pas de l'ERP. Le dire clairement
     * évite de chercher l'erreur du mauvais côté.
     *
     * @return array<string, mixed>|string
     */
    private function fichierRecu()
    {
        $fichier = $_FILES['fichier'] ?? null;

        if (!is_array($fichier) || !isset($fichier['error'])) {
            return 'Aucun fichier reçu.';
        }

        $erreur = (int) $fichier['error'];

        if ($erreur === UPLOAD_ERR_INI_SIZE || $erreur === UPLOAD_ERR_FORM_SIZE) {
            return 'Fichier trop lourd pour cette boutique.';
        }

        if ($erreur !== UPLOAD_ERR_OK) {
            return 'Envoi interrompu, merci de réessayer.';
        }

        if (!is_uploaded_file((string) $fichier['tmp_name'])) {
            // Sans ce contrôle, un chemin fourni par la requête ferait lire un
            // fichier quelconque du serveur, et l'enverrait à l'ERP.
            return 'Fichier invalide.';
        }

        if ((int) $fichier['size'] <= 0) {
            return 'Fichier vide.';
        }

        return $fichier;
    }

    /**
     * Le type réel du fichier, lu dans ses octets.
     *
     * `finfo` peut manquer sur un hébergement mutualisé : on retombe alors
     * sur le type déclaré, en le disant plutôt qu'en le supposant. L'ERP
     * recontrôle de toute façon à l'arrivée, donc ce repli ne laisse rien
     * passer — il fait seulement perdre un aller-retour.
     */
    private function typeReel(string $fichierLocal, string $declare): string
    {
        if (!function_exists('finfo_open')) {
            return $declare !== '' ? $declare : 'application/octet-stream';
        }

        $info = finfo_open(FILEINFO_MIME_TYPE);

        if ($info === false) {
            return $declare !== '' ? $declare : 'application/octet-stream';
        }

        $type = finfo_file($info, $fichierLocal);
        finfo_close($info);

        return is_string($type) && $type !== '' ? $type : ($declare !== '' ? $declare : 'application/octet-stream');
    }

    /**
     * @return array{ok: false, erreur: string}
     */
    private function refus(string $raison): array
    {
        return ['ok' => false, 'erreur' => $raison];
    }
}
