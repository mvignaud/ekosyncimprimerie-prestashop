<?php

/**
 * EKO Sync — Imprimerie
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

declare(strict_types=1);

/**
 * Les spécifications techniques d'une configuration en cours, sur la fiche.
 *
 * ─── POURQUOI UN ALLER-RETOUR AU SERVEUR ───────────────────────────────────
 *
 * Le configurateur connaît la sélection du visiteur ; il ne connaît pas les
 * règles qui en tirent des cotes — lecture du format, format ouvert d'un
 * dépliant, pagination d'une brochure, grammage d'un papier. Ces règles vivent
 * dans `SpecTechnique`, en PHP, et c'est le même code qui sert le panier.
 *
 * Les réécrire en JavaScript ferait deux implémentations d'un même calcul.
 * Elles seraient d'accord le premier jour et divergeraient au premier
 * correctif : le client lirait des cotes sur la fiche et d'autres au panier,
 * sans que rien ne signale laquelle est juste.
 *
 * ─── CE QUI EST VÉRIFIÉ ────────────────────────────────────────────────────
 *
 * Les critères arrivent du navigateur : ils sont donc suspects. Le format
 * annoncé doit appartenir au produit — sans quoi une requête fabriquée ferait
 * afficher les cotes d'un autre article, ou calculer un gabarit de deux mètres
 * sur un format inventé.
 */
class EkosyncimprimerieSpecificationsModuleFrontController extends ModuleFrontController
{
    /** @var bool réponse JSON, pas une page */
    public $content_only = true;

    /** @var bool aucune raison d'exiger un client connecté pour lire des cotes */
    public $auth = false;

    public function initContent()
    {
        $idProduct = (int) Tools::getValue('id_product');
        $criteres = $this->criteresRecus();

        if ($idProduct <= 0 || $criteres === []) {
            $this->repondre(['html' => '']);
        }

        $produit = new Product($idProduct, false, (int) $this->context->language->id);

        if (!Validate::isLoadedObject($produit) || !$produit->active) {
            $this->repondre(['html' => '']);
        }

        $format = $this->formatDes($criteres);

        // Le format doit être une caractéristique DE CE PRODUIT. La même
        // vérification qu'au téléchargement du gabarit, pour la même raison :
        // les deux points d'entrée reçoivent le format du navigateur.
        if ($format === null || !$this->formatDuProduit($idProduct, $format)) {
            $this->repondre(['html' => '']);
        }

        /** @var Ekosyncimprimerie $module */
        $module = $this->module;

        $this->repondre([
            'html' => $module->blocSpecifications($idProduct, $criteres, 1, 'eko-fitech-fiche', false),
        ]);
    }

    /**
     * Les critères reçus, nettoyés.
     *
     * Le configurateur les envoie sous la forme `criteres[Libellé]=Valeur` —
     * exactement ce qu'il affiche dans son récapitulatif, et exactement ce que
     * le panier enregistrera. Une seule forme d'un bout à l'autre du parcours.
     *
     * @return array<string,string>
     */
    private function criteresRecus(): array
    {
        $brut = Tools::getValue('criteres');

        if (!is_array($brut)) {
            return [];
        }

        $criteres = [];
        $n = 0;

        foreach ($brut as $libelle => $valeur) {
            // Borne de sécurité : une configuration d'imprimé compte dix à
            // quinze critères. Au-delà de cinquante, ce n'est plus une
            // configuration, c'est une tentative de faire travailler le
            // serveur pour rien.
            if (++$n > 50) {
                break;
            }

            if (!is_scalar($valeur) || !is_string($libelle)) {
                continue;
            }

            $l = trim(mb_substr($libelle, 0, 120));
            $v = trim(mb_substr((string) $valeur, 0, 255));

            if ($l !== '' && $v !== '') {
                $criteres[$l] = $v;
            }
        }

        return $criteres;
    }

    /** @param array<string,string> $criteres */
    private function formatDes(array $criteres): ?string
    {
        foreach ($criteres as $critere => $valeur) {
            $sansAccent = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $critere);
            $c = mb_strtolower($sansAccent === false ? $critere : $sansAccent);

            if (preg_match('/format|dimension|taille/u', $c)) {
                return $valeur;
            }
        }

        return null;
    }

    private function formatDuProduit(int $idProduct, string $format): bool
    {
        $cle = \Eko\SyncImprimerie\Configurateur\DepotGabarit::cle($format);

        $valeurs = Db::getInstance()->executeS(
            'SELECT fvl.value'
            . ' FROM `' . _DB_PREFIX_ . 'feature_product` fp'
            . ' JOIN `' . _DB_PREFIX_ . 'feature_value_lang` fvl'
            . '   ON fvl.id_feature_value = fp.id_feature_value'
            . '  AND fvl.id_lang = ' . (int) $this->context->language->id
            . ' WHERE fp.id_product = ' . $idProduct
        );

        foreach ((array) $valeurs as $v) {
            if (\Eko\SyncImprimerie\Configurateur\DepotGabarit::cle((string) $v['value']) === $cle) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $donnees */
    private function repondre(array $donnees): void
    {
        // Le tampon de PrestaShop contient déjà du HTML de page : sans ce
        // nettoyage il précéderait le JSON, qui deviendrait illisible pour le
        // navigateur — sans erreur, juste un `SyntaxError` côté client.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
