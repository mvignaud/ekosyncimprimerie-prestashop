<?php

/**
 * EKO Sync — Imprimerie
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

declare(strict_types=1);

use Eko\SyncImprimerie\Configurateur\DepotGabarit;
use Eko\SyncImprimerie\Configurateur\Gabarit;
use Eko\SyncImprimerie\Configurateur\LiaisonProduit;
use Eko\SyncImprimerie\Configurateur\SpecTechnique;

/**
 * Le téléchargement d'un gabarit.
 *
 * ─── UNE SEULE URL POUR DEUX ORIGINES ──────────────────────────────────────
 *
 * Un gabarit est soit CALCULÉ depuis le format fini de la fiche, soit DÉPOSÉ à
 * la main pour les formats que le calcul ne sait pas produire — boîtes,
 * formes rondes, beach flags. Le client ne doit jamais avoir à savoir lequel
 * des deux il télécharge : même lien, même nom de fichier, même comportement.
 *
 * L'ordre de recherche compte : le fichier DÉPOSÉ passe avant le calcul. Si
 * quelqu'un a pris la peine de dessiner une découpe pour un format que nous
 * savons pourtant calculer, c'est qu'il avait une raison — un rainage, une
 * encoche, une contrainte de fabricant. Le calcul ne doit pas l'écraser.
 */
class EkosyncimprimerieGabaritModuleFrontController extends ModuleFrontController
{
    /** @var bool cette page ne s'affiche pas, elle rend un fichier */
    public $content_only = true;

    public function initContent()
    {
        $idProduct = (int) Tools::getValue('id_product');
        $format = trim((string) Tools::getValue('format'));

        // ⚠️ DEUX ENTRÉES, DEUX NATURES DE PRODUIT.
        //
        // La sous-traitance donne un LIBELLÉ de format pris dans une liste
        // fermée — « 8.9x5.8 cm » — et le gabarit se déduit de lui. L'atelier
        // n'en a pas : le client tape 1837 × 700, et aucune liste ne contient
        // cette valeur. On accepte donc aussi des COTES, sous une garde qui
        // remplace celle du format : la fiche doit être liée à l'atelier.
        $largeur = (float) str_replace(',', '.', (string) Tools::getValue('largeur'));
        $hauteur = (float) str_replace(',', '.', (string) Tools::getValue('hauteur'));
        $parCotes = $largeur > 0 && $hauteur > 0;

        if ($idProduct <= 0 || ($format === '' && !$parCotes)) {
            $this->rendre404();
        }

        $produit = new Product($idProduct, false, (int) $this->context->language->id);

        // ⚠️ On sert le gabarit d'un produit VISIBLE. Sans ce garde, l'URL
        // devient un inventaire des fiches en préparation : il suffirait de
        // parcourir les identifiants pour découvrir ce qui n'est pas encore
        // publié.
        if (!Validate::isLoadedObject($produit) || !$produit->active) {
            $this->rendre404();
        }

        if ($parCotes) {
            // La garde des cotes : la fiche doit être liée à l'ATELIER. Sans
            // elle, l'URL deviendrait un générateur de PDF à la demande sur
            // n'importe quel produit de la boutique — et le mutualisé paierait
            // chaque appel.
            $liaison = (new LiaisonProduit())->pour($idProduct);

            if ($liaison === null || $liaison['source'] !== LiaisonProduit::SOURCE_ATELIER) {
                $this->rendre404();
            }

            // Les bornes vivent dans la spécification, pas ici : c'est elle qui
            // sait ce qu'elle accepte de dessiner.
            // ⚠️ UNE SEULE PAGE, et le paramètre `pages` est ignoré ici.
            //
            // Il sert aux brochures de sous-traitance, dont le format fini ne
            // dit pas le nombre de feuillets. Un produit d'atelier n'a qu'une
            // face : ses variables ne déclarent aucune pagination. Le laisser
            // passer produisait 400 pages d'un grand format sur simple demande
            // — mesuré, 311 Ko pour une bâche de 2 m — sans que cela ait le
            // moindre sens pour un client.
            $spec = SpecTechnique::depuisCotes($largeur, $hauteur, 1);

            if ($spec === null) {
                $this->rendre404();
            }

            $pdf = Gabarit::depuis($spec, (string) $produit->name);

            $this->envoyer(
                $pdf,
                'application/pdf',
                Gabarit::nomDeFichier($spec, (string) $produit->name),
                strlen($pdf)
            );
        }

        // Et le format doit appartenir À CE PRODUIT. Autrement une requête
        // fabriquée ferait servir le gabarit d'un autre article, ou pire
        // ferait calculer un PDF de deux mètres sur un format inventé.
        if (!$this->formatDuProduit($idProduct, $format)) {
            $this->rendre404();
        }

        $depose = DepotGabarit::lire($idProduct, $format);

        if ($depose !== null) {
            $this->rendreFichier(
                $depose['chemin'],
                $this->nomPourClient($produit->name, $format, pathinfo($depose['fichier'], PATHINFO_EXTENSION))
            );
        }

        $spec = SpecTechnique::depuisFormat($format, $this->pagesDemandees());

        if ($spec === null) {
            // Ni gabarit déposé, ni format calculable. On le dit franchement
            // plutôt que de rendre un PDF vide qui passerait pour un gabarit.
            $this->rendre404();
        }

        $pdf = Gabarit::depuis($spec, (string) $produit->name);

        $this->envoyer(
            $pdf,
            'application/pdf',
            Gabarit::nomDeFichier($spec, (string) $produit->name),
            strlen($pdf)
        );
    }

    /**
     * Le nombre de pages demandé, borné.
     *
     * Il arrive par l'URL parce qu'il dépend de la CONFIGURATION en cours —
     * une brochure de 24 pages et une de 48 partagent le même format fini. La
     * borne haute vient de la spécification elle-même : sans elle, un
     * paramètre fabriqué ferait générer un PDF de dix mille pages, et le
     * mutualisé rendrait un 500.
     */
    private function pagesDemandees(): int
    {
        $pages = (int) Tools::getValue('pages', 1);

        return max(1, min($pages, SpecTechnique::PAGES_MAX));
    }

    /** Le format est-il bien une caractéristique de ce produit ? */
    private function formatDuProduit(int $idProduct, string $format): bool
    {
        $cle = DepotGabarit::cle($format);

        $valeurs = Db::getInstance()->executeS(
            'SELECT fvl.value'
            . ' FROM `' . _DB_PREFIX_ . 'feature_product` fp'
            . ' JOIN `' . _DB_PREFIX_ . 'feature_value_lang` fvl'
            . '   ON fvl.id_feature_value = fp.id_feature_value'
            . '  AND fvl.id_lang = ' . (int) $this->context->language->id
            . ' WHERE fp.id_product = ' . $idProduct
        );

        foreach ((array) $valeurs as $v) {
            // La comparaison passe par la même normalisation que le dépôt :
            // une différence de casse ou d'espaces ne doit pas faire échouer
            // un format qui est bel et bien celui du produit.
            if (DepotGabarit::cle((string) $v['value']) === $cle) {
                return true;
            }
        }

        return false;
    }

    /** Rend un fichier du disque, en flux. */
    private function rendreFichier(string $chemin, string $nom): void
    {
        $taille = (int) @filesize($chemin);

        if ($taille <= 0) {
            $this->rendre404();
        }

        $this->entetes(self::typeMime($chemin), $nom, $taille);

        // `readfile` diffuse sans charger le fichier en mémoire. Un
        // `file_get_contents` sur une découpe de PLV de trente méga-octets
        // ferait exploser la mémoire du mutualisé.
        @readfile($chemin);
        exit;
    }

    /** Rend un contenu déjà en mémoire. */
    private function envoyer(string $contenu, string $type, string $nom, int $taille): void
    {
        $this->entetes($type, $nom, $taille);
        echo $contenu;
        exit;
    }

    private function entetes(string $type, string $nom, int $taille): void
    {
        // Le tampon de sortie de PrestaShop contient déjà du HTML de page à ce
        // stade. Sans ce nettoyage, il se retrouverait EN TÊTE du PDF, qui
        // deviendrait illisible — sans erreur, juste un fichier corrompu.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: ' . $type);
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $nom) . '"');
        header('Content-Length: ' . $taille);
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
    }

    private static function typeMime(string $chemin): string
    {
        return match (mb_strtolower((string) pathinfo($chemin, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'eps' => 'application/postscript',
            'ai' => 'application/illustrator',
            default => 'application/octet-stream',
        };
    }

    /** Un nom lisible pour le client, quel que soit le fichier d'origine. */
    private function nomPourClient(string $produit, string $format, string $extension): string
    {
        $morceau = static function (string $t): string {
            $sansAccent = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);
            $propre = preg_replace('/[^a-z0-9]+/i', '-', $sansAccent === false ? $t : $sansAccent) ?? '';

            return trim(mb_strtolower($propre), '-');
        };

        return 'gabarit-' . $morceau($produit) . '_' . $morceau($format) . '.'
            . ($extension === '' ? 'pdf' : mb_strtolower($extension));
    }

    private function rendre404(): void
    {
        header('HTTP/1.1 404 Not Found');
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Gabarit indisponible.';
        exit;
    }
}
