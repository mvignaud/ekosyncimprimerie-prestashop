<?php

/**
 * EKO Sync — TopTex
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

declare(strict_types=1);

namespace Eko\Depot;

use Imagick;

/**
 * Le contrôle d'un fichier avant qu'il n'entre en production.
 *
 * ─── CE QU'UN PREFLIGHT DOIT FAIRE, ET CE QU'IL NE DOIT PAS ────────────────
 *
 * Il DIT ce qui ne va pas, et il laisse le client décider quand ce n'est pas
 * bloquant. Un contrôle qui refuse tout ce qui n'est pas parfait renvoie le
 * client chez un concurrent ; un contrôle muet laisse passer un logo en 72 dpi
 * qui sortira flou sur cinquante vêtements.
 *
 * D'où trois niveaux, et trois seulement :
 *
 *   — `refus`   : l'atelier ne peut PAS travailler avec ça (mauvais format,
 *                 fichier illisible, image là où il faut du vectoriel).
 *   — `alerte`  : c'est faisable, mais le rendu en pâtira. Le client voit et
 *                 assume.
 *   — `ok`      : rien à signaler.
 *
 * ─── ELLE NE CONNAÎT AUCUN MÉTIER ──────────────────────────────────────────
 *
 * Trois configurateurs déposent des fichiers, avec des techniques qui n'ont
 * rien à voir — DTF, sérigraphie, broderie, gravure. Ce qui ne change pas,
 * c'est la MESURE : la résolution qu'une image atteint une fois posée sur sa
 * zone, ses proportions, son fond, son espace colorimétrique. Cette classe ne
 * fait que cela ; ce qu'il faut en conclure vient du PROFIL qu'on lui passe.
 *
 * ⚠️ AUCUN CONTRÔLE NE DOIT MENTIR PAR OMISSION. Si Imagick ne sait pas lire
 * le fichier, on le DIT — « non vérifié » — plutôt que de rendre un `ok` qui
 * ferait croire à un contrôle réussi. Un vert qui n'a rien mesuré est pire que
 * pas de contrôle du tout : personne ne rouvrira le fichier.
 */
class Preflight
{
    /** Les extensions que l'on sait ouvrir pour mesurer. */
    private const MESURABLES = ['png', 'jpg', 'jpeg', 'tif', 'tiff', 'psd', 'pdf', 'eps', 'ai'];

    /** Ce qui, par extension, est du dessin vectoriel. */
    private const VECTORIELS = ['pdf', 'ai', 'eps', 'svg'];

    /**
     * @param array{0: float|int, 1: float|int} $mm cotes de la zone
     *
     * @return array{
     *     verdict: string, mesure: bool, messages: list<array{niveau: string, texte: string}>,
     *     details: array<string, string>
     * }
     */
    public static function controler(string $chemin, string $nom, array $profil, array $mm): array
    {
        $procede = $profil + ['nom' => 'ce procédé', 'formats' => [], 'vectoriel' => false,
            'dpi_minimum' => 150, 'dpi_conseille' => 300, 'fond_transparent' => false];
        $zone = self::zone($mm, (int) $procede['dpi_minimum'], (int) $procede['dpi_conseille']);
        $ext = strtolower((string) pathinfo($nom, PATHINFO_EXTENSION));

        $messages = [];
        $details = [];
        $mesure = false;

        if (!in_array($ext, $procede['formats'], true)) {
            $messages[] = [
                'niveau' => 'refus',
                'texte' => sprintf(
                    'Le format « %s » n\'est pas accepté en %s. Formats attendus : %s.',
                    $ext === '' ? 'sans extension' : $ext,
                    $procede['nom'],
                    implode(', ', $procede['formats'])
                ),
            ];

            return self::conclure($messages, $details, false);
        }

        $vectoriel = in_array($ext, self::VECTORIELS, true);

        if ($procede['vectoriel'] && !$vectoriel) {
            $messages[] = [
                'niveau' => 'refus',
                'texte' => sprintf(
                    '%s exige un fichier vectoriel (PDF, AI, EPS ou SVG) : un fichier photo ne s\'y prête pas.',
                    $procede['nom']
                ),
            ];
        }

        if ($ext === 'svg') {
            // Imagick sait ouvrir le SVG, mais son rendu dépend d'une
            // bibliothèque qui n'est pas toujours là. On ne prétend donc rien
            // mesurer plutôt que de rendre une cote inventée.
            $details['nature'] = 'vectoriel (SVG)';
            $messages[] = ['niveau' => 'info', 'texte' => 'Fichier vectoriel : la résolution ne s\'applique pas.'];

            return self::conclure($messages, $details, false);
        }

        if (!in_array($ext, self::MESURABLES, true) || !extension_loaded('imagick')) {
            $messages[] = ['niveau' => 'info', 'texte' => 'Fichier accepté, non mesuré automatiquement.'];

            return self::conclure($messages, $details, false);
        }

        try {
            $img = new Imagick();
            // Une seule page : un PDF de vingt pages n'a pas à être décodé en
            // entier pour qu'on en lise les cotes.
            $img->setResolution((int) $procede['dpi_conseille'], (int) $procede['dpi_conseille']);
            $img->readImage($chemin . '[0]');
            $mesure = true;
        } catch (\Throwable $e) {
            $messages[] = [
                'niveau' => 'refus',
                'texte' => 'Fichier illisible : il est peut-être corrompu, ou protégé par un mot de passe.',
            ];

            return self::conclure($messages, $details, false);
        }

        $px = [$img->getImageWidth(), $img->getImageHeight()];
        $details['pixels'] = $px[0] . ' × ' . $px[1] . ' px';
        $details['nature'] = $vectoriel ? 'vectoriel' : 'image';

        if (!$vectoriel) {
            self::mesurerResolution($messages, $details, $px, $zone);
            self::mesurerProportions($messages, $px, $zone);
            self::mesurerFond($messages, $details, $img, (bool) $procede['fond_transparent'], (string) $procede['nom']);
        }

        self::mesurerCouleur($messages, $details, $img);

        $img->clear();

        return self::conclure($messages, $details, $mesure);
    }

    /**
     * @param list<array{niveau: string, texte: string}> $messages
     * @param array<string, string>                     $details
     * @param array{0: int, 1: int}                     $px
     * @param array<string, mixed>                      $zone
     */
    private static function mesurerResolution(array &$messages, array &$details, array $px, array $zone): void
    {
        $dpiL = self::dpiEffectif($px[0], (float) $zone['largeur_mm']);
        $dpiH = self::dpiEffectif($px[1], (float) $zone['hauteur_mm']);
        $dpi = min($dpiL, $dpiH);

        $details['resolution'] = round($dpi) . ' dpi à la taille de la zone';

        if ($dpi < $zone['dpi_minimum']) {
            $messages[] = [
                'niveau' => 'refus',
                'texte' => sprintf(
                    'Résolution insuffisante : %d dpi une fois posé sur la zone, alors qu\'il en faut %d au minimum. '
                    . 'Il faudrait une image d\'au moins %d × %d px.',
                    (int) $dpi,
                    $zone['dpi_minimum'],
                    $zone['pixels_minimum'][0],
                    $zone['pixels_minimum'][1]
                ),
            ];
        } elseif ($dpi < $zone['dpi_conseille']) {
            $messages[] = [
                'niveau' => 'alerte',
                'texte' => sprintf(
                    'Résolution de %d dpi : imprimable, mais %d dpi donnerait un rendu franc '
                    . '(soit %d × %d px pour cette zone).',
                    (int) $dpi,
                    $zone['dpi_conseille'],
                    $zone['pixels_conseilles'][0],
                    $zone['pixels_conseilles'][1]
                ),
            ];
        }
    }

    /**
     * ⚠️ UN FICHIER AUX BONNES PROPORTIONS N'EST PAS UN DÉTAIL. Posé sur une
     * zone dont il n'a pas le rapport, il sera soit déformé, soit recadré — et
     * dans les deux cas le client découvre le résultat sur le vêtement.
     *
     * @param list<array{niveau: string, texte: string}> $messages
     * @param array{0: int, 1: int}                      $px
     * @param array<string, mixed>                       $zone
     */
    private static function mesurerProportions(array &$messages, array $px, array $zone): void
    {
        if ($px[1] <= 0 || (float) $zone['hauteur_mm'] <= 0) {
            return;
        }

        $rapportFichier = $px[0] / $px[1];
        $rapportZone = (float) $zone['largeur_mm'] / (float) $zone['hauteur_mm'];
        $ecart = abs($rapportFichier - $rapportZone) / $rapportZone;

        if ($ecart > 0.05) {
            $messages[] = [
                'niveau' => 'alerte',
                'texte' => sprintf(
                    'Les proportions du fichier ne sont pas celles de la zone (%s contre %s). '
                    . 'Le visuel sera centré sans être déformé : il restera de la place sur les côtés.',
                    self::rapport($rapportFichier),
                    self::rapport($rapportZone)
                ),
            ];
        }
    }

    /**
     * @param list<array{niveau: string, texte: string}> $messages
     * @param array<string, string>                     $details
     */
    private static function mesurerFond(array &$messages, array &$details, Imagick $img, bool $exigeTransparent, string $nomProcede): void
    {
        $transparent = false;

        try {
            $transparent = $img->getImageAlphaChannel() && $img->getImageColors() > 0
                && $img->getImagePixelColor(0, 0)->getColorValue(Imagick::COLOR_ALPHA) < 0.5;
        } catch (\Throwable $e) {
            return;
        }

        $details['fond'] = $transparent ? 'transparent' : 'opaque';

        if ($exigeTransparent && !$transparent) {
            $messages[] = [
                'niveau' => 'alerte',
                'texte' => sprintf(
                    'Le fond ne semble pas transparent. En %s, un fond blanc est reproduit tel quel : '
                    . 'le rectangle se verra sur l\'article.',
                    $nomProcede
                ),
            ];
        }
    }

    /**
     * @param list<array{niveau: string, texte: string}> $messages
     * @param array<string, string>                     $details
     */
    private static function mesurerCouleur(array &$messages, array &$details, Imagick $img): void
    {
        try {
            $espace = $img->getImageColorspace();
        } catch (\Throwable $e) {
            return;
        }

        $noms = [
            Imagick::COLORSPACE_SRGB => 'RVB',
            Imagick::COLORSPACE_RGB => 'RVB',
            Imagick::COLORSPACE_CMYK => 'CMJN',
            Imagick::COLORSPACE_GRAY => 'niveaux de gris',
        ];

        $details['couleurs'] = $noms[$espace] ?? 'espace ' . $espace;
    }

    /**
     * @param list<array{niveau: string, texte: string}> $messages
     * @param array<string, string>                     $details
     *
     * @return array{verdict: string, mesure: bool, messages: list<array{niveau: string, texte: string}>, details: array<string, string>}
     */
    private static function conclure(array $messages, array $details, bool $mesure): array
    {
        $verdict = 'ok';

        foreach ($messages as $m) {
            if ($m['niveau'] === 'refus') {
                $verdict = 'refus';
                break;
            }

            if ($m['niveau'] === 'alerte') {
                $verdict = 'alerte';
            }
        }

        return ['verdict' => $verdict, 'mesure' => $mesure, 'messages' => $messages, 'details' => $details];
    }

    /**
     * Les cotes d'une zone, et ce qu'elles réclament en pixels.
     *
     * @param array{0: float|int, 1: float|int} $mm
     *
     * @return array<string, mixed>
     */
    public static function zone(array $mm, int $dpiMinimum = 150, int $dpiConseille = 300): array
    {
        $l = (float) $mm[0];
        $h = (float) $mm[1];

        return [
            'largeur_mm' => $l,
            'hauteur_mm' => $h,
            'dpi_minimum' => $dpiMinimum,
            'dpi_conseille' => $dpiConseille,
            'pixels_minimum' => [self::pixels($l, $dpiMinimum), self::pixels($h, $dpiMinimum)],
            'pixels_conseilles' => [self::pixels($l, $dpiConseille), self::pixels($h, $dpiConseille)],
        ];
    }

    /**
     * ⚠️ Un pouce vaut 25,4 mm — pas 25. L'écart paraît dérisoire ; sur un dos
     * complet de 400 mm il fait quarante pixels, et c'est justement le genre de
     * marge qu'un contrôle « au pixel près » refuserait.
     */
    public static function pixels(float $mm, int $dpi): int
    {
        return (int) ceil($mm / 25.4 * $dpi);
    }

    public static function dpiEffectif(int $pixels, float $mm): float
    {
        return $mm <= 0 ? 0.0 : round($pixels / ($mm / 25.4), 1);
    }

    private static function rapport(float $r): string
    {
        return number_format($r, 2, ',', '') . ':1';
    }
}
