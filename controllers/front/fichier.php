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
use Eko\SyncImprimerie\Configurateur\SpecTechnique;

/**
 * Le relais des fichiers clients vers E-KO.
 *
 * ─── POURQUOI LA BOUTIQUE RELAIE, PLUTÔT QUE LE NAVIGATEUR ─────────────────
 *
 * Décision du 2026-08-13 : pas d'URL S3 présignée. Le fichier passe donc par
 * ici. Ce n'est pas le chemin le plus court, mais c'est celui qui évite
 * d'ouvrir le seau S3 aux navigateurs.
 *
 * Deux conséquences tenues :
 *
 *   — le JETON D'E-KO NE SORT JAMAIS du serveur. Le navigateur parle à ce
 *     contrôleur, jamais à E-KO. Un jeton posé dans une page est un jeton
 *     public.
 *
 *   — LE FICHIER N'EST JAMAIS ÉCRIT ICI. PHP dépose déjà l'envoi dans son
 *     propre fichier temporaire et LE SUPPRIME SEUL à la fin de la requête —
 *     à la condition expresse de ne jamais appeler `move_uploaded_file()`.
 *     On lit donc directement `tmp_name` en flux. Rien à nettoyer, parce que
 *     rien n'aura été créé.
 *
 * ─── LE RÉSEAU ─────────────────────────────────────────────────────────────
 *
 * ⚠️ Ce contrôleur ne fonctionne QUE depuis le tier web. Sur ce mutualisé
 * OVH, la passerelle SSH ne joint pas l'API de l'ERP — seul le contexte web y
 * accède. Un script de reprise lancé en ligne de commande échouerait sans
 * qu'on comprenne pourquoi.
 */
class EkosyncimprimerieFichierModuleFrontController extends ModuleFrontController
{
    /** @var bool réponse JSON, pas une page */
    public $content_only = true;

    /** @var bool le panier d'un visiteur non connecté doit pouvoir déposer */
    public $auth = false;

    public function initContent()
    {
        $action = (string) Tools::getValue('action');

        switch ($action) {
            case 'envoyer':
                $this->envoyer();
                break;

            case 'statut':
                $this->statut();
                break;

            case 'retirer':
                $this->retirer();
                break;

            default:
                $this->repondre(400, ['erreur' => 'action_inconnue']);
        }
    }

    /**
     * Reçoit le fichier, le déclare à E-KO, puis relaie les octets.
     */
    private function envoyer(): void
    {
        $idCustomization = (int) Tools::getValue('id_customization');
        $ligne = $this->ligneDuPanier($idCustomization);

        if ($ligne === null) {
            $this->repondre(403, ['erreur' => 'ligne_inconnue']);
        }

        $fichier = $_FILES['fichier'] ?? null;

        if (!is_array($fichier)) {
            $this->repondre(422, ['erreur' => 'aucun_fichier']);
        }

        $erreur = (int) ($fichier['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($erreur !== UPLOAD_ERR_OK) {
            $this->repondre(422, ['erreur' => 'envoi_incomplet', 'message' => self::messageErreur($erreur)]);
        }

        $temporaire = (string) ($fichier['tmp_name'] ?? '');
        $taille = (int) ($fichier['size'] ?? 0);
        $nom = self::nomPropre((string) ($fichier['name'] ?? ''));

        // Le garde qui compte : sans lui, un chemin fabriqué dans la requête
        // ferait relayer n'importe quel fichier du serveur vers E-KO.
        if ($temporaire === '' || !is_uploaded_file($temporaire)) {
            $this->repondre(422, ['erreur' => 'fichier_non_recu']);
        }

        if ($taille <= 0) {
            $this->repondre(422, ['erreur' => 'fichier_vide']);
        }

        $type = self::typeMime($temporaire, $nom);

        if ($type === null) {
            $this->repondre(422, ['erreur' => 'type_refuse']);
        }

        /** @var Ekosyncimprimerie $module */
        $module = $this->module;
        $client = $module->client();

        // ─── 1. On déclare ─────────────────────────────────────────────────
        $declaration = $client->appeler('POST', '/api/v1/printing/uploads', [
            'external_ref' => (string) $idCustomization,
            'filename' => $nom,
            'size' => $taille,
            'mime' => $type,
            'expected' => $this->attendu($ligne),
        ]);

        if (!$declaration['ok']) {
            $this->repondre(502, [
                'erreur' => 'declaration_refusee',
                'message' => $this->messageDeLErp($declaration),
            ]);
        }

        $uploadId = (int) ($declaration['donnees']['data']['id'] ?? 0);

        if ($uploadId <= 0) {
            $this->repondre(502, ['erreur' => 'identifiant_absent']);
        }

        // ─── 2. On relaie les octets ───────────────────────────────────────
        $envoi = $this->relayer($uploadId, $temporaire, $nom, $type);

        if (!$envoi['ok']) {
            // Même règle qu'à la déclaration : le détail au journal, une
            // phrase utile au visiteur.
            $this->repondre(502, [
                'erreur' => 'envoi_refuse',
                'message' => $this->messageDeLErp(['message' => $envoi['message']]),
            ]);
        }

        FichierClient::poser($idCustomization, (int) $this->context->cart->id, $uploadId, [
            'nom' => $nom,
            'taille' => $taille,
            'statut' => (string) ($envoi['statut'] ?? 'analyzing'),
        ]);

        $this->repondre(200, [
            'upload_id' => $uploadId,
            'nom' => $nom,
            'taille' => $taille,
            'statut' => $envoi['statut'] ?? 'analyzing',
        ]);
    }

    /**
     * Relaie le fichier temporaire de PHP vers E-KO, EN FLUX.
     *
     * ⚠️ `CURLOPT_INFILE` et non `CURLOPT_POSTFIELDS` avec un `CURLFile` :
     * cURL lit alors le descripteur au fur et à mesure au lieu de charger le
     * fichier en mémoire. Sur un grand format de cent méga-octets, la
     * différence est entre un envoi qui passe et un processus qui meurt.
     *
     * @return array{ok:bool,message:string,statut:?string}
     */
    private function relayer(int $uploadId, string $temporaire, string $nom, string $type): array
    {
        /** @var Ekosyncimprimerie $module */
        $module = $this->module;

        $base = rtrim((string) Configuration::get(Ekosyncimprimerie::CLE_BASE), '/');
        $jeton = (string) Configuration::get(Ekosyncimprimerie::CLE_JETON);
        $url = $base . '/api/v1/printing/uploads/' . $uploadId . '/content';

        $flux = @fopen($temporaire, 'rb');

        if ($flux === false) {
            return ['ok' => false, 'message' => 'Fichier temporaire illisible.', 'statut' => null];
        }

        // ⚠️ On construit le corps multipart À LA MAIN, autour du flux.
        // `CURLFile` chargerait le fichier ; les en-têtes sont donc écrits
        // avant et après, et cURL diffuse ce qu'il y a entre les deux.
        $limite = '----eko' . bin2hex(random_bytes(12));

        $entete = "--$limite\r\n"
            . 'Content-Disposition: form-data; name="file"; filename="' . str_replace('"', '', $nom) . "\"\r\n"
            . "Content-Type: $type\r\n\r\n";
        $pied = "\r\n--$limite--\r\n";

        $tailleFichier = (int) @filesize($temporaire);
        $corpsTotal = strlen($entete) + $tailleFichier + strlen($pied);

        $lu = 0;
        $etape = 0; // 0 = en-tête, 1 = fichier, 2 = pied

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 150,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $jeton,
                'Accept: application/json',
                'Content-Type: multipart/form-data; boundary=' . $limite,
                'Content-Length: ' . $corpsTotal,
                'Expect:',
            ],
            CURLOPT_UPLOAD => false,
            CURLOPT_INFILESIZE => $corpsTotal,
            CURLOPT_READFUNCTION => static function ($ressource, $fd, $longueur) use (
                &$etape, &$lu, $entete, $pied, $flux
            ): string {
                if ($etape === 0) {
                    $morceau = substr($entete, $lu, $longueur);
                    $lu += strlen($morceau);

                    if ($lu >= strlen($entete)) {
                        $etape = 1;
                        $lu = 0;
                    }

                    return $morceau;
                }

                if ($etape === 1) {
                    $morceau = fread($flux, $longueur);

                    if ($morceau === false || $morceau === '') {
                        $etape = 2;
                        $lu = 0;

                        return substr($pied, 0, $longueur);
                    }

                    return $morceau;
                }

                $morceau = substr($pied, $lu, $longueur);
                $lu += strlen($morceau);

                return $morceau;
            },
        ]);

        $reponse = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erreurCurl = curl_error($ch);
        curl_close($ch);
        fclose($flux);

        if ($code < 200 || $code >= 300) {
            $decode = json_decode($reponse, true);

            return [
                'ok' => false,
                'message' => is_array($decode) && isset($decode['message'])
                    ? (string) $decode['message']
                    : ($erreurCurl !== '' ? $erreurCurl : 'HTTP ' . $code),
                'statut' => null,
            ];
        }

        $decode = json_decode($reponse, true);

        return [
            'ok' => true,
            'message' => '',
            'statut' => is_array($decode) ? (string) ($decode['data']['status'] ?? 'analyzing') : 'analyzing',
        ];
    }

    /**
     * Où en est l'analyse ?
     *
     * ⚠️ Le dépôt doit appartenir à la ligne DEMANDÉE. L'identifiant est un
     * simple entier : sans cette vérification, le faire varier donnerait le
     * rapport de préflight des fichiers d'autres clients.
     */
    private function statut(): void
    {
        $idCustomization = (int) Tools::getValue('id_customization');
        $uploadId = (int) Tools::getValue('upload_id');

        if ($this->ligneDuPanier($idCustomization) === null
            || !FichierClient::appartient($uploadId, $idCustomization)) {
            $this->repondre(403, ['erreur' => 'depot_inconnu']);
        }

        /** @var Ekosyncimprimerie $module */
        $module = $this->module;

        // ⚠️ Un client SANS CACHE. Celui du module retient tout GET pendant
        // quinze minutes : le statut resterait « analyse en cours » un quart
        // d'heure après la fin.
        $r = $module->client(0)->appeler('GET', '/api/v1/printing/uploads/' . $uploadId);

        if (!$r['ok']) {
            $this->repondre(502, ['erreur' => 'erp_injoignable']);
        }

        $donnees = $r['donnees']['data'] ?? [];
        $statut = (string) ($donnees['status'] ?? 'pending');
        $verdict = isset($donnees['verdict']) ? (string) $donnees['verdict'] : null;

        FichierClient::majEtat($uploadId, $statut, $verdict);

        $this->repondre(200, [
            'upload_id' => $uploadId,
            'statut' => $statut,
            'verdict' => $verdict,
            'analyse' => (bool) ($donnees['analysed'] ?? false),
            'rapport' => $donnees['report'] ?? null,
        ]);
    }

    /** Détache un dépôt de la ligne. Le fichier reste sur E-KO. */
    private function retirer(): void
    {
        $idCustomization = (int) Tools::getValue('id_customization');
        $uploadId = (int) Tools::getValue('upload_id');

        if ($this->ligneDuPanier($idCustomization) === null
            || !FichierClient::appartient($uploadId, $idCustomization)) {
            $this->repondre(403, ['erreur' => 'depot_inconnu']);
        }

        FichierClient::retirer($uploadId, $idCustomization);

        $this->repondre(200, ['retire' => true]);
    }

    /**
     * La ligne du panier COURANT portant cette personnalisation, ou `null`.
     *
     * C'est la seule autorisation qui vaille ici : le visiteur peut agir sur
     * les lignes de SON panier, et sur aucune autre. Un identifiant de
     * personnalisation se devine en comptant.
     *
     * @return array<string,mixed>|null
     */
    private function ligneDuPanier(int $idCustomization): ?array
    {
        if ($idCustomization <= 0) {
            return null;
        }

        $cart = $this->context->cart;

        if (Validate::isLoadedObject($cart)) {
            foreach ($cart->getProducts() as $ligne) {
                if ((int) ($ligne['id_customization'] ?? 0) === $idCustomization) {
                    return $ligne;
                }
            }
        }

        return $this->ligneDUneCommande($idCustomization);
    }

    /**
     * La même ligne, mais une fois la commande passée.
     *
     * ─── POURQUOI CE SECOND CHEMIN ─────────────────────────────────────────
     *
     * Beaucoup de clients commandent avant d'avoir leur maquette — c'est tout
     * l'objet de la case « je chargerai mes fichiers plus tard ». Or le panier
     * COURANT ne contient plus rien une fois la commande validée : les trois
     * actions de ce contrôleur (envoyer, sonder, retirer) rendaient alors 403,
     * et le client ne pouvait plus jamais déposer.
     *
     * ─── ⚠️ ET POURQUOI IL EXIGE LA CONNEXION, LUI ─────────────────────────
     *
     * Le premier chemin s'appuie sur le panier de la session : il ne peut
     * désigner que ce que le visiteur possède déjà, connecté ou non. Celui-ci
     * part d'un identifiant NU, et un identifiant de personnalisation est un
     * entier qui s'énumère. Sans le contrôle d'appartenance ci-dessous, il
     * suffirait de le faire varier pour remplacer — ou lire — les fichiers de
     * production des autres clients.
     *
     * On remonte la chaîne complète : personnalisation → panier → commande →
     * client. `customization` n'a jamais porté d'`id_order` ; c'est par le
     * panier que le cœur lui-même relie les deux.
     *
     * @return array<string,mixed>|null
     */
    private function ligneDUneCommande(int $idCustomization): ?array
    {
        $client = $this->context->customer ?? null;

        if (!Validate::isLoadedObject($client) || !$client->isLogged()) {
            return null;
        }

        $idCart = (int) \Db::getInstance()->getValue(
            'SELECT cu.`id_cart`'
            . ' FROM `' . _DB_PREFIX_ . 'customization` cu'
            . ' JOIN `' . _DB_PREFIX_ . 'orders` o ON o.`id_cart` = cu.`id_cart`'
            . ' WHERE cu.`id_customization` = ' . $idCustomization
            . ' AND o.`id_customer` = ' . (int) $client->id
        );

        if ($idCart <= 0) {
            return null;
        }

        // Le panier de la commande porte la ligne avec tout ce que l'appelant
        // attend — `id_product`, `cart_quantity`, l'attendu de préflight. Le
        // reconstruire à la main ici en donnerait une version appauvrie, et
        // l'attendu servirait alors à contrôler le fichier contre d'autres
        // cotes que celles annoncées au client.
        $panier = new \Cart($idCart);

        if (!Validate::isLoadedObject($panier)) {
            return null;
        }

        foreach ($panier->getProducts() as $ligne) {
            if ((int) ($ligne['id_customization'] ?? 0) === $idCustomization) {
                return $ligne;
            }
        }

        return null;
    }

    /**
     * L'attendu de cette ligne, tel que le préflight le compare.
     *
     * Il vient du MÊME calcul que le gabarit et la fenêtre technique. Le
     * recomposer ici autrement ferait qu'on contrôlerait le fichier contre des
     * cotes différentes de celles qu'on a données au client.
     *
     * @param  array<string,mixed> $ligne
     * @return array<string,mixed>
     */
    private function attendu(array $ligne): array
    {
        /** @var Ekosyncimprimerie $module */
        $module = $this->module;

        $criteres = $module->criteresDeLignePublic((int) ($ligne['id_customization'] ?? 0));

        if ($criteres === []) {
            return [];
        }

        $spec = SpecTechnique::depuisConfiguration($criteres);

        return $spec === null ? [] : $spec->attenduPreflight();
    }

    /**
     * Ce qu'on montre au visiteur quand l'ERP refuse.
     *
     * ⚠️ JAMAIS le message brut. Un client a vu s'afficher dans son panier
     * « The POST method is not supported for route api/v1/printing/uploads » —
     * une phrase qui ne lui apprend rien, le laisse croire à une panne de son
     * fichier, et renseigne au passage sur l'architecture interne.
     *
     * Le détail technique part au journal, où il sert à qui peut agir.
     *
     * @param array<string,mixed> $reponse
     */
    private function messageDeLErp(array $reponse): string
    {
        $donnees = $reponse['donnees'] ?? [];
        $detail = is_array($donnees) && isset($donnees['message'])
            ? (string) $donnees['message']
            : (string) ($reponse['message'] ?? '');

        \PrestaShopLogger::addLog(
            'ekosyncimprimerie — dépôt de fichier refusé par l’ERP : '
            . mb_substr($detail === '' ? 'sans message' : $detail, 0, 500),
            2,
            null,
            'Cart',
            (int) ($this->context->cart->id ?? 0)
        );

        // ─── UN FICHIER TROP LOURD N'EST PAS UNE PANNE ─────────────────────
        //
        // ⚠️ Tous les refus rendaient « momentanément indisponible ». C'est la
        // bonne réponse quand l'ERP ne répond pas ; c'est une réponse FAUSSE
        // quand le fichier dépasse la taille admise. Le client attendait, puis
        // réessayait le même fichier, indéfiniment — rien ne lui disait ce
        // qu'il fallait changer.
        //
        // On distingue donc ce seul cas, parce que c'est le seul où le client
        // peut AGIR : alléger son PDF, ou nous l'envoyer autrement.
        $plafond = self::plafondAnnonce($reponse);

        if ($plafond !== null) {
            // Avec le chiffre quand on l'a, sans lui quand on ne l'a pas :
            // dans les deux cas le client sait quoi faire.
            return $plafond > 0
                ? sprintf(
                    $this->module->l(
                        'Ce fichier dépasse la taille acceptée (%s Mo maximum). '
                        . 'Allégez-le, ou envoyez-le nous par e-mail : nous le rattacherons à votre commande.',
                        'fichier'
                    ),
                    $plafond
                )
                : $this->module->l(
                    'Ce fichier est trop lourd pour le dépôt en ligne. '
                    . 'Allégez-le, ou envoyez-le nous par e-mail : nous le rattacherons à votre commande.',
                    'fichier'
                );
        }

        return $this->module->l(
            'Le dépôt de fichiers est momentanément indisponible. '
            . 'Envoyez-nous votre fichier par e-mail, nous le rattacherons à votre commande.',
            'fichier'
        );
    }

    /**
     * Le plafond en mégaoctets, si le refus porte bien sur la TAILLE.
     *
     * ⚠️ On ne se fie pas au seul texte du message : l'ERP annonce sa limite
     * dans `max_bytes` à la déclaration, et c'est ce chiffre-là qui fait foi.
     * Le message, lui, peut venir de PHP (« The POST data is too large ») et ne
     * porte alors aucune valeur exploitable.
     *
     * Rend `null` si le refus n'a rien à voir avec la taille — auquel cas
     * l'appelant garde son message de panne.
     *
     * @param array<string,mixed> $reponse
     */
    private static function plafondAnnonce(array $reponse): ?int
    {
        $donnees = $reponse['donnees'] ?? [];
        $texte = is_array($donnees) ? json_encode($donnees) : '';
        $texte .= ' ' . (string) ($reponse['message'] ?? '') . ' ' . (string) ($reponse['erreur'] ?? '');

        $parleDeTaille = preg_match('/too large|trop (?:lourd|volumineux|grand)|max(?:imum)?.{0,12}(?:size|taille)|\bsize\b/i', $texte) === 1;

        if (!$parleDeTaille) {
            return null;
        }

        // La valeur annoncée par l'ERP, quand elle est là.
        if (is_array($donnees)) {
            $octets = (int) ($donnees['data']['max_bytes'] ?? $donnees['max_bytes'] ?? 0);

            if ($octets > 0) {
                return (int) floor($octets / (1024 * 1024));
            }
        }

        // ⚠️ PAS DE REPLI SUR LA LIMITE DE LA BOUTIQUE. Elle accepte 128 Mo
        // là où l'ERP en accepte 64 : annoncer 128 renverrait le client
        // réessayer le MÊME fichier, qui échouerait encore. Un chiffre faux
        // est pire que pas de chiffre.
        //
        // On rend donc 0 : l'appelant dira « trop lourd » sans avancer de
        // taille, ce qui est vrai et actionnable (alléger, ou envoyer par
        // e-mail).
        return 0;
    }

    /**
     * Le type du fichier, déduit de son CONTENU, ou `null` s'il est refusé.
     *
     * ⚠️ Jamais le type annoncé par le navigateur : il vient du client et se
     * fabrique. On lit les premiers octets.
     */
    private static function typeMime(string $chemin, string $nom): ?string
    {
        $acceptes = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/tiff',
            'application/postscript',
            'application/zip',
        ];

        $type = null;

        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);

            if ($finfo !== false) {
                $lu = @finfo_file($finfo, $chemin);
                @finfo_close($finfo);

                if (is_string($lu) && $lu !== '') {
                    $type = $lu;
                }
            }
        }

        if ($type === null) {
            // Repli sur l'extension, à défaut de mieux. Moins sûr, mais un
            // refus systématique sur un serveur sans `fileinfo` bloquerait
            // toute la boutique.
            $type = match (mb_strtolower((string) pathinfo($nom, PATHINFO_EXTENSION))) {
                'pdf' => 'application/pdf',
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'tif', 'tiff' => 'image/tiff',
                'eps', 'ai' => 'application/postscript',
                'zip' => 'application/zip',
                default => '',
            };
        }

        return in_array($type, $acceptes, true) ? $type : null;
    }

    private static function nomPropre(string $nom): string
    {
        $propre = preg_replace('/[\x00-\x1F\/\\\\]+/u', '', basename($nom)) ?? '';

        return mb_substr(trim($propre), 0, 255) ?: 'fichier';
    }

    private static function messageErreur(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Fichier trop lourd pour le serveur.',
            UPLOAD_ERR_PARTIAL => 'Envoi interrompu, fichier incomplet.',
            UPLOAD_ERR_NO_FILE => 'Aucun fichier sélectionné.',
            UPLOAD_ERR_NO_TMP_DIR => 'Dossier temporaire absent sur le serveur.',
            UPLOAD_ERR_CANT_WRITE => 'Écriture impossible sur le serveur.',
            default => 'Envoi refusé (code ' . $code . ').',
        };
    }

    /** @param array<string,mixed> $donnees */
    private function repondre(int $code, array $donnees): void
    {
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
