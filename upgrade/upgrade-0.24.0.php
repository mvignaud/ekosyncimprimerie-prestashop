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
 * 0.24.0 — la commande de la boutique devient un devis dans l'ERP.
 *
 * ⚠️ Sans cette étape, le hook reste déclaré dans le code et absent de la
 * base : les commandes ne partiraient nulle part, et les fichiers déposés par
 * les clients resteraient en transit indéfiniment — sans la moindre erreur.
 *
 * @param Ekosyncimprimerie $module
 */
function upgrade_module_0_24_0($module): bool
{
    if (!method_exists($module, 'poserPousseeCommande')) {
        return false;
    }

    return $module->poserPousseeCommande();
}
