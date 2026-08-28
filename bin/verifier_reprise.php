<?php
/**
 * Le contrôle de la reprise réseau — `php bin/verifier_reprise.php`.
 *
 * Un déploiement de l'ERP arrête FrankenPHP de 2 à 16 secondes ; pendant cette
 * fenêtre le port 443 refuse net. Sans reprise, la tâche en cours mourait, et
 * le client voyait une panne pour une coupure de quelques secondes.
 *
 * Ce script éprouve la reprise sur un port réellement fermé — le refus est le
 * même que celui d'un service arrêté. Il vérifie aussi une INVARIANTE de
 * structure : un seul `curl_exec` dans le client, dans l'exécuteur. C'est ce
 * qui garantit qu'aucun appel ne contourne la reprise ; la version d'avant en
 * avait deux, et corriger l'un aurait laissé l'autre nu.
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

require_once __DIR__ . '/../src/Client/ClientEko.php';

$echecs = [];
$verifier = static function (string $cas, bool $tenu, string $detail) use (&$echecs): void {
    if (!$tenu) {
        $echecs[] = sprintf('%s — %s', $cas, $detail);
    }
    printf("  %-46s %s\n", $cas, $tenu ? 'ok' : 'ÉCHEC : ' . $detail);
};

/* ── L'invariante de structure ────────────────────────────────────────────── */
$source = (string) file_get_contents(__DIR__ . '/../src/Client/ClientEko.php');
$verifier(
    'un seul curl_exec dans le client',
    substr_count($source, 'curl_exec(') === 1,
    substr_count($source, 'curl_exec(') . ' occurrences — un appel contourne la reprise'
);
$verifier(
    'le délai dépassé n\'est PAS rejoué',
    !preg_match('/ERREURS_REJOUABLES = \[[^]]*\b28\b/s', $source),
    'le code 28 est dans la liste : un appel lent serait rejoué, chargeant un serveur déjà en peine'
);

/* ── La reprise, sur un port qui refuse ───────────────────────────────────── */
$classe = new ReflectionClass(Eko\SyncImprimerie\Client\ClientEko::class);
$client = $classe->newInstanceWithoutConstructor();
foreach (['base' => 'https://127.0.0.1:9', 'jeton' => 'sans-importance'] as $nom => $valeur) {
    if ($classe->hasProperty($nom)) {
        $p = $classe->getProperty($nom);
        $p->setValue($client, $valeur);
    }
}

$attendu = $classe->getConstant('RETENTATIVES_RESEAU') * $classe->getConstant('ATTENTE_RESEAU');

$depart = microtime(true);
$r = $client->telecharger('/api/v1/rien');
$duree = microtime(true) - $depart;

$verifier(
    sprintf('un service absent est rejoué (~%d s)', $attendu),
    $duree >= $attendu - 1 && $duree < $attendu + 10,
    sprintf('durée %.1f s, attendu ~%d s', $duree, $attendu)
);
$verifier(
    'le message dit combien de reprises',
    str_contains((string) $r['erreur'], 'reprise'),
    'message sans mention de reprise : « ' . $r['erreur'] . ' »'
);

if ($echecs !== []) {
    printf("\nÉCHEC — %d contrôle(s).\n", count($echecs));
    exit(1);
}

echo "\nTous les contrôles passent.\n";
