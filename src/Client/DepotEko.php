<?php

/**
 * EKO Sync — Imprimerie
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

/**
 * Accès en lecture aux données E-KO dont la boutique a besoin.
 *
 * Une couche au-dessus du client HTTP, qui connaît les chemins et la forme des
 * réponses. Elle ne calcule rien et ne transforme rien : ce que l'ERP dit fait
 * foi, y compris pour les montants.
 *
 * La correspondance client PrestaShop → tiers E-KO se fait par ADRESSE
 * E-MAIL. C'est la seule clé commune aux deux systèmes aujourd'hui, et les
 * endpoints la prennent en filtre (`GET /tiers?email=`). Une fois résolue, elle
 * est mémorisée côté boutique pour ne pas interroger l'ERP à chaque page.
 */

declare(strict_types=1);

namespace Eko\SyncImprimerie\Client;

class DepotEko
{
    public function __construct(private readonly ClientEko $client) {}

    /**
     * Retrouve le tiers E-KO correspondant à une adresse e-mail.
     *
     * @return array{trouve: bool, tier: array<string,mixed>|null, erreur: string, ambigu: bool}
     */
    public function tierParEmail(string $email): array
    {
        $email = trim(mb_strtolower($email));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['trouve' => false, 'tier' => null, 'erreur' => 'Adresse e-mail invalide.', 'ambigu' => false];
        }

        $r = $this->client->appeler('GET', '/api/v1/tiers?email=' . rawurlencode($email));

        if (!$r['ok']) {
            return ['trouve' => false, 'tier' => null, 'erreur' => $r['erreur'], 'ambigu' => false];
        }

        $lignes = $this->lignes($r['donnees']);

        if ($lignes === []) {
            return ['trouve' => false, 'tier' => null, 'erreur' => '', 'ambigu' => false];
        }

        // Plusieurs tiers sur la même adresse : on ne devine pas. Rattacher un
        // client au mauvais tiers lui montrerait les documents d'un autre — le
        // pire défaut possible sur cette fonction.
        if (count($lignes) > 1) {
            return [
                'trouve' => false,
                'tier' => null,
                'erreur' => sprintf('%d tiers partagent cette adresse : rattachement à faire à la main.', count($lignes)),
                'ambigu' => true,
            ];
        }

        return ['trouve' => true, 'tier' => $lignes[0], 'erreur' => '', 'ambigu' => false];
    }

    /**
     * Crée un devis dans l'ERP à partir d'une commande de la boutique.
     *
     * ─── LA CLÉ D'IDEMPOTENCE ──────────────────────────────────────────────
     *
     * L'ERP sait refuser un doublon, mais seulement si on le lui demande :
     * l'en-tête `Idempotency-Key` est facultatif côté serveur. Sans lui, un
     * client qui recharge la page de confirmation, ou un module de paiement
     * qui rejoue sa notification, créerait un SECOND devis — et l'opérateur
     * verrait deux fois la même commande sans savoir laquelle honorer.
     *
     * La clé est dérivée de la commande, jamais tirée au hasard : deux envois
     * de la MÊME commande doivent porter la MÊME clé, sinon la protection ne
     * sert à rien.
     *
     * ─── LE DÉLAI ──────────────────────────────────────────────────────────
     *
     * Cinq secondes, et non les quinze de la classe. Cet appel a lieu pendant
     * que le client attend sa page de confirmation. Un ERP qui traîne ne doit
     * pas retenir la boutique : mieux vaut un devis manquant, qu'on rattrape,
     * qu'une commande que le client croit perdue.
     *
     * ⚠️ `appeler()` rend le statut HTTP sous la clé `code`, et le devis rend
     * SON code sous la même clé dans `donnees.data`. Deux « code » de nature
     * différente : on nomme le premier `http` en sortie pour qu'aucun
     * appelant ne les confonde.
     *
     * Le rejeu n'est pas remonté : l'ERP le signale par un en-tête de
     * réponse, que le client HTTP ne capture pas. Ce n'est pas gênant — un
     * rejeu rend le MÊME devis, donc le même code, et rien ne casse.
     *
     * @param  array<string, mixed>  $charge
     * @return array{ok: bool, id: int, code: string, erreur: string, http: int}
     */
    public function creerDevis(array $charge, string $cleIdempotence): array
    {
        $r = $this->client->appeler(
            'POST',
            '/api/v1/proposals',
            $charge,
            ['Idempotency-Key: ' . $cleIdempotence],
            5
        );

        $http = (int) ($r['code'] ?? 0);

        if (!$r['ok']) {
            return [
                'ok' => false,
                'id' => 0,
                'code' => '',
                'erreur' => (string) ($r['erreur'] ?? 'appel refusé'),
                'http' => $http,
            ];
        }

        $donnees = is_array($r['donnees'] ?? null) ? $r['donnees'] : [];
        $devis = is_array($donnees['data'] ?? null) ? $donnees['data'] : [];

        return [
            'ok' => true,
            'id' => (int) ($devis['id'] ?? 0),
            'code' => (string) ($devis['code'] ?? ''),
            'erreur' => '',
            'http' => $http,
        ];
    }

    /**
     * Documents d'un tiers, tous types confondus.
     *
     * @return array<string, array{ok: bool, nombre: int, lignes: list<array<string,mixed>>, erreur: string}>
     */
    public function documentsDuTier(int $tierId, int $parPage = 25): array
    {
        $sortie = [];

        foreach ([
            'devis' => '/api/v1/proposals',
            'commandes' => '/api/v1/sales-orders',
            'factures' => '/api/v1/invoices',
        ] as $cle => $chemin) {
            $r = $this->client->appeler(
                'GET',
                $chemin . '?tier_id=' . $tierId . '&per_page=' . max(1, min(100, $parPage))
            );

            $lignes = $r['ok'] ? $this->lignes($r['donnees']) : [];

            $sortie[$cle] = [
                'ok' => $r['ok'],
                // Le total renvoyé par l'API prime sur le nombre de lignes de
                // la page : afficher « 3 factures » alors qu'il y en a 40 sur
                // quatre pages serait faux.
                'nombre' => $r['ok'] ? $this->total($r['donnees'], count($lignes)) : 0,
                'lignes' => $lignes,
                'erreur' => $r['ok'] ? '' : $r['erreur'],
            ];
        }

        return $sortie;
    }

    /**
     * URL du PDF d'un document. E-KO les expose déjà — rien à générer côté
     * boutique.
     */
    public function cheminPdf(string $type, int $id): ?string
    {
        return match ($type) {
            'devis' => '/api/v1/proposals/' . $id . '/pdf',
            'factures' => '/api/v1/invoices/' . $id . '/pdf',
            default => null,
        };
    }

    /**
     * Extrait la liste d'une réponse paginée.
     *
     * Laravel enveloppe sous `data`, mais certains endpoints rendent un tableau
     * nu. On accepte les deux plutôt que de supposer.
     *
     * @param  array<string,mixed>  $donnees
     * @return list<array<string,mixed>>
     */
    private function lignes(array $donnees): array
    {
        $brut = $donnees['data'] ?? $donnees;

        if (!is_array($brut)) {
            return [];
        }

        return array_values(array_filter($brut, 'is_array'));
    }

    /** @param  array<string,mixed>  $donnees */
    private function total(array $donnees, int $defaut): int
    {
        foreach (['total', 'meta.total'] as $chemin) {
            $v = $donnees;

            foreach (explode('.', $chemin) as $cle) {
                if (!is_array($v) || !array_key_exists($cle, $v)) {
                    $v = null;
                    break;
                }

                $v = $v[$cle];
            }

            if (is_int($v) || (is_string($v) && ctype_digit($v))) {
                return (int) $v;
            }
        }

        return $defaut;
    }
}
