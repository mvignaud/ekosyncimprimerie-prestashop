<?php

/**
 * EKO Sync — Imprimerie
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

declare(strict_types=1);

use Eko\SyncImprimerie\Client\StockStricker;

/**
 * Le point d'entrée qui déclenche le rapatriement du stock, depuis un cron.
 *
 * ─── POURQUOI UNE URL ET PAS UNE LIGNE DE COMMANDE ────────────────────────
 *
 * Ce compte d'hébergement est cloisonné : le binaire `crontab` n'y existe pas.
 * La planification passe donc par un service extérieur qui appelle une URL —
 * la même mécanique que le cron de sitemap déjà en place.
 *
 * ─── ⚠️ UN JETON PROPRE, ET PAS CELUI DES COMMANDES ──────────────────────
 *
 * Le module porte déjà `EKOSYNC_JETON_ENTRANT`, qui garde le point d'entrée
 * par lequel E-KO fait avancer une commande — il change des statuts et envoie
 * des e-mails aux clients. Le réutiliser ici le sèmerait dans les journaux
 * d'Apache, dans l'historique du service de cron et dans tout référent : une
 * URL est écrite partout, un en-tête ne l'est nulle part.
 *
 * Ce point d'entrée-ci a donc son propre secret, et son pouvoir est étroit :
 * il recopie dans la boutique un stock dont E-KO fait autorité. Le pire qu'un
 * tiers puisse en faire est de le déclencher trop souvent.
 *
 * Le jeton se présente au choix par `Authorization: Bearer …` ou par le
 * paramètre `jeton` — les deux comparés en TEMPS CONSTANT. Une comparaison
 * ordinaire fuit la longueur du préfixe correct, et un attaquant patient
 * reconstitue le secret caractère par caractère.
 */
class EkosyncimprimerieStocksModuleFrontController extends ModuleFrontController
{
    /** @var bool réponse JSON, pas une page */
    public $content_only = true;

    public function initContent(): void
    {
        // ⚠️ TOUT SE JOUE DANS `postProcess()` PUIS `ajaxRender()`.
        // `run()` appelle `initContent()` ensuite, qui meurt sans gabarit —
        // et rend un 500 sans corps ni ligne de journal.
        parent::initContent();
    }

    public function postProcess(): void
    {
        if (!$this->secretValide()) {
            $this->repondre(['ok' => false, 'erreur' => 'jeton_invalide'], 403);
        }

        $module = Module::getInstanceByName('ekosyncimprimerie');
        if (!$module instanceof Module) {
            $this->repondre(['ok' => false, 'erreur' => 'module_indisponible'], 500);
        }

        // `sec` sans écriture : de quoi vérifier la porte sans toucher au stock.
        $ecrire = !Tools::getValue('essai');

        $etat = StockStricker::rapatrier($module, $ecrire);

        if ($etat['erreur'] !== null) {
            $this->repondre(['ok' => false] + $etat, 502);
        }

        $this->journaliser($etat, $ecrire);

        if ($this->journalMuet !== null) {
            $etat['journal'] = 'ILLISIBLE: '.$this->journalMuet;
        }

        // ⚠️ UNE LIGNE ÉCRITE QUI NE SE RELIT PAS EST UN ÉCHEC, et le service
        // de cron doit le voir comme tel : un 200 muet ferait passer une
        // synchronisation à moitié faite pour une réussite, pendant des mois.
        $this->repondre(['ok' => $etat['restantes'] === 0] + $etat, $etat['restantes'] === 0 ? 200 : 500);
    }

    /**
     * Une ligne par passage, pour que « le cron a-t-il tourné ? » ait une réponse.
     *
     * ⚠️ SANS CE JOURNAL, UN PASSAGE SANS CHANGEMENT NE LAISSE AUCUNE TRACE.
     * Or c'est le cas le plus fréquent — et le plus trompeur : un cron arrêté
     * et un cron qui tourne sur un stock stable produisent exactement le même
     * silence en base. On ne saurait les distinguer qu'au moment où l'écart
     * devient visible, c'est-à-dire trop tard.
     *
     * ⚠️ LE CHEMIN SE PREND SUR `_PS_ROOT_DIR_`, ET IL VAUT `/web` ICI.
     * Ce compte est cloisonné : la racine du site n'est PAS
     * `/home/<compte>/web` mais `/web`. Viser le dossier parent envoyait donc
     * le journal vers `//_sync_backups`, qui n'existe pas — et le passage
     * rendait un 200 parfaitement rassurant sans laisser une ligne.
     *
     * `var/logs` est le dossier de journaux de PrestaShop, inscriptible, et
     * refusé au web : vérifié, `/var/logs/` répond 403.
     *
     * @param array<string, mixed> $etat
     */
    private function journaliser(array $etat, bool $ecrire): void
    {
        $chemin = _PS_ROOT_DIR_.'/var/logs/stocks-stricker.log';

        $ligne = sprintf(
            "%s  %s  lues=%d boutique=%d identiques=%d ecrites=%d inconnues=%d restantes=%d %.1fs%s\n",
            date('Y-m-d H:i:s'),
            $ecrire ? 'ecriture' : 'essai   ',
            (int) $etat['lues'],
            (int) $etat['boutique'],
            (int) $etat['identiques'],
            (int) $etat['ecrites'],
            (int) $etat['inconnues'],
            (int) $etat['restantes'],
            (float) $etat['secondes'],
            $etat['erreur'] !== null ? '  ERREUR: '.$etat['erreur'] : ''
        );

        // Un journal qui n'a pas pu s'écrire ne doit PAS faire échouer la
        // synchronisation : la trace est utile, elle n'est pas le travail.
        //
        // ⚠️ MAIS L'ÉCHEC DOIT SE VOIR. Une première version portait un `@`
        // devant cet appel : le journal n'a jamais rien écrit, et rien ne l'a
        // dit — ni erreur, ni code de retour, juste un fichier absent. On
        // remonte donc l'échec dans la réponse, sans faire échouer le travail.
        $ecrit = file_put_contents($chemin, $ligne, FILE_APPEND | LOCK_EX);

        if ($ecrit === false) {
            $this->journalMuet = $chemin;
        }
    }

    /** @var string|null le journal n'a pas pu s'écrire, et il faut le dire */
    private $journalMuet = null;

    /**
     * @param array<string, mixed> $charge
     */
    private function repondre(array $charge, int $code): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $this->ajaxRender((string) json_encode($charge, JSON_UNESCAPED_UNICODE));
        exit;
    }

    /**
     * Le secret présenté est-il celui attendu ?
     */
    private function secretValide(): bool
    {
        $attendu = (string) Configuration::get('EKOSYNC_JETON_STOCKS');

        // Pas de secret configuré = porte fermée. Accepter tout tant que rien
        // n'est réglé laisserait une porte grande ouverte le temps d'une
        // installation — et cette installation-là dure parfois des mois.
        if (strlen($attendu) < 32) {
            return false;
        }

        $entete = (string) ($_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '');

        if (preg_match('/^Bearer\s+(\S+)$/i', $entete, $m) === 1) {
            return hash_equals($attendu, $m[1]);
        }

        $parUrl = (string) Tools::getValue('jeton');

        return $parUrl !== '' && hash_equals($attendu, $parUrl);
    }
}
