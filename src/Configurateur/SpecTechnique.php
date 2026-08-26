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
 * La spécification technique d'un imprimé : ce que le client doit fournir.
 *
 * ─── UN SEUL CALCUL, TROIS CONSOMMATEURS ───────────────────────────────────
 *
 * Le format fini de la fiche produit, augmenté du fond perdu et diminué de la
 * marge de sécurité, donne un objet unique dont dépendent :
 *
 *   1. le GABARIT PDF téléchargeable (Gabarit::depuis()) ;
 *   2. la fenêtre « données techniques » de la fiche et du panier ;
 *   3. le bloc `expected` envoyé au préflight d'E-KO, qui n'a aujourd'hui
 *      AUCUN attendu à quoi comparer le fichier reçu.
 *
 * Les trois doivent dire la même chose. Les calculer séparément, c'est
 * garantir qu'ils divergeront — et qu'un client imprimera sur un gabarit qui
 * ne correspond pas au contrôle qu'on lui opposera ensuite.
 *
 * ─── CE QUI EST REFUSÉ, ET POURQUOI ────────────────────────────────────────
 *
 * `depuisFormat()` rend `null` dès que le format fini ne se lit pas SANS
 * interprétation. Mesuré sur les 248 libellés du catalogue : 21 produits sur
 * 80 sont hors d'atteinte, et ce n'est pas un défaut de l'analyseur.
 *
 *   — objets en VOLUME (« 8 x 8 x 33cm ») : une boîte se fabrique sur une
 *     découpe fournisseur, pas sur un rectangle à fond perdu ;
 *   — formats PLIÉS (« A5 fermé / A4 ouvert », « A4 - 3 volets ») : le gabarit
 *     doit porter les traits de pli, dont la répartition n'est écrite nulle
 *     part ;
 *   — formes RONDES : un fond perdu circulaire n'est pas un rectangle élargi ;
 *   — une SEULE cote (« 1,90 m » des beach flags) : la seconde manque.
 *
 * Un gabarit faux coûte une réimpression. L'absence de gabarit coûte un
 * e-mail. On refuse.
 */
final class SpecTechnique
{
    /** Fond perdu, en millimètres, sur CHAQUE bord. */
    public const FOND_PERDU_MM = 2.0;

    /** Marge de sécurité, en millimètres, à l'intérieur du format fini. */
    public const SECURITE_MM = 4.0;

    /**
     * Pagination maximale acceptée pour un gabarit.
     *
     * Le catalogue plafonne à 154 pages (l'agenda spirale). La borne écarte
     * une valeur aberrante lue dans un critère mal rempli : générer un PDF de
     * dix mille pages sur un mutualisé, c'est une page blanche et un 500.
     */
    public const PAGES_MAX = 400;

    /**
     * La part d'emballage ajoutée au poids du papier, en proportion.
     *
     * Carton, calage, film : trois pour cent, décidé le 2026-08-13. Le chiffre
     * est appliqué au SEUL endroit où le poids se calcule, pour que la fenêtre
     * technique et le total du panier annoncent le même nombre — deux calculs
     * du même poids finiraient par diverger, et l'écart ne se découvrirait
     * qu'au comptoir, au moment d'affranchir.
     */
    public const EMBALLAGE = 0.03;

    /** Résolution attendue des images, en points par pouce. */
    public const RESOLUTION_DPI = 300;

    /** Les extensions que l'atelier sait traiter. */
    public const TYPES_ACCEPTES = ['PDF', 'JPEG', 'TIFF'];

    /**
     * Les formats normalisés, en millimètres, orientation portrait.
     *
     * Ils sont écrits ici plutôt que déduits d'une formule : la série A se
     * calcule bien par division successive, mais les arrondis officiels ne
     * suivent pas exactement le calcul, et DL n'appartient à aucune série.
     *
     * @var array<string,array{0:float,1:float}>
     */
    private const NORMALISES = [
        'a0' => [841.0, 1189.0],
        'a1' => [594.0, 841.0],
        'a2' => [420.0, 594.0],
        'a3' => [297.0, 420.0],
        'a4' => [210.0, 297.0],
        'a5' => [148.0, 210.0],
        'a6' => [105.0, 148.0],
        'a7' => [74.0, 105.0],
        'a8' => [52.0, 74.0],
        'dl' => [99.0, 210.0],
    ];

    private function __construct(
        public readonly float $largeurMm,
        public readonly float $hauteurMm,
        public readonly int $pages,
        public readonly string $origine,
        public readonly string $libelle,
        public readonly int $volets = 0
    ) {
    }

    /**
     * La plus petite cote qu'on accepte de dessiner, en millimètres.
     *
     * Sous 10 mm, un gabarit n'a plus de sens : le fond perdu et la marge de
     * sécurité mangeraient toute la pièce.
     */
    public const COTE_MIN_MM = 10.0;

    /**
     * La plus grande, en millimètres.
     *
     * Dix mètres couvre le grand format de l'atelier — bâches, kakémonos,
     * habillages. Au-delà, on refuse plutôt que de faire calculer un PDF que
     * personne n'ouvrira.
     */
    public const COTE_MAX_MM = 10000.0;

    /**
     * La spécification déduite de COTES LIBRES.
     *
     * ─── POURQUOI UNE SECONDE FABRIQUE ─────────────────────────────────────
     *
     * `depuisFormat()` lit un libellé de catalogue — « 8.9x5.8 cm », « A4 ».
     * L'atelier n'en a pas : le client tape 1837 × 700 mm, et aucune liste ne
     * contient cette valeur. Les deux chemins produisent la même chose, ils
     * partent seulement d'ailleurs.
     *
     * On borne, parce que ces cotes viennent du navigateur : sans borne, un
     * paramètre fabriqué ferait calculer un PDF de cent mètres sur un
     * hébergement mutualisé.
     *
     * @param float $largeurMm largeur FINIE, en millimètres
     * @param float $hauteurMm hauteur FINIE, en millimètres
     */
    public static function depuisCotes(float $largeurMm, float $hauteurMm, int $pages = 1): ?self
    {
        $bornee = static function (float $v): bool {
            return $v >= self::COTE_MIN_MM && $v <= self::COTE_MAX_MM;
        };

        if (!$bornee($largeurMm) || !$bornee($hauteurMm)) {
            return null;
        }

        return new self(
            round($largeurMm, 1),
            round($hauteurMm, 1),
            max(1, min($pages, self::PAGES_MAX)),
            'cotes',
            sprintf('%s × %s mm', self::nombre($largeurMm), self::nombre($hauteurMm))
        );
    }

    /** Un nombre écrit sans décimale inutile : « 1837 » et non « 1837.0 ». */
    private static function nombre(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',');
    }

    /**
     * La spécification déduite d'un libellé de format, ou `null`.
     *
     * @param string $libelle le libellé tel qu'il est sur la fiche produit
     * @param int    $pages   1 pour un recto, 2 pour un recto-verso
     */
    public static function depuisFormat(string $libelle, int $pages = 1): ?self
    {
        $brut = trim($libelle);

        if ($brut === '') {
            return null;
        }

        $t = self::sansAccent($brut);
        $pages = max(1, $pages);

        // ─── Les refus, en premier ─────────────────────────────────────────
        //
        // L'ordre compte : « 10 cm de diamètre » contient un nombre, et
        // « 8 x 8 x 33cm » contient « 8 x 8 ». Tester les rectangles avant
        // les exclusions ferait passer un rond pour un carré.
        if (preg_match('/diametre|\brond\b|ovale|cercle/u', $t)) {
            return null;
        }

        // ─── Les formats pliés : on retient le format OUVERT ───────────────
        //
        // Décision métier du 2026-08-13 : le gabarit d'un dépliant est celui
        // du format DÉPLIÉ. C'est NOUS qui plions — le client fournit une
        // feuille à plat, à lui de caler son contenu pour qu'il tombe juste
        // sur les plis. Le contrôle se fait à l'œil chez nous, et le gabarit
        // est de toute façon retiré du fichier avant impression.
        //
        // ⚠️ Le libellé affiché dit « correspondance des plis à votre charge »
        // et non « le pliage reste à votre charge » : le second laissait
        // croire au client qu'il recevrait une feuille à plat.
        //
        // Dessiner les traits de pli demanderait de connaître la répartition
        // des volets — roulé, accordéon, portefeuille, croisé — qui n'est
        // écrite nulle part dans le catalogue. Un trait de pli faux est pire
        // qu'aucun trait : il fait plier au mauvais endroit.
        $plie = self::formatOuvert($t);

        if ($plie !== null) {
            [$ouvert, $volets] = $plie;
            $spec = self::depuisFormat($ouvert, $pages);

            return $spec === null
                ? null
                : new self($spec->largeurMm, $spec->hauteurMm, $pages, 'ouvert', $brut, $volets);
        }

        if (preg_match('/\bouvert\b|\bferme\b|volet|\bpli(s|e|es)?\b|rabat/u', $t)) {
            return null;
        }

        $nombre = '(\d+(?:[.,]\d+)?)';

        if (preg_match('/' . $nombre . '\s*[x×]\s*' . $nombre . '\s*[x×]\s*' . $nombre . '/u', $t)) {
            return null;
        }

        // ─── Format normalisé nommé, sans mesure ───────────────────────────
        if (preg_match('/\b(a[0-8]|dl)\b/u', $t, $m)
            && !preg_match('/' . $nombre . '\s*[x×]/u', $t)) {
            [$l, $h] = self::NORMALISES[$m[1]];

            // « A6 Paysage » : mêmes cotes, orientation retournée.
            if (preg_match('/paysage|horizontal/u', $t)) {
                [$l, $h] = [$h, $l];
            }

            return new self($l, $h, $pages, 'normalise', $brut);
        }

        // ─── Deux mesures ──────────────────────────────────────────────────
        if (!preg_match('/' . $nombre . '\s*[x×]\s*' . $nombre . '/u', $t, $m)) {
            return null;
        }

        $a = (float) str_replace(',', '.', $m[1]);
        $b = (float) str_replace(',', '.', $m[2]);

        if ($a <= 0.0 || $b <= 0.0) {
            return null;
        }

        if (str_contains($t, 'mm')) {
            return new self($a, $b, $pages, 'mesure', $brut);
        }

        if (str_contains($t, 'cm')) {
            return new self($a * 10, $b * 10, $pages, 'mesure', $brut);
        }

        if (preg_match('/\bm\b/u', $t)) {
            return new self($a * 1000, $b * 1000, $pages, 'mesure', $brut);
        }

        // ─── Aucune unité écrite ───────────────────────────────────────────
        //
        // Le catalogue en compte 81 sur 248 : « 10x10 », « Carré 14,8 x 14,8 »,
        // « 100x200 ». Le centimètre est la seule lecture qui donne des
        // formats d'imprimerie plausibles — en millimètres, « 10x10 » ferait
        // un carré d'un centimètre.
        //
        // C'est une DÉDUCTION, et elle est marquée comme telle : `origine`
        // vaut « deduit », ce qui permet d'en dresser la liste et de la faire
        // relire, plutôt que de la noyer dans le reste.
        return new self($a * 10, $b * 10, $pages, 'deduit', $brut);
    }

    /**
     * Le format OUVERT d'un libellé plié, et son nombre de volets.
     *
     * Deux écritures coexistent dans le catalogue, et elles ne se lisent pas
     * de la même façon :
     *
     *   « A5 fermé / A4 ouvert »  -> le libellé DÉSIGNE les deux états ; on
     *                                prend celui qui porte « ouvert ».
     *   « A4 - 3 volets »          -> le format nommé est la FEUILLE À PLAT,
     *                                qu'on plie ensuite en trois. C'est la
     *                                convention de l'imprimerie : on nomme ce
     *                                qu'on met sous la presse.
     *
     * @return array{0:string,1:int}|null [format ouvert, nombre de volets]
     */
    private static function formatOuvert(string $t): ?array
    {
        // « … ouvert » / « ouvert : … » — de part et d'autre du séparateur.
        if (preg_match('#([^/]+?)\\s*ouvert#u', $t, $m)) {
            $cote = trim($m[1], " \t/-:");

            // « A5 ferme / A4 ouvert » : le membre capturé est « a5 ferme / a4 »
            // si la barre a été avalée. On ne garde que ce qui suit la
            // dernière barre.
            $barre = mb_strrpos($cote, '/');

            if ($barre !== false) {
                $cote = trim(mb_substr($cote, $barre + 1));
            }

            if ($cote !== '') {
                return [$cote, 0];
            }
        }

        // « A4 - 3 volets », « A5 - 2 volets », « 10 x 5,5 cm (2 volets) »
        // La parenthèse compte parmi les séparateurs : trois écritures pour
        // une même notion, et le catalogue les emploie toutes les trois.
        if (preg_match('#^(.+?)[\\s(-]+(\\d+)\\s*volets?#u', $t, $m)) {
            $cote = trim($m[1], " \t-");

            if ($cote !== '') {
                return [$cote, (int) $m[2]];
            }
        }

        return null;
    }

    /**
     * La spécification d'une ligne de configuration complète.
     *
     * @param array<string,string> $criteres libellé de critère => valeur
     */
    public static function depuisConfiguration(array $criteres): ?self
    {
        $format = null;

        foreach ($criteres as $critere => $valeur) {
            $c = self::sansAccent((string) $critere);

            if (preg_match('/format|dimension|taille/u', $c)) {
                $format = (string) $valeur;
                break;
            }
        }

        if ($format !== null) {
            return self::depuisFormat($format, self::pagesDepuisCriteres($criteres));
        }

        // ⚠️ L'ATELIER N'ÉCRIT PAS DE FORMAT, IL ÉCRIT DEUX COTES.
        //
        // Sa configuration se lit « Largeur : 1000 mm » puis « Hauteur : 700 mm »
        // sur deux lignes : aucun critère unique ne porte « format », et cette
        // méthode rendait donc `null`.
        //
        // Conséquence mesurée le 2026-08-20 : `fichier.php::attendu()` rendait
        // un tableau VIDE, et le préflight de l'ERP n'avait RIEN à quoi comparer
        // le fichier du client. Il l'acceptait donc quelles que soient ses cotes
        // — un fichier A4 passait pour une bâche de trois mètres, et le défaut
        // ne se voyait qu'à la fabrication.
        return self::depuisCotesCriteres($criteres);
    }

    /**
     * La spécification lue sur DEUX critères de cote, ou `null`.
     *
     * On accepte les deux graphies — « Largeur » et « Width » — parce que les
     * libellés viennent de l'ERP et suivent sa langue, pas la nôtre.
     *
     * @param array<string,string> $criteres
     */
    private static function depuisCotesCriteres(array $criteres): ?self
    {
        $largeur = null;
        $hauteur = null;

        foreach ($criteres as $critere => $valeur) {
            $c = self::sansAccent((string) $critere);
            $mm = self::millimetres((string) $valeur);

            if ($mm === null) {
                continue;
            }

            if ($largeur === null && preg_match('/largeur|width|laize/u', $c)) {
                $largeur = $mm;
            } elseif ($hauteur === null && preg_match('/hauteur|height|longueur|length/u', $c)) {
                $hauteur = $mm;
            }
        }

        if ($largeur === null || $hauteur === null) {
            return null;
        }

        // Une pièce d'atelier n'a qu'une face : ses variables ne déclarent
        // aucune pagination, et `pagesDepuisCriteres()` compterait des
        // exemplaires pour des pages.
        return self::depuisCotes($largeur, $hauteur, 1);
    }

    /**
     * Une valeur de critère convertie en millimètres, ou `null`.
     *
     * « 1000 mm », « 100 cm », « 1,5 m » — l'unité est écrite à côté du nombre
     * par le configurateur, qui la tient de l'ERP. Sans unité on suppose des
     * millimètres : c'est celle que l'atelier emploie partout.
     */
    private static function millimetres(string $valeur): ?float
    {
        if (!preg_match('/(-?\d+(?:[.,]\d+)?)\s*(mm|cm|m)?/ui', trim($valeur), $m)) {
            return null;
        }

        $n = (float) str_replace(',', '.', $m[1]);

        if ($n <= 0) {
            return null;
        }

        return match (mb_strtolower($m[2] ?? '')) {
            'cm' => $n * 10,
            'm' => $n * 1000,
            default => $n,
        };
    }

    /**
     * Le nombre de pages du document à fournir, lu sur la configuration.
     *
     * Trois familles de critères, et une seule dit vraiment la pagination :
     *
     *   « EKO Pages »           10, 12, … 154   -> pagination RÉELLE d'une
     *                                              brochure ou d'un agenda.
     *   « EKO Recto Verso »     Recto / R-V     -> 1 ou 2 pages.
     *   « EKO Feuillets »       25, 50, 100…    -> ⚠️ PIÈGE. Ce sont des
     *                                              EXEMPLAIRES d'un même
     *                                              dessin, pas des pages.
     *
     * Un bloc-notes de 100 feuillets se fournit en UNE page : les cent
     * feuillets sont cent tirages du même fichier. Générer un gabarit de cent
     * pages ferait croire au client qu'il doit dessiner cent maquettes — et le
     * préflight refuserait ensuite son fichier d'une page. Le critère est donc
     * explicitement écarté, pas simplement « non reconnu ».
     *
     * Même chose pour les carnets autocopiants : « 3 - Triplicata » désigne
     * trois épaisseurs de papier, pas trois pages à dessiner.
     *
     * @param array<string,string> $criteres
     */
    public static function pagesDepuisCriteres(array $criteres): int
    {
        $pages = 1;

        foreach ($criteres as $critere => $valeur) {
            $c = self::sansAccent((string) $critere);
            $v = trim((string) $valeur);

            // Le piège, écarté en premier — « nombre de feuillets » contient
            // « nombre », et un test trop large sur les chiffres l'avalerait.
            if (preg_match('/feuillet|liasse|carnet|exemplaire/u', $c)) {
                continue;
            }

            // Pagination réelle : le critère se nomme « Pages » et la valeur
            // est un entier nu. « 154 » est une pagination ; « 2 plis
            // accordéon » n'en est pas une, et le critère « Pliage » ne doit
            // jamais être lu ici.
            if (preg_match('/\bpages?\b/u', $c) && preg_match('/^\d+$/', $v)) {
                $n = (int) $v;

                if ($n >= 1 && $n <= self::PAGES_MAX) {
                    return $n;
                }
            }

            if (preg_match('/recto/u', $c)) {
                $pages = self::pagesDepuisRectoVerso($v);
            }
        }

        return $pages;
    }

    /**
     * Le nombre de pages attendu, lu sur le critère « Recto Verso ».
     *
     * « Recto » vaut une page ; « Recto Verso » en vaut deux — que le client
     * livre un PDF de deux pages ou deux fichiers d'une page. C'est la règle
     * du métier, et le préflight d'E-KO n'en connaît aucune aujourd'hui : son
     * contrôle de pagination n'est qu'un plafond.
     */
    public static function pagesDepuisRectoVerso(string $valeur): int
    {
        $v = self::sansAccent($valeur);

        // « Recto Verso », « Recto-Verso », « R/V ». Le test porte sur la
        // présence de « verso », pas sur l'égalité : les libellés varient d'un
        // fournisseur à l'autre.
        return str_contains($v, 'verso') || preg_match('/\br\s*\/?\s*v\b/u', $v) ? 2 : 1;
    }

    /**
     * Le grammage lu sur un libellé de papier, en g/m², ou `null`.
     *
     * Le catalogue écrit la chose de quatre façons : « 115G Recyclé »,
     * « Carton kraft 330g/m² », « Bâche 510 g », « 350 G Papier de création ».
     * Toutes se ramènent à un nombre suivi d'un « g » isolé.
     *
     * Et une bonne partie des supports n'en portent AUCUN : « Adhésif Vinyl
     * Blanc », « M1 - Anti-feu », « Aucun ». On rend `null` — la fenêtre
     * technique masque alors la ligne, plutôt que d'afficher un zéro qui
     * passerait pour une mesure.
     *
     * ⚠️ Le « g » doit être isolé (`\b`), sans quoi le « G » de « (Gmund) »
     * ferait lire un grammage sur le mot suivant.
     */
    public static function grammage(string $libellePapier): ?int
    {
        if (!preg_match('/(\d+)\s*g\b/iu', $libellePapier, $m)) {
            return null;
        }

        $g = (int) $m[1];

        // Bornes de bon sens : au-dessous de 30 g/m² on est sur du papier de
        // soie, au-dessus de 2000 sur une erreur de saisie. Les deux méritent
        // un refus plutôt qu'un poids de commande fantaisiste.
        return ($g >= 30 && $g <= 2000) ? $g : null;
    }

    /**
     * Le poids total de la commande, en kilogrammes, ou `null`.
     *
     * Surface du format FINI — et non du format à fournir : les fonds perdus
     * partent au massicot, le client ne les emporte pas.
     */
    public function poidsKg(int $quantite, ?int $grammage): ?float
    {
        if ($grammage === null || $quantite <= 0) {
            return null;
        }

        $surfaceM2 = ($this->largeurMm / 1000) * ($this->hauteurMm / 1000);

        // Le nombre de pages compte : une brochure de 24 pages, ce sont douze
        // feuilles. Diviser par deux, c'est admettre qu'une page est une FACE.
        $feuilles = max(1, (int) ceil($this->pages / 2));

        $papier = $surfaceM2 * $grammage * $feuilles * $quantite / 1000;

        return round($papier * (1 + self::EMBALLAGE), 2);
    }

    /** La largeur à fournir, fonds perdus compris. */
    public function largeurAFournirMm(): float
    {
        return $this->largeurMm + 2 * self::FOND_PERDU_MM;
    }

    /** La hauteur à fournir, fonds perdus compris. */
    public function hauteurAFournirMm(): float
    {
        return $this->hauteurMm + 2 * self::FOND_PERDU_MM;
    }

    /** Vrai si le format fini a été lu, et non déduit. */
    public function estCertaine(): bool
    {
        return $this->origine !== 'deduit';
    }

    /**
     * La spécification sous forme de tableau, pour l'affichage et pour l'API.
     *
     * Les longueurs sont rendues en CENTIMÈTRES, comme les fenêtres de nos
     * confrères et comme les fiches produit — le millimètre est l'unité du
     * calcul, pas celle du client.
     *
     * @return array<string,mixed>
     */
    public function enTableau(): array
    {
        return [
            'largeur_fournir_cm' => round($this->largeurAFournirMm() / 10, 2),
            'hauteur_fournir_cm' => round($this->hauteurAFournirMm() / 10, 2),
            'largeur_finie_cm' => round($this->largeurMm / 10, 2),
            'hauteur_finie_cm' => round($this->hauteurMm / 10, 2),
            'fond_perdu_cm' => round(self::FOND_PERDU_MM / 10, 2),
            'securite_cm' => round(self::SECURITE_MM / 10, 2),
            'forme' => 'Rectangle',
            'echelle' => '100 %',
            'resolution_dpi' => self::RESOLUTION_DPI,
            'types' => self::TYPES_ACCEPTES,
            'pages' => $this->pages,
            'origine' => $this->origine,
            'libelle' => $this->libelle,
            'volets' => $this->volets,
            'plie' => $this->origine === 'ouvert',
        ];
    }

    /**
     * Le bloc « attendu » destiné au préflight d'E-KO.
     *
     * En MILLIMÈTRES : c'est l'unité dans laquelle un PDF se mesure, et une
     * conversion de plus au moment de la comparaison serait une occasion de
     * plus de se tromper d'un facteur dix.
     *
     * @return array<string,mixed>
     */
    public function attenduPreflight(): array
    {
        return [
            'width_mm' => round($this->largeurAFournirMm(), 2),
            'height_mm' => round($this->hauteurAFournirMm(), 2),
            'trim_width_mm' => round($this->largeurMm, 2),
            'trim_height_mm' => round($this->hauteurMm, 2),
            'bleed_mm' => self::FOND_PERDU_MM,
            'safety_mm' => self::SECURITE_MM,
            'pages' => $this->pages,
            'min_dpi' => self::RESOLUTION_DPI,
        ];
    }

    /** Une empreinte stable, pour nommer et mettre en cache un gabarit. */
    public function empreinte(): string
    {
        return sprintf(
            '%s-%sx%s-fp%s-s%s-p%d',
            'gabarit',
            self::nombreCourt($this->largeurMm),
            self::nombreCourt($this->hauteurMm),
            self::nombreCourt(self::FOND_PERDU_MM),
            self::nombreCourt(self::SECURITE_MM),
            $this->pages
        );
    }

    private static function nombreCourt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    /** Minuscules sans accents : « Carré » et « CARRE » doivent se lire pareil. */
    private static function sansAccent(string $t): string
    {
        $sansAccent = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);

        // `iconv` rend `false` sur certaines plateformes mal configurées. On
        // retombe alors sur le texte d'origine plutôt que sur une chaîne vide,
        // qui ferait échouer TOUTES les analyses sans le signaler.
        return mb_strtolower($sansAccent === false ? $t : $sansAccent);
    }
}
