<?php

/**
 * EKO Sync — Imprimerie
 *
 * Contrôle : les contrôleurs JSON rendent-ils le bon STATUT HTTP ?
 *
 * ─── Pourquoi ce contrôle existe ───────────────────────────────────────────
 *
 * Le 2026-08-13, `prix` et `catalogue` rendaient HTTP 500 sur TOUTES leurs
 * réponses, succès compris, derrière un corps JSON parfaitement correct. Rien
 * ne le montrait : le JavaScript du configurateur lit `d.ok` et ne regarde
 * jamais le statut, donc la boutique marchait. Seul le journal du serveur
 * savait, et il disait « fatale Smarty » — pas « le configurateur est cassé ».
 *
 * Un défaut qu'aucun écran ne montre a besoin d'un contrôle qui le regarde.
 * Celui-ci interroge la boutique comme le ferait le navigateur et compare le
 * statut, pas le corps.
 *
 * ⚠️ À LANCER DEPUIS UNE MACHINE QUI ATTEINT LA BOUTIQUE. Mesuré le
 * 2026-08-13 : le shell SSH de l'hébergement mutualisé OVH n'a pas d'accès
 * HTTP sortant vers le site qu'il héberge — « Connection refused » sur le
 * port 443, alors que le cron et le web y accèdent. Lancé là-bas, ce contrôle
 * ne dit rien de la boutique ; il le dit maintenant au lieu d'aligner des
 * « reçu 0 ».
 *
 * Usage : php dev/verifier-statut-json.php [https://boutique]
 */

require_once __DIR__ . '/../src/Configurateur/ReponseJson.php';

use Eko\SyncImprimerie\Configurateur\ReponseJson;

// ⚠️ AUCUN DÉFAUT. Ce contrôle interroge une boutique en HTTP : lancé sans
// argument, un défaut ferait taper le site de quelqu'un d'autre. Et un domaine
// de client n'a rien à faire dans un module distribué.
if (($argv[1] ?? '') === '') {
    fwrite(STDERR, "usage : php verifier-statut-json.php <https://boutique> [id_product]\n");
    exit(2);
}

$base = rtrim($argv[1], '/');

// ⚠️ L'IDENTIFIANT DE FICHE VIENT AUSSI DE LA LIGNE DE COMMANDE.
//
// Il était écrit en dur — celui d'un produit d'une boutique précise. Ailleurs,
// les trois derniers cas ne veulent plus rien dire : ils interrogent une fiche
// qui n'existe pas, et rendent un refus qu'on lit comme un succès du contrôle.
//
// Sans argument, on se limite aux cas qui ne demandent aucune fiche liée.
$fiche = (int) ($argv[2] ?? 0);
$echecs = 0;

/** Un cas : ce qu'on demande, ce qu'on doit recevoir. */
$cas = [
    // Refus de validation — une saisie, pas un incident.
    ['prix, sans produit', 'prix', [], 422],
    ['prix, quantité nulle', 'prix', ['id_product' => 1, 'quantity' => 0], 422],
    ['prix, fiche non liée', 'prix', ['id_product' => 999999, 'quantity' => 1], 422],
    ['catalogue, fiche non liée', 'catalogue', ['id_product' => 999999], 422],
];

// Les cas qui exigent une fiche RÉELLEMENT liée : ils ne sont joués que si on
// en a nommé une.
if ($fiche > 0) {
    $cas[] = ['catalogue, configuration vide', 'catalogue', ['id_product' => $fiche], 422];
    // Refus venu de l'ERP : il a jugé la demande, ce n'est pas une panne.
    $cas[] = ['catalogue, combinaison inconnue', 'catalogue', ['id_product' => $fiche, 'selection[]' => 'zzz'], 422];
    // Succès — c'est CE cas qui rendait 500 sans que personne ne le voie.
    $cas[] = ['catalogue, arbre d\'options', 'catalogue', ['id_product' => $fiche, 'quoi' => 'arbre'], 200];
} else {
    fwrite(STDERR, "  (sans identifiant de fiche, les trois cas qui en demandent une sont sautés)\n");
}

foreach ($cas as [$nom, $controleur, $parametres, $attendu]) {
    $url = $base . '/index.php?fc=module&module=ekosyncimprimerie&controller=' . $controleur;

    foreach ($parametres as $cle => $valeur) {
        $url .= '&' . $cle . '=' . rawurlencode((string) $valeur);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        // Exactement ce que le configurateur envoie — et surtout PAS
        // `Accept: application/json`, qui est le seul en-tête auquel
        // `Controller::isAjax()` de PrestaShop 9 réagit. C'est justement en
        // s'en passant qu'on retombe dans le chemin qui rendait 500.
        CURLOPT_HTTPHEADER => ['X-Requested-With: XMLHttpRequest'],
    ]);
    $corps = (string) curl_exec($ch);
    $statut = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $erreurReseau = curl_error($ch);

    // Un contrôle qui n'a pas pu appeler n'a rien contrôlé : il le dit et
    // s'arrête, plutôt que de faire passer une panne de réseau pour un défaut
    // de la boutique.
    if ($statut === 0) {
        echo 'INJOIGNABLE : ', $erreurReseau ?: 'aucune réponse', PHP_EOL,
            'La boutique n\'a pas été interrogée — voir l\'en-tête de ce fichier.', PHP_EOL;

        exit(2);
    }

    $json = json_decode($corps, true);
    $ok = $statut === $attendu
        && is_array($json)
        && str_contains($type, 'application/json');

    $echecs += $ok ? 0 : 1;

    printf(
        "%s attendu %d — reçu %d %s  %s\n",
        mb_str_pad($nom, 34),
        $attendu,
        $statut,
        str_contains($type, 'application/json') ? '(json)' : '(' . strtok($type, ';') . ')',
        $ok ? 'OK' : '*** ÉCHEC ***'
    );
}

// Le tri des échecs d'ERP : sa faute (4xx, il a jugé) ou la nôtre (le reste).
foreach ([[404, 422], [422, 422], [500, 502], [503, 502], [0, 502]] as [$code, $attendu]) {
    $recu = ReponseJson::statutAmont($code);
    $echecs += $recu === $attendu ? 0 : 1;

    printf(
        "%s attendu %d — reçu %d  %s\n",
        mb_str_pad('statutAmont(' . $code . ')', 34),
        $attendu,
        $recu,
        $recu === $attendu ? 'OK' : '*** ÉCHEC ***'
    );
}

echo $echecs === 0 ? "\nTOUT VERT\n" : "\n{$echecs} ÉCHEC(S)\n";

exit($echecs === 0 ? 0 : 1);
