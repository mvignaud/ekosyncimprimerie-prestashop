<?php

/**
 * EKO Sync — Imprimerie
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 0.21.0 — le bon de commande dans le compte client.
 *
 * ⚠️ CE FICHIER EXISTE PARCE QUE `install()` NE REPASSE JAMAIS.
 *
 * Le module est posé en production depuis le 2026-08-10. Ajouter
 * `displayOrderDetail` à la liste des hooks de `install()` ne l'enregistre
 * donc sur AUCUNE boutique déjà servie : le module se déclare installé, le
 * hook n'existe nulle part, le lien ne s'affiche pas, et rien ne le signale.
 * C'est le même piège que celui d'un gabarit qui cesse d'appeler un hook — une
 * absence sans erreur.
 *
 * @param Ekosyncimprimerie $module
 */
function upgrade_module_0_21_0($module): bool
{
    if (!method_exists($module, 'poserHookCommande')) {
        return false;
    }

    return $module->poserHookCommande();
}
