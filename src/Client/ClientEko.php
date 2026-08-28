<?php

/**
 * EKO Sync — Imprimerie
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

/**
 * Client HTTP vers l'API E-KO.
 *
 * Volontairement mince : il transporte, il ne décide pas. Aucun calcul de prix
 * ici, aucune règle métier — l'exigence est que
 * le tarif du site soit RIGOUREUSEMENT celui d'un devis E-KO, donc PrestaShop
 * ne recalcule jamais rien. Ce fichier est la seule porte par laquelle un prix
 * peut entrer dans la boutique.
 *
 * curl brut plutôt qu'une bibliothèque : une seule dépendance de moins à suivre
 * sur un hébergement mutualisé, et PrestaShop n'expose pas Guzzle de façon
 * stable d'une version à l'autre.
 */

declare(strict_types=1);

namespace Eko\SyncImprimerie\Client;

class ClientEko
{
    public const DELAI_CONNEXION = 5;
    public const DELAI_TOTAL = 15;

    /**
     * Combien de fois rejouer un appel tué par le TRANSPORT — pas par une
     * réponse du serveur.
     *
     * ⚠️ Un déploiement de l'ERP arrête FrankenPHP puis le relance : mesuré le
     * 2026-08-28, la coupure dure de 2 à 16 secondes, et il y a eu cinq
     * déploiements dans la journée. Pendant cette fenêtre le port 443 refuse
     * NET — « Couldn't connect to server » en 2 ms. Sans reprise, la tâche en
     * cours meurt sur place, et le client voit une panne pour une coupure de
     * quelques secondes.
     *
     * Trois reprises à six secondes couvrent vingt-quatre secondes. Au-delà,
     * ce n'est plus un redémarrage mais une panne, et elle doit remonter.
     */
    private const RETENTATIVES_RESEAU = 3;

    /** L'attente entre deux reprises de transport, en secondes. */
    private const ATTENTE_RESEAU = 6;

    /**
     * Les échecs de TRANSPORT qui méritent une reprise.
     *
     * ⚠️ Uniquement ceux-là. Un 4xx ou un 5xx est une RÉPONSE : la rejouer ne
     * la changerait pas, et masquerait une erreur qui doit se voir.
     *
     * ⚠️ `CURLE_OPERATION_TIMEDOUT` est ABSENT volontairement. Un délai dépassé
     * ne veut pas dire « service absent » mais « service lent » : le rejouer
     * ajoute de la charge à un serveur qui en manque déjà, et transforme un
     * appel long en trois. Le redémarrage, lui, refuse en 2 ms — c'est le cas
     * 7, et c'est celui qu'on rattrape.
     *
     * `CURLE_HTTP2_STREAM` (92) est ce que rend un appel coupé EN COURS par
     * l'arrêt du serveur ; `CURLE_SSL_CONNECT_ERROR` (35) ce que rend un appel
     * arrivé pendant que le service remonte, écouteur ouvert mais TLS pas prêt.
     */
    private const ERREURS_REJOUABLES = [
        6,  // CURLE_COULDNT_RESOLVE_HOST
        7,  // CURLE_COULDNT_CONNECT
        35, // CURLE_SSL_CONNECT_ERROR
        52, // CURLE_GOT_NOTHING
        55, // CURLE_SEND_ERROR
        56, // CURLE_RECV_ERROR
        92, // CURLE_HTTP2_STREAM
    ];

    /** Préfixe des clés de cache, pour pouvoir toutes les retrouver. */
    private const PREFIXE_CACHE = 'ekosync_c_';

    private string $base;
    private string $jeton;
    private int $dureeCache;

    public function __construct(string $base, string $jeton, int $dureeCache = 0)
    {
        // Une base sans barre finale évite les doubles slashs dans les chemins,
        // qui font répondre 404 à certaines configurations Apache.
        $this->base = rtrim(trim($base), '/');
        $this->jeton = trim($jeton);
        $this->dureeCache = max(0, $dureeCache);
    }

    public function estConfigure(): bool
    {
        return $this->base !== '' && $this->jeton !== '';
    }

    /**
     * Vérifie que l'API répond et que le jeton est accepté.
     *
     * Deux appels et pas un : `/ping` dit que le service est joignable, `/me`
     * dit que le jeton vaut quelque chose. Un `/ping` vert avec un jeton mort
     * est le faux positif classique — on croit la liaison bonne jusqu'au
     * premier appel utile.
     *
     * @return array{ok: bool, messages: list<string>}
     */
    public function diagnostiquer(): array
    {
        $messages = [];

        if (!$this->estConfigure()) {
            return ['ok' => false, 'messages' => ['Adresse ou jeton non renseigné.']];
        }

        $ping = $this->appeler('GET', '/api/v1/ping');

        if (!$ping['ok']) {
            return ['ok' => false, 'messages' => ['Service injoignable : ' . $ping['erreur']]];
        }

        $messages[] = sprintf('Service joignable (%d ms).', $ping['duree_ms']);

        $moi = $this->appeler('GET', '/api/v1/me');

        if (!$moi['ok']) {
            $messages[] = 'Jeton REFUSÉ : ' . $moi['erreur'];

            return ['ok' => false, 'messages' => $messages];
        }

        $messages[] = 'Jeton accepté.';

        return ['ok' => true, 'messages' => $messages];
    }

    /**
     * @param  array<string,mixed>|null  $corps
     * @return array{ok: bool, code: int, donnees: array<string,mixed>, erreur: string, duree_ms: int}
     */
    /**
     * Le cache ne garde que des réponses de LECTURE, et seulement si elles ont
     * réussi.
     *
     * Mettre en cache une écriture la ferait disparaître ; mettre en cache une
     * erreur la ferait durer. La clé inclut la base et le jeton : changer l'un
     * ou l'autre doit invalider ce qui a été lu avec les précédents, sans quoi
     * un jeton révoqué continuerait de servir des données.
     */
    private function cleCache(string $chemin): string
    {
        return self::PREFIXE_CACHE . md5($this->base . '|' . $this->jeton . '|' . $chemin);
    }

    /** @return array<string,mixed>|null */
    private function lireCache(string $cle): ?array
    {
        if ($this->dureeCache <= 0) {
            return null;
        }

        $brut = \Configuration::getGlobalValue($cle);

        if (!is_string($brut) || $brut === '') {
            return null;
        }

        $paquet = json_decode($brut, true);

        if (!is_array($paquet) || !isset($paquet['t'], $paquet['r']) || !is_array($paquet['r'])) {
            return null;
        }

        if (time() - (int) $paquet['t'] > $this->dureeCache) {
            return null;
        }

        return $paquet['r'];
    }

    /** @param  array<string,mixed>  $reponse */
    private function ecrireCache(string $cle, array $reponse): void
    {
        if ($this->dureeCache <= 0) {
            return;
        }

        \Configuration::updateGlobalValue($cle, (string) json_encode([
            't' => time(),
            'r' => $reponse,
        ]));
    }

    /** Vide tout ce que ce module a mis en cache. */
    public static function viderCache(): int
    {
        $lignes = \Db::getInstance()->executeS(
            'SELECT `name` FROM `' . _DB_PREFIX_ . 'configuration`'
            . ' WHERE `name` LIKE "' . pSQL(self::PREFIXE_CACHE) . '%"'
        ) ?: [];

        foreach ($lignes as $l) {
            \Configuration::deleteByName((string) $l['name']);
        }

        return count($lignes);
    }

    /**
     * Télécharge un fichier binaire de l'ERP.
     *
     * ─── ⚠️ POURQUOI `appeler()` NE PEUT PAS SERVIR ────────────────────────
     *
     * `appeler()` fait `json_decode()` sur la réponse. Un PDF n'est pas du
     * JSON : le décodage échoue, la méthode conclut à une réponse illisible et
     * rend un échec — alors que le serveur a parfaitement répondu. Le défaut
     * serait indiscernable d'une panne de l'ERP.
     *
     * Aucune mise en cache non plus : un document nominatif retenu ici serait
     * servi à la requête suivante, quel que soit le client qui la fait.
     *
     * @return array{ok: bool, code: int, contenu: string, type: string, erreur: string}
     */
    /**
     * Lancer un appel préparé, et le rejouer si le TRANSPORT a échoué.
     *
     * Les deux points d'appel de cette classe passent par ici : sans quoi la
     * reprise n'aurait couvert que l'un des deux, et le défaut serait revenu
     * par la porte laissée ouverte.
     *
     * ⚠️ La préparation est REFAITE à chaque essai : une ressource cURL est
     * consommée par son exécution, et la rejouer telle quelle ne repart pas.
     *
     * @param callable(): (\CurlHandle|false) $preparer
     *
     * @return array{reponse: string|false, code: int, type: string, erreur: string, reprises: int}
     */
    private function executerAvecReprise(callable $preparer): array
    {
        $reprises = 0;

        while (true) {
            $ch = $preparer();

            if ($ch === false) {
                return ['reponse' => false, 'code' => 0, 'type' => '', 'erreur' => 'cURL indisponible', 'reprises' => $reprises];
            }

            $reponse = curl_exec($ch);
            $resultat = [
                'reponse' => $reponse,
                'code' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
                'type' => (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
                'erreur' => curl_error($ch),
                'reprises' => $reprises,
            ];
            $numero = curl_errno($ch);
            curl_close($ch);

            if ($reponse !== false
                || !in_array($numero, self::ERREURS_REJOUABLES, true)
                || $reprises >= self::RETENTATIVES_RESEAU
            ) {
                return $resultat;
            }

            sleep(self::ATTENTE_RESEAU);
            $reprises++;
        }
    }

    /**
     * Le nombre de reprises, dit dans le message d'erreur.
     *
     * « après 3 reprises » désigne une indisponibilité durable ; un refus sans
     * reprise désigne un incident bref. Les confondre fait chercher au mauvais
     * endroit — c'est ce qui m'a fait accuser ma propre cadence là où l'ERP
     * était simplement en cours de redéploiement.
     */
    private static function mention(string $erreur, int $reprises): string
    {
        return $reprises > 0 ? $erreur . sprintf(' (après %d reprise(s))', $reprises) : $erreur;
    }

    public function telecharger(string $chemin): array
    {
        $tentative = $this->executerAvecReprise(function () use ($chemin) {
            $ch = curl_init($this->base . $chemin);

            if ($ch === false) {
                return false;
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_CONNECTTIMEOUT => self::DELAI_CONNEXION,
                // ⚠️ Plus long que pour un appel ordinaire : l'ERP FABRIQUE le
                // PDF à la demande la première fois, en démarrant un navigateur
                // sans écran. Le délai des appels JSON couperait juste avant la
                // fin, et le client verrait une panne pour un document qui
                // arrivait.
                CURLOPT_TIMEOUT => 60,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/pdf',
                    'Authorization: Bearer ' . $this->jeton,
                ],
            ]);

            return $ch;
        });

        $reponse = $tentative['reponse'];
        $code = $tentative['code'];
        $type = $tentative['type'];
        $erreur = $tentative['erreur'];

        if (!is_string($reponse) || $reponse === '') {
            return [
                'ok' => false,
                'code' => $code,
                'contenu' => '',
                'type' => $type,
                'erreur' => self::mention($erreur !== '' ? $erreur : 'réponse vide', $tentative['reprises']),
            ];
        }

        if ($code < 200 || $code >= 300) {
            // Le corps d'une erreur est du JSON : on en tire le message, qui
            // dit s'il faut attendre ou corriger.
            $donnees = json_decode($reponse, true);
            $message = is_array($donnees) ? (string) ($donnees['message'] ?? '') : '';

            return [
                'ok' => false,
                'code' => $code,
                'contenu' => '',
                'type' => $type,
                'erreur' => $message !== '' ? $message : ('HTTP ' . $code),
            ];
        }

        return ['ok' => true, 'code' => $code, 'contenu' => $reponse, 'type' => $type, 'erreur' => ''];
    }

    /**
     * @param  bool  $sansCache  ⚠️ POUR CE QU'ON INTERROGE EN BOUCLE.
     *
     * Le cache est une bonne idée pour un catalogue, et une très mauvaise
     * pour un état qui change. Mesuré le 2026-08-26 : la boutique relisait
     * l'avancement d'un calcul de tarif toutes les trois secondes et recevait
     * indéfiniment la PREMIÈRE réponse — « en attente » — alors que le calcul
     * était fini depuis longtemps et que ses vingt-deux prix étaient rangés.
     * Rien ne distingue une réponse en cache d'une réponse fraîche.
     */
    public function appeler(string $methode, string $chemin, ?array $corps = null, bool $sansCache = false): array
    {
        $lisible = strtoupper($methode) === 'GET' && $corps === null && !$sansCache;
        $cle = $lisible ? $this->cleCache($chemin) : '';

        if ($lisible) {
            $enCache = $this->lireCache($cle);

            if ($enCache !== null) {
                $enCache['cache'] = true;

                return $enCache;
            }
        }

        $debut = microtime(true);

        $tentative = $this->executerAvecReprise(function () use ($methode, $chemin, $corps) {
            $ch = curl_init($this->base . $chemin);

            if ($ch === false) {
                return false;
            }

            $entetes = [
                'Accept: application/json',
                'Authorization: Bearer ' . $this->jeton,
            ];

            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => $methode,
                CURLOPT_CONNECTTIMEOUT => self::DELAI_CONNEXION,
                CURLOPT_TIMEOUT => self::DELAI_TOTAL,
                CURLOPT_FOLLOWLOCATION => false,
            ];

            if ($corps !== null) {
                $options[CURLOPT_POSTFIELDS] = json_encode($corps, JSON_UNESCAPED_UNICODE);
                $entetes[] = 'Content-Type: application/json';
            }

            $options[CURLOPT_HTTPHEADER] = $entetes;
            curl_setopt_array($ch, $options);

            return $ch;
        });

        $reponse = $tentative['reponse'];
        $code = $tentative['code'];
        $erreurCurl = $tentative['erreur'];

        $duree = (int) round((microtime(true) - $debut) * 1000);

        if ($reponse === false) {
            return $this->echec($code, self::mention($erreurCurl ?: 'aucune réponse', $tentative['reprises']), $duree);
        }

        $donnees = json_decode((string) $reponse, true);

        if (!is_array($donnees)) {
            // Une réponse non-JSON sur une API JSON signale presque toujours
            // autre chose qu'un bug d'API : page d'erreur du serveur, portail
            // captif, ou redirection avalée. On garde un extrait pour le dire.
            return $this->echec(
                $code,
                'réponse illisible : ' . mb_substr(strip_tags((string) $reponse), 0, 120),
                $duree
            );
        }

        if ($code < 200 || $code >= 300) {
            return $this->echec($code, self::motif($donnees, $code), $duree, $donnees);
        }

        $succes = [
            'ok' => true,
            'code' => $code,
            'donnees' => $donnees,
            'erreur' => '',
            'duree_ms' => $duree,
        ];

        // Seules les lectures réussies entrent en cache : une écriture mise en
        // cache disparaîtrait, une erreur mise en cache durerait.
        if ($lisible) {
            $this->ecrireCache($cle, $succes);
        }

        return $succes;
    }

    /**
     * Le motif d'un refus, dans l'ordre de ce qui aide le plus.
     *
     * ⚠️ CE QUE CETTE METHODE REPARE.
     *
     * L'ERP separe deliberement deux champs sur un refus : `message` porte une
     * phrase generique et sans risque — « This configuration cannot be
     * priced. » — et `reason` porte le motif reel, en francais, qui NOMME ce
     * qui cloche : « Le produit "Adhesif decoupe" ne declare pas cette
     * variable : marge_secrete », « Configuration incomplete : lamination est
     * requise et n'a pas ete transmise ».
     *
     * Le client ne lisait que `message`. Un visiteur francais recevait donc
     * une phrase anglaise qui ne disait pas quoi corriger — alors que la
     * reponse contenait, deux lignes plus bas, exactement ce qu'il fallait
     * lui dire. Un refus qui ne dit pas pourquoi est un refus a moitie rendu.
     *
     * `reason` n'est retenu que sur un 4xx : c'est le code par lequel l'ERP
     * dit « j'ai juge ta demande ». Sur un 5xx il est en peine, et son motif
     * interne n'a rien a faire sous les yeux d'un client.
     *
     * @param  array<string,mixed>  $donnees
     */
    private static function motif(array $donnees, int $code): string
    {
        if ($code >= 400 && $code < 500) {
            $reason = $donnees['reason'] ?? null;

            if (is_string($reason) && trim($reason) !== '') {
                // Borne : un motif est une phrase, pas une trace.
                return mb_substr(trim($reason), 0, 300);
            }
        }

        $message = $donnees['message'] ?? null;

        return is_string($message) && trim($message) !== ''
            ? mb_substr(trim($message), 0, 300)
            : 'HTTP ' . $code;
    }

    /**
     * @param  array<string,mixed>  $donnees
     * @return array{ok: bool, code: int, donnees: array<string,mixed>, erreur: string, duree_ms: int}
     */
    private function echec(int $code, string $erreur, int $duree, array $donnees = []): array
    {
        return [
            'ok' => false,
            'code' => $code,
            'donnees' => $donnees,
            'erreur' => $erreur,
            'duree_ms' => $duree,
        ];
    }
}
