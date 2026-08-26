<?php

/**
 * EKO Sync — Imprimerie
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

declare(strict_types=1);

namespace Eko\SyncImprimerie\Configurateur;

/**
 * Le gabarit PDF téléchargeable : le plan de travail du client.
 *
 * ─── LA GÉOMÉTRIE, RELEVÉE SUR UN GABARIT DE FOURNISSEUR ───────────────────
 *
 * Un gabarit du 2026-08-13, mesuré au `pdfinfo -box` :
 *
 *   MediaBox   0 0 430.87 606.61   =  152,0 × 214,0 mm   <- page = fond perdu
 *   TrimBox    5.67 5.67 425.20 600.94                   <- 2,00 mm de retrait
 *   soit un format fini de 148,0 × 210,0 mm — un A5.
 *
 * Trois traits, et pas un de plus :
 *
 *   ┌──────────────────────────────┐  bord de page   = format + 2 mm par bord
 *   │ ┌──────────────────────────┐ │  trait ROUGE    = format fini (coupe)
 *   │ │ ┌ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─┐ │ │  trait VERT     = 4 mm à l'intérieur
 *   │ │ │                      │ │ │                   (zone de sécurité)
 *
 * La `TrimBox` est posée en plus des traits : elle est ce que lit une machine,
 * là où les traits s'adressent à l'œil. Un massicot suit la TrimBox ; un
 * graphiste suit le trait rouge. Les deux doivent coïncider, et coïncident.
 *
 * ─── POURQUOI IL SE CALCULE PLUTÔT QU'IL NE SE STOCKE ──────────────────────
 *
 * Un gabarit est une fonction de quatre nombres. Le produire coûte quelques
 * millisecondes et huit kilo-octets ; en archiver deux cent cinquante coûte
 * une synchronisation à tenir, et le jour où un format change sur une fiche,
 * un fichier périmé continue de circuler. On calcule.
 */
final class Gabarit
{
    /** Épaisseur des traits, en millimètres. Relevée sur le gabarit source. */
    private const TRAIT_MM = 0.1;

    /** Longueur des tirets et des blancs du trait de sécurité, en mm. */
    private const TIRET_MM = 2.0;

    /** Le rouge de coupe et le vert de sécurité, en composantes 0-255. */
    /**
     * La plus grande cote qu'un PDF accepte, en millimètres.
     *
     * ⚠️ CE N'EST PAS UNE LIMITE DE TCPDF, c'est celle du FORMAT PDF : 200
     * pouces par côté, soit 5 080 mm. Au-delà, les lecteurs se comportent
     * chacun à leur façon — page tronquée, refus d'ouverture, ou silence.
     *
     * L'atelier imprime des bâches de six mètres. Leur gabarit ne peut donc
     * pas exister à l'échelle 1, et c'est une propriété du format, pas un
     * défaut à corriger : on réduit, et on l'ÉCRIT sur le document.
     */
    public const COTE_PDF_MAX_MM = 5080.0;

    /**
     * Les réductions admises, de la plus douce à la plus forte.
     *
     * Des rapports ronds : un imprimeur remonte 1:10 de tête, pas 1:7,3.
     */
    public const ECHELLES = [2, 5, 10, 20, 50, 100];

    private const ROUGE = [227, 30, 36];
    private const VERT = [0, 158, 73];

    /**
     * Le PDF du gabarit, rendu en mémoire.
     *
     * @param SpecTechnique $spec  la spécification, seule source des cotes
     * @param string        $titre le nom du produit, écrit dans les métadonnées
     *
     * @return string le PDF binaire
     */
    public static function depuis(SpecTechnique $spec, string $titre = ''): string
    {
        $largeur = $spec->largeurAFournirMm();
        $hauteur = $spec->hauteurAFournirMm();
        $fondPerdu = SpecTechnique::FOND_PERDU_MM;
        $securite = SpecTechnique::SECURITE_MM;

        // ⚠️ AU-DELÀ DE 5 080 mm, ON RÉDUIT. Le format PDF ne va pas plus loin.
        //
        // Tout est divisé — cotes, fond perdu, marge — sans quoi les traits ne
        // seraient plus à leur place proportionnelle et le gabarit mentirait.
        // L'échelle est écrite sur le document ET dans ses métadonnées : un
        // gabarit réduit qu'on prend pour une échelle 1 fait imprimer une
        // bâche de soixante centimètres.
        $echelle = self::echellePour($largeur, $hauteur);

        if ($echelle > 1) {
            $largeur /= $echelle;
            $hauteur /= $echelle;
            $fondPerdu /= $echelle;
            $securite /= $echelle;
        }

        // ⚠️ L'ORIENTATION SE DÉDUIT DES COTES, ELLE NE SE FORCE PAS.
        //
        // « P » était écrit en dur. Or TCPDF ne prend pas l'orientation comme
        // une indication : il l'IMPOSE, et permute largeur et hauteur quand
        // elles la contredisent (`setPageFormat()`). Une carte de visite
        // 89 × 58 sortait donc en page 58 × 89 — tandis que le dessin, lui,
        // continuait d'employer 89 et 58. Le cadre de sécurité et le trait de
        // coupe débordaient de la page, et le client ne voyait plus qu'un
        // fragment de trait rouge en bas de son gabarit.
        //
        // Constaté le 2026-08-15 sur `gabarit-carte-de-visite_8.9x5.8-cm.pdf` :
        // MediaBox 58 × 89 mm, TrimBox 54 × 85 — les bonnes cotes, permutées.
        //
        // Tout format dont la largeur dépasse la hauteur était touché.
        $orientation = $largeur > $hauteur ? 'L' : 'P';

        // ⚠️ Le format de page est donné en TABLEAU de millimètres, jamais par
        // un nom ('A5'). TCPDF appliquerait alors le format fini, sans le fond
        // perdu, et le gabarit serait faux de 4 mm sur chaque dimension.
        $pdf = new \TCPDF($orientation, 'mm', [$largeur, $hauteur], true, 'UTF-8', false);

        $pdf->SetCreator('2M Numérique');
        $pdf->SetAuthor('2M Numérique');
        $pdf->SetTitle($titre === '' ? 'Gabarit' : 'Gabarit — ' . $titre);
        $pdf->SetSubject(sprintf(
            'Format fini %s × %s mm — fond perdu %s mm — marge de sécurité %s mm%s',
            self::mm($spec->largeurMm),
            self::mm($spec->hauteurMm),
            self::mm(SpecTechnique::FOND_PERDU_MM),
            self::mm(SpecTechnique::SECURITE_MM),
            $echelle > 1 ? sprintf(' — DOCUMENT RÉDUIT À L\'ÉCHELLE 1:%d', $echelle) : ''
        ));

        // Ni en-tête, ni pied de page, ni saut automatique : le gabarit ne
        // porte QUE ses traits. Une pagination TCPDF au bas d'un plan de
        // travail se retrouverait à l'impression.
        $pdf->SetPrintHeader(false);
        $pdf->SetPrintFooter(false);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetCellPadding(0);

        // Une page par face. Un recto-verso donne un gabarit de deux pages,
        // ce qui est exactement ce que le préflight attendra du fichier rendu.
        // ⚠️ Les boîtes se déclarent AVEC le format de page, à `AddPage()`.
        // `setPageBoxes()` est une méthode statique de `TCPDF_STATIC`, pas de
        // `TCPDF` : l'appeler sur l'instance lève une fatale. Et une TrimBox
        // posée après coup serait de toute façon écrasée par l'héritage de
        // format de la page suivante.
        //
        // Les coordonnées sont en unités utilisateur — millimètres ici, TCPDF
        // les multiplie par son facteur d'échelle. Les passer en points
        // donnerait un gabarit près de trois fois trop grand.
        $boitePleine = ['llx' => 0.0, 'lly' => 0.0, 'urx' => $largeur, 'ury' => $hauteur];

        $format = [
            'MediaBox' => $boitePleine,
            'CropBox' => $boitePleine,
            'BleedBox' => $boitePleine,
            'ArtBox' => $boitePleine,
            'TrimBox' => [
                'llx' => $fondPerdu,
                'lly' => $fondPerdu,
                'urx' => $largeur - $fondPerdu,
                'ury' => $hauteur - $fondPerdu,
            ],
        ];

        for ($page = 1; $page <= max(1, $spec->pages); $page++) {
            $pdf->AddPage($orientation, $format);

            $pdf->SetLineWidth(self::TRAIT_MM);

            // Trait de coupe, plein.
            $pdf->SetDrawColor(...self::ROUGE);
            $pdf->SetLineStyle(['dash' => 0]);
            $pdf->Rect(
                $fondPerdu,
                $fondPerdu,
                $largeur - 2 * $fondPerdu,
                $hauteur - 2 * $fondPerdu
            );

            // Zone de sécurité, pointillée.
            $pdf->SetDrawColor(...self::VERT);
            $pdf->SetLineStyle(['dash' => self::TIRET_MM . ',' . self::TIRET_MM]);
            $pdf->Rect(
                $fondPerdu + $securite,
                $fondPerdu + $securite,
                $largeur - 2 * ($fondPerdu + $securite),
                $hauteur - 2 * ($fondPerdu + $securite)
            );
        }

        // ⚠️ ÉCRIT SUR LE DOCUMENT, pas seulement dans ses métadonnées. Personne
        // ne lit les propriétés d'un PDF ; tout le monde voit ce qui est dessus.
        if ($echelle > 1) {
            $pdf->setPage(1);
            $pdf->SetFont('helvetica', 'B', max(6, min(24, $largeur / 30)));
            $pdf->SetTextColor(...self::ROUGE);
            $pdf->SetXY($fondPerdu + $securite, $fondPerdu + $securite);
            $pdf->Cell(
                $largeur - 2 * ($fondPerdu + $securite),
                0,
                sprintf(
                    'GABARIT RÉDUIT — ÉCHELLE 1:%d — format fini %s × %s mm',
                    $echelle,
                    self::mm($spec->largeurMm),
                    self::mm($spec->hauteurMm)
                ),
                0,
                0,
                'C'
            );
        }

        return (string) $pdf->Output('gabarit.pdf', 'S');
    }

    /**
     * La réduction nécessaire pour tenir dans un PDF, ou 1.
     *
     * On prend la plus douce qui suffise : réduire au-delà du nécessaire fait
     * perdre de la précision au tracé pour rien.
     */
    private static function echellePour(float $largeur, float $hauteur): int
    {
        $max = max($largeur, $hauteur);

        if ($max <= self::COTE_PDF_MAX_MM) {
            return 1;
        }

        foreach (self::ECHELLES as $e) {
            if ($max / $e <= self::COTE_PDF_MAX_MM) {
                return $e;
            }
        }

        return (int) end(self::ECHELLES);
    }

    /**
     * Le nom de fichier proposé au téléchargement.
     *
     * Il porte les cotes À FOURNIR, comme celui du fournisseur
     * (« …_21.4x15.2_… ») : le client range son gabarit à côté de son fichier,
     * et le nom doit lui rappeler la taille du document à créer, pas celle du
     * produit fini.
     */
    public static function nomDeFichier(SpecTechnique $spec, string $produit = ''): string
    {
        $base = 'gabarit';

        if ($produit !== '') {
            $propre = preg_replace('/[^a-z0-9]+/i', '-', self::translitterer($produit)) ?? '';
            $propre = trim(mb_strtolower($propre), '-');

            if ($propre !== '') {
                $base .= '-' . $propre;
            }
        }

        // Le suffixe dit ce que le document EST, pas seulement qu'il a plusieurs
        // pages : deux pages sont un recto-verso, vingt-quatre sont une
        // brochure. Nommer « recto-verso » un gabarit de brochure, c'est
        // annoncer au client un document qu'il ne recevra pas.
        $suffixe = match (true) {
            $spec->pages <= 1 => '',
            $spec->pages === 2 => '_recto-verso',
            default => '_' . $spec->pages . '-pages',
        };

        return sprintf(
            '%s_%sx%s-cm%s.pdf',
            $base,
            self::cm($spec->largeurAFournirMm()),
            self::cm($spec->hauteurAFournirMm()),
            $suffixe
        );
    }

    private static function mm(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',');
    }

    private static function cm(float $mm): string
    {
        return rtrim(rtrim(number_format($mm / 10, 1, '.', ''), '0'), '.');
    }

    private static function translitterer(string $t): string
    {
        $sansAccent = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);

        return $sansAccent === false ? $t : $sansAccent;
    }
}
