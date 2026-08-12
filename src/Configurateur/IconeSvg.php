<?php

/**
 * EKO Sync — Imprimerie
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

/**
 * L'icône SVG déposée par le marchand.
 *
 * ─── POURQUOI UN SVG DÉPOSÉ DEMANDE PLUS DE SOIN QU'UNE IMAGE ──────────────
 *
 * Un SVG n'est pas une image : c'est du BALISAGE, exécuté par le navigateur
 * dans le contexte de la page. Il peut porter du script, des gestionnaires
 * d'événements, des ressources distantes. Déposé tel quel dans un dossier
 * public et affiché en ligne dans une fiche produit, il donne à quiconque peut
 * téléverser un moyen d'exécuter du code chez chaque visiteur.
 *
 * On ne fait donc pas confiance au fichier reçu, même venant du back-office :
 * on le RÉÉCRIT, en n'y laissant que ce qu'une icône a besoin d'être.
 *
 * ─── OÙ IL EST RANGÉ, ET POURQUOI PAS DANS LE MODULE ───────────────────────
 *
 * Dans `img/ekosync/`, jamais dans `modules/ekosyncimprimerie/`. Ce dernier est
 * effacé à chaque réinstallation du module — un logo déposé par le marchand y
 * disparaîtrait sans prévenir, et il n'aurait aucune raison de faire le lien.
 *
 * Le nom du fichier est ENGENDRÉ, jamais celui du dépôt : un nom reçu peut
 * porter des séparateurs de chemin, une double extension, ou simplement écraser
 * le fichier d'à côté.
 */

declare(strict_types=1);

namespace Eko\SyncImprimerie\Configurateur;

final class IconeSvg
{
    /** Le dossier de dépôt, relatif à la racine de la boutique. */
    public const DOSSIER = 'img/ekosync/';

    /** 64 Ko : large pour une icône, étroit pour une charge utile. */
    private const POIDS_MAX = 65536;

    /**
     * Range un fichier reçu, et rend son chemin web.
     *
     * @param  array<string, mixed>  $fichier  une entrée de `$_FILES`
     *
     * @return array{ok: bool, chemin?: string, message?: string}
     */
    public static function deposer(array $fichier): array
    {
        $erreur = (int) ($fichier['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($erreur !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'Le fichier n\'est pas arrivé au bout.'];
        }

        $source = (string) ($fichier['tmp_name'] ?? '');

        // ⚠️ `is_uploaded_file` et rien d'autre : sans elle, un chemin fabriqué
        // dans la requête ferait lire n'importe quel fichier du serveur.
        if ($source === '' || !is_uploaded_file($source)) {
            return ['ok' => false, 'message' => 'Dépôt refusé.'];
        }

        if ((int) ($fichier['size'] ?? 0) > self::POIDS_MAX) {
            return ['ok' => false, 'message' => 'Le fichier dépasse 64 Ko.'];
        }

        $brut = (string) file_get_contents($source);
        $propre = self::assainir($brut);

        if ($propre === null) {
            return ['ok' => false, 'message' => 'Ce fichier n\'est pas un SVG exploitable.'];
        }

        $dossier = rtrim(_PS_ROOT_DIR_, '/') . '/' . self::DOSSIER;

        if (!is_dir($dossier) && !@mkdir($dossier, 0755, true) && !is_dir($dossier)) {
            return ['ok' => false, 'message' => 'Le dossier de dépôt n\'a pas pu être créé.'];
        }

        // Un `index.php` vide : sans lui, le dossier se laisse parcourir sur
        // les serveurs qui listent les répertoires par défaut.
        $garde = $dossier . 'index.php';

        if (!is_file($garde)) {
            @file_put_contents($garde, "<?php\nheader('Location: ../');\n");
        }

        // Le nom vient de NOUS : md5 du contenu, donc deux dépôts du même
        // fichier n'en font qu'un, et aucun caractère du dépôt ne s'y glisse.
        $nom = md5($propre) . '.svg';

        if (@file_put_contents($dossier . $nom, $propre) === false) {
            return ['ok' => false, 'message' => 'Le fichier n\'a pas pu être écrit.'];
        }

        return ['ok' => true, 'chemin' => '/' . self::DOSSIER . $nom];
    }

    /**
     * Réécrit un SVG en n'y laissant que ce qu'une icône a besoin d'être.
     *
     * Rend `null` si ce n'en est pas un — mieux vaut refuser que servir un
     * fichier dont on ne sait pas ce qu'il contient.
     */
    public static function assainir(string $brut): ?string
    {
        // Un fichier SVG commence rarement par `<svg` : il porte d'abord son
        // prologue XML, parfois un DOCTYPE, souvent la signature de son
        // logiciel. Exiger `<svg` en tête reviendrait à tout refuser.
        $svg = preg_replace('#^(?:\s|<\?xml\b[^>]*\?>|<!DOCTYPE[^>]*>|<!--.*?-->)+#is', '', trim($brut)) ?? '';

        if ($svg === '' || stripos($svg, '<svg') !== 0) {
            return null;
        }

        // Les éléments à bannir, dans leurs deux écritures : appariés, et
        // auto-fermants. `style` compris — il peut charger une ressource
        // distante et suivre le visiteur d'une page à l'autre.
        $bannis = 'script|style|foreignObject|iframe|embed|object|image|use|a|set|animate';

        // ⚠️ ON RETIRE, on ne COMMENTE PAS. Une première version remplaçait
        // `<image` par `<!--image` — un commentaire jamais refermé, qui avalait
        // tout ce qui suivait, `</svg>` compris. Le fichier devenait illisible,
        // et inséré dans une page, le commentaire ouvert aurait mangé la suite
        // du document. Mesuré sur un dessin de fournisseur.
        $svg = preg_replace('#<(' . $bannis . ')\b[^>]*>.*?</\1\s*>#is', '', $svg) ?? '';
        $svg = preg_replace('#<(' . $bannis . ')\b[^>]*/?>#i', '', $svg) ?? '';
        // Les gestionnaires d'événements, sous toutes leurs formes.
        $svg = preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $svg) ?? '';
        // Une adresse exécutable dans un attribut qui a survécu.
        $svg = preg_replace('#(href|xlink:href|src)\s*=\s*("|\')\s*(javascript|data)\s*:[^"\']*\2#i', '', $svg) ?? '';

        $svg = trim($svg);

        // Le document doit encore se refermer : un SVG tronqué par le ménage
        // ne s'affiche nulle part, et vaut mieux être refusé que servi.
        if (stripos($svg, '<svg') !== 0 || stripos($svg, '</svg') === false) {
            return null;
        }

        return $svg;
    }
}
