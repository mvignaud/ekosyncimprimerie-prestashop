<?php

/**
 * EKO Sync — Imprimerie
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

namespace Eko\SyncImprimerie\Configurateur;

/**
 * La sortie des contrôleurs qui rendent du JSON, et la seule.
 *
 * ─── Pourquoi cette classe existe ──────────────────────────────────────────
 *
 * `prix.php` et `catalogue.php` répondaient depuis `postProcess()` par
 * `ajaxRender()`, en croyant — c'était écrit dans les deux fichiers — que
 * cette méthode « pose l'en-tête et termine ». MESURÉ le 2026-08-13 dans le
 * cœur de PrestaShop 9.1.4 : elle ne fait ni l'un ni l'autre.
 *
 *   Controller.php:722-738 — `ajaxRender()` pose `Cache-Control` et
 *   `X-Robots-Tag`, puis un simple `echo`. Pas de `Content-Type`, pas de fin
 *   de requête.
 *
 * Le contrôleur rendait donc la main à `Controller::run()`, qui poursuivait.
 * Et la branche AJAX de `run()` ne se déclenchait jamais :
 *
 *   Controller.php:252 — `$this->ajax = $this->isAjax();` ÉCRASE le
 *   `public $ajax = true` déclaré par le contrôleur, au moment même de sa
 *   construction.
 *
 *   Controller.php:269-281 — `isAjax()` ne regarde que le paramètre `ajax`
 *   (déclaré déprécié) et l'en-tête `Accept: application/json`. Il ne regarde
 *   PAS `X-Requested-With`, qui est pourtant le seul en-tête que le JavaScript
 *   du configurateur envoyait.
 *
 * Résultat : `run()` partait en `display()`, donc en
 * `smartyOutputContent($this->template)` avec un `template` à `null`, donc en
 * fatale Smarty « Missing '$template' parameter ». Comme
 * `FrontController::init()` (ligne 240) a ouvert un `ob_start()`, les en-têtes
 * n'étaient pas encore partis : PHP posait un 500 sur une réponse dont le
 * corps — le JSON, déjà dans le tampon — était parfaitement correct.
 *
 * Toutes les réponses étaient concernées, LES SUCCÈS COMPRIS. Le configurateur
 * fonctionnait quand même : son JavaScript lit `d.ok` et ne regarde jamais le
 * statut HTTP. Seuls les journaux savaient.
 *
 * ─── Ce que cette classe garantit ──────────────────────────────────────────
 *
 * Un statut voulu, un `Content-Type` juste, et une requête qui S'ARRÊTE ici —
 * PrestaShop n'a plus l'occasion de chercher un gabarit qui n'existe pas. Les
 * quatre autres contrôleurs front du module faisaient déjà cela à la main,
 * chacun avec sa copie des quatre mêmes lignes ; c'est cette copie-là qui vit
 * désormais à un seul endroit.
 */
final class ReponseJson
{
    /**
     * La demande est comprise, mais inexploitable : champ absent, quantité
     * nulle, fiche non liée, combinaison que le fournisseur ne fabrique pas.
     *
     * C'est une SAISIE, pas un incident. Le distinguer du 500 est tout l'objet
     * de cette classe : sans quoi une supervision ne peut ni voir une vraie
     * panne — noyée dans le bruit —, ni ignorer le bruit sans risquer de rater
     * la panne.
     */
    public const REFUS = 422;

    /** La boutique a échoué : panier impossible à créer, exception inattendue. */
    public const PANNE = 500;

    /** L'ERP n'a pas répondu, ou a répondu une erreur qui lui appartient. */
    public const AMONT = 502;

    /**
     * Le statut à rendre quand l'appel à l'ERP a échoué.
     *
     * Un 4xx de l'ERP juge la demande — configuration impossible, référence
     * inconnue : c'est un refus, il se rend en 422. Tout le reste — 5xx,
     * délai dépassé, réponse illisible, `code` à 0 quand cURL n'a rien eu —
     * est une panne d'un service dont nous dépendons : 502.
     */
    public static function statutAmont(int $code): int
    {
        return ($code >= 400 && $code < 500) ? self::REFUS : self::AMONT;
    }

    /**
     * Rend le JSON et termine la requête.
     *
     * @param array<string, mixed> $donnees
     *
     * @return never
     */
    public static function rendre(array $donnees, int $statut = 200)
    {
        // `smartyOutputContent()` écrivait le cookie avant de rendre la page.
        // On sort avant lui : sans cette ligne, le panier invité créé par le
        // chiffrage perdrait son identifiant, et l'appel suivant en créerait
        // un autre — avec la configuration du premier restée en arrière.
        $cookie = \Context::getContext()->cookie ?? null;

        if ($cookie instanceof \Cookie) {
            $cookie->write();
        }

        if (!headers_sent()) {
            http_response_code($statut);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('X-Robots-Tag: noindex, nofollow', true);
        }

        echo (string) json_encode($donnees, JSON_UNESCAPED_UNICODE);

        exit;
    }
}
