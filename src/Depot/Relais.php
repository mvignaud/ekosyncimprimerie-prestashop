<?php

/**
 * EKO — socle partagé des dépôts de fichiers clients.
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

declare(strict_types=1);

namespace Eko\Depot;

/**
 * Le relais d'un fichier client vers l'entrepôt de l'ERP.
 *
 * ─── POURQUOI CETTE CLASSE VIT ICI, ET NON DANS UN MODULE ──────────────────
 *
 * Trois configurateurs déposent des fichiers — sous-traitance, objets, textile
 * — et tous les trois parlent au MÊME entrepôt, par le même protocole, avec les
 * mêmes pièges. Trois copies auraient divergé au premier correctif : celle
 * qu'on n'aurait pas mise à jour aurait continué de perdre des octets en
 * silence. Elle ne connaît donc aucun métier : on lui passe l'adresse, le
 * jeton, un flux.
 *
 * ─── EN DEUX TEMPS, ET EN FLUX ─────────────────────────────────────────────
 *
 * On DÉCLARE d'abord — nom, taille, type — puis on pousse les octets. L'ERP
 * rend un identifiant entre les deux ; c'est lui qui rattache le fichier à la
 * commande. Les octets ne sont jamais chargés en mémoire, et le jeton de l'ERP
 * ne sort jamais du serveur : le navigateur parle à la boutique, jamais à
 * l'ERP.
 */
class Relais
{
    /**
     * La reprise d'un appel tué par le TRANSPORT.
     *
     * ⚠️ Un déploiement de l'ERP arrête FrankenPHP puis le relance : de 2 à
     * 16 secondes pendant lesquelles le 443 refuse net. Un client qui dépose
     * son fichier à cet instant voyait une panne.
     *
     * ⚠️ Ces valeurs sont RECOPIÉES de `ClientEko` et non importées : ce
     * fichier est partagé tel quel entre les modules, et le faire dépendre
     * d'une classe propre à l'un d'eux le rendrait incopiable dans les autres.
     *
     * `CURLE_OPERATION_TIMEDOUT` reste dehors : un délai dépassé dit « lent »,
     * pas « absent », et le rejouer charge un serveur qui en manque déjà.
     */
    private const RETENTATIVES_RESEAU = 3;

    private const ATTENTE_RESEAU = 6;

    private const ERREURS_REJOUABLES = [6, 7, 35, 52, 55, 56, 92];

    /** Au-delà, le transfert est trop long pour une requête web. */
    private const DELAI_ENVOI = 900;

    private string $base;
    private string $jeton;

    public function __construct(string $base, string $jeton)
    {
        $this->base = rtrim(trim($base), '/');
        $this->jeton = trim($jeton);
    }

    public function estConfigure(): bool
    {
        return $this->base !== '' && $this->jeton !== '';
    }

    /**
     * Annonce un fichier et rend l'identifiant de dépôt, ou `null`.
     *
     * @return array{id: int, max: int}|null
     */
    public function declarer(string $reference, string $nom, int $taille, string $mime): ?array
    {
        $reponse = $this->appeler('/api/v1/printing/uploads', [
            // ⚠️ `raw` change le plafond que l'ERP applique : le chemin brut
            // n'est pas borné par les réglages de formulaire de PHP. Sans ce
            // mot, un fichier de 100 Mo serait refusé dès l'annonce.
            'transfer' => 'raw',
            'external_ref' => $reference,
            'filename' => $nom,
            'size' => $taille,
            'mime' => $mime,
        ]);

        $id = (int) ($reponse['data']['id'] ?? 0);

        return $id > 0 ? ['id' => $id, 'max' => (int) ($reponse['data']['max_bytes'] ?? 0)] : null;
    }

    /**
     * Pousse les octets d'un flux vers un dépôt déclaré.
     *
     * @param resource $flux
     *
     * @return array{ok: bool, envoyes: int, erreur: string}
     */
    public function pousser(int $idDepot, $flux, int $taille, int $reprises = 0): array
    {
        // La borne est vérifiée à l'ENTRÉE : une reprise de plus ne partirait
        // pas, mais elle aurait déjà dormi six secondes pour rien.
        if ($reprises > self::RETENTATIVES_RESEAU) {
            return ['ok' => false, 'envoyes' => 0, 'erreur' => sprintf(
                'ERP injoignable après %d reprises', self::RETENTATIVES_RESEAU
            )];
        }

        $ch = curl_init($this->base . '/api/v1/printing/uploads/' . $idDepot . '/content');

        if ($ch === false) {
            return ['ok' => false, 'envoyes' => 0, 'erreur' => 'curl indisponible'];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::DELAI_ENVOI,
            // ⚠️ HTTP/1.1 IMPOSÉ. En HTTP/2, un envoi de 120 Mo mourait après
            // exactement 1 Mo — « Stream error in the HTTP/2 framing layer »,
            // la fenêtre de contrôle de flux. Et cURL rendait quand même 200
            // avec un corps vide : l'échec ne se voyait qu'en comptant les
            // octets partis. Mesuré en production le 2026-08-28.
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->jeton,
                'Accept: application/json',
                // Corps BRUT, pas multipart : c'est ce qui évite le fichier
                // temporaire des deux côtés.
                'Content-Type: application/octet-stream',
                'Content-Length: ' . $taille,
                // Sans quoi cURL attend un « 100 Continue » qui n'arrive pas
                // toujours, et perd une seconde par envoi.
                'Expect:',
            ],
            CURLOPT_INFILESIZE => $taille,
            CURLOPT_READFUNCTION => static function ($ressource, $fd, $longueur) use ($flux): string {
                $morceau = fread($flux, $longueur);

                return $morceau === false ? '' : $morceau;
            },
        ]);

        $corps = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $envoyes = (int) curl_getinfo($ch, CURLINFO_SIZE_UPLOAD);
        $erreurCurl = curl_error($ch);
        $numeroCurl = curl_errno($ch);
        curl_close($ch);

        // ⚠️ REJOUER UN ENVOI EXIGE DE REMBOBINER LA SOURCE. La fonction de
        // lecture a déjà consommé une partie du flux ; repartir sans revenir
        // au début enverrait un fichier TRONQUÉ — et le serveur l'accepterait.
        // Un flux non rembobinable n'est donc jamais rejoué : mieux vaut un
        // échec que la moitié d'un visuel rangée sous le nom du bon.
        //
        // Le garde qui suit compte les octets partis : si une reprise se passe
        // mal, elle ne peut pas passer pour une réussite.
        $rembobinable = (bool) (stream_get_meta_data($flux)['seekable'] ?? false);

        if ($corps === false
            && in_array($numeroCurl, self::ERREURS_REJOUABLES, true)
            && $rembobinable
            && rewind($flux)
        ) {
            sleep(self::ATTENTE_RESEAU);

            return $this->pousser($idDepot, $flux, $taille, $reprises + 1);
        }

        // ⚠️ ON COMPTE LES OCTETS, ON NE SE FIE PAS AU CODE. Un envoi coupé en
        // route a déjà rendu 200 avec un corps vide ; seul le compteur disait
        // la vérité.
        if ($envoyes < $taille) {
            return [
                'ok' => false,
                'envoyes' => $envoyes,
                'erreur' => sprintf(
                    'transfert interrompu : %d octets sur %d%s',
                    $envoyes,
                    $taille,
                    $erreurCurl !== '' ? ' (' . $erreurCurl . ')' : ''
                ),
            ];
        }

        if ($code < 200 || $code >= 300) {
            $decode = json_decode((string) $corps, true);

            return [
                'ok' => false,
                'envoyes' => $envoyes,
                'erreur' => sprintf(
                    'HTTP %d — %s',
                    $code,
                    is_array($decode) ? (string) ($decode['message'] ?? 'sans message') : 'sans message'
                ),
            ];
        }

        return ['ok' => true, 'envoyes' => $envoyes, 'erreur' => ''];
    }

    /**
     * Le type réel d'un fichier, mesuré et non cru sur parole.
     *
     * ⚠️ Le type ANNONCÉ par le navigateur ne vaut rien : il vient du système
     * du visiteur, et se change à volonté. L'ERP refuse un type hors liste ;
     * lui envoyer celui du navigateur ferait rejeter des fichiers valides — ou
     * accepter la déclaration d'un fichier qui ne l'est pas.
     */
    public static function typeMime(string $chemin, string $nom): string
    {
        $type = '';

        if (function_exists('finfo_open')) {
            $info = finfo_open(FILEINFO_MIME_TYPE);

            if ($info !== false) {
                $type = (string) finfo_file($info, $chemin);
                finfo_close($info);
            }
        }

        if ($type !== '' && $type !== 'application/octet-stream') {
            return $type;
        }

        // Le repli par extension, pour les formats que `finfo` ne distingue
        // pas — un `.ai` est un PDF pour lui, un `.eps` du PostScript brut.
        return match (strtolower((string) pathinfo($nom, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'ai' => 'application/illustrator',
            'eps' => 'application/postscript',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'tif', 'tiff' => 'image/tiff',
            'psd' => 'image/vnd.adobe.photoshop',
            default => 'application/octet-stream',
        };
    }

    /**
     * @param array<string, mixed> $charge
     *
     * @return array<string, mixed>
     */
    private function appeler(string $chemin, array $charge, int $reprises = 0): array
    {
        $ch = curl_init($this->base . $chemin);

        if ($ch === false) {
            return [];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->jeton,
                'Accept: application/json',
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($charge, JSON_UNESCAPED_UNICODE),
        ]);

        $corps = curl_exec($ch);
        $numeroCurl = curl_errno($ch);
        curl_close($ch);

        // La déclaration est le PREMIER pas d'un dépôt : ratée, rien ne part.
        // Elle ne porte qu'un peu de JSON, la rejouer ne coûte rien.
        if ($corps === false
            && in_array($numeroCurl, self::ERREURS_REJOUABLES, true)
            && $reprises < self::RETENTATIVES_RESEAU
        ) {
            sleep(self::ATTENTE_RESEAU);

            return $this->appeler($chemin, $charge, $reprises + 1);
        }

        $decode = json_decode((string) $corps, true);

        return is_array($decode) ? $decode : [];
    }
}
