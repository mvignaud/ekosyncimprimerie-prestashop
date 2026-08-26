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
 * 0.23.0 — l'écran « Mes fichiers » du compte client.
 *
 * ⚠️ Comme la 0.21.0 : `install()` ne repasse jamais sur une boutique servie.
 * Un hook ajouté à sa liste y reste lettre morte, sans erreur ni signal.
 *
 * @param Ekosyncimprimerie $module
 */
function upgrade_module_0_23_0($module): bool
{
    if (!method_exists($module, 'poserHookCompte')) {
        return false;
    }

    return $module->poserHookCompte();
}
