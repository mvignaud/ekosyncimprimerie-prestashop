<?php

/**
 * EKO Sync — Imprimerie
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

/**
 * Les prestations que l'imprimeur ajoute, et sa fiche technique.
 *
 * ─── CE QUI VIENT D'ICI, ET CE QUI VIENT DE L'ERP ──────────────────────────
 *
 * L'ERP sait ce que le sous-traitant FABRIQUE : formats, papiers, quantités,
 * délais, prix. Il ne sait rien de ce que l'imprimeur ajoute par-dessus — un
 * bon à tirer, une création graphique — ni de ce qu'il attend d'un fichier.
 *
 * Ces choses-là sont des propriétés du MARCHAND. Elles se règlent donc ici,
 * produit par produit, et ne remontent jamais à l'ERP.
 *
 * ─── LA RÈGLE DU PRIX, ET COMMENT ELLE TIENT ENCORE ────────────────────────
 *
 * Le module ne recalcule JAMAIS le prix de l'ERP. Cette règle ne bouge pas :
 * le montant du sous-traitant reste ce qu'il a rendu, au centime.
 *
 * Les prestations s'AJOUTENT par-dessus, et l'addition se fait côté serveur —
 * jamais dans le navigateur. Le récapitulatif montre les deux lignes
 * séparément, pour qu'un client puisse rapprocher son devis du prix affiché
 * sans avoir à deviner ce qui a été ajouté.
 *
 * ─── Pourquoi `Configuration` et pas une table ─────────────────────────────
 *
 * Une poignée de réglages par produit, lus une fois par affichage, jamais
 * cherchés ni triés. Une table apporterait un schéma à faire évoluer et des
 * jointures, pour remplacer un `get()` par clé. `Configuration` est déjà
 * l'endroit où PrestaShop range ce genre de chose, et le module s'y tient
 * déjà pour ses autres réglages.
 */

declare(strict_types=1);

namespace Eko\SyncImprimerie\Configurateur;

final class ServicesProduit
{
    /** Les quatre lignes de la fiche technique, et leur usage par défaut. */
    public const TECHNIQUE = [
        'resolution' => '300 DPI',
        'couleurs' => 'CMJN',
        'fonds_perdus' => '2 mm',
        'marge' => '4 mm',
    ];

    /**
     * Les prestations proposées, avec leur supplément en centimes.
     *
     * La première option de chaque prestation est celle par défaut, et elle est
     * gratuite : « je fournis mon fichier », « pas de bon à tirer ». Un
     * configurateur qui démarrerait sur une option payante gonflerait le prix
     * d'appel sans que le visiteur l'ait demandé.
     */
    public const SERVICES = ['bat', 'creation'];

    /** La clé de réglage d'un produit, ou du réglage global si `$idProduct` vaut 0. */
    private static function cle(string $nom, int $idProduct = 0): string
    {
        return 'EKOSYNC_' . strtoupper($nom) . ($idProduct > 0 ? '_' . $idProduct : '');
    }

    /**
     * Un réglage : celui du produit, sinon celui de la boutique, sinon le défaut.
     *
     * La cascade compte : un marchand règle une fois pour toute la boutique, et
     * ne redescend au produit que pour l'exception. L'inverse — tout saisir sur
     * chaque fiche — se périme au premier oubli.
     */
    public static function reglage(string $nom, int $idProduct, string $defaut = ''): string
    {
        foreach ([self::cle($nom, $idProduct), self::cle($nom)] as $cle) {
            $v = \Configuration::get($cle);

            if ($v !== false && $v !== '') {
                return (string) $v;
            }
        }

        return $defaut;
    }

    public static function poser(string $nom, int $idProduct, string $valeur): void
    {
        \Configuration::updateValue(self::cle($nom, $idProduct), $valeur);
    }

    /**
     * La fiche technique d'un produit.
     *
     * @return list<array{cle: string, valeur: string}>
     */
    public static function technique(int $idProduct): array
    {
        $sortie = [];

        foreach (self::TECHNIQUE as $cle => $defaut) {
            $sortie[] = ['cle' => $cle, 'valeur' => self::reglage('tech_' . $cle, $idProduct, $defaut)];
        }

        return $sortie;
    }

    /**
     * Les prestations d'un produit, prêtes à afficher.
     *
     * Le format de saisie est une ligne par option : `Libellé|supplément en
     * euros`. C'est ce qu'un marchand tape le plus vite, et ça se relit à l'œil
     * — un tableau de saisie coûterait dix fois plus d'écran pour trois lignes.
     *
     * Une prestation sans aucune option n'est pas proposée : mieux vaut ne rien
     * demander que de demander de choisir entre rien.
     *
     * @return list<array{cle: string, label: string, options: list<array{nom: string, centimes: int}>}>
     */
    public static function services(int $idProduct): array
    {
        $libelles = [
            'bat' => self::reglage('svc_bat_label', $idProduct, 'BAT numérique'),
            'creation' => self::reglage('svc_creation_label', $idProduct, 'Ma création graphique'),
        ];

        $sortie = [];

        foreach (self::SERVICES as $cle) {
            $options = self::lireOptions(self::reglage('svc_' . $cle, $idProduct));

            if ($options === []) {
                continue;
            }

            $sortie[] = ['cle' => $cle, 'label' => $libelles[$cle], 'options' => $options];
        }

        return $sortie;
    }

    /**
     * Les ventes phares : des raccourcis vers une configuration courante.
     *
     * ─── POURQUOI CELA SE RÈGLE, ET NE SE DÉDUIT PAS ───────────────────────
     *
     * « Top vente A5 » est une affirmation sur ce que les clients achètent.
     * Elle appartient au marchand, qui le sait. La déduire de l'arbre —
     * « prenons les deux premiers formats » — reviendrait à écrire au client
     * une information fausse sur une étiquette qui a l'air d'un conseil.
     *
     * ─── CE QUE LE MARCHAND SAISIT ─────────────────────────────────────────
     *
     * Une ligne par onglet : `Libellé|format`. Le format s'écrit avec le code
     * du fournisseur OU avec le nom affiché sur le site — le configurateur
     * accepte les deux, parce qu'un marchand voit les noms et jamais les codes.
     *
     * Sans séparateur, la ligne sert des deux : `A5` donne un onglet « A5 »
     * qui pointe sur le format A5.
     *
     * @return list<array{nom: string, cible: string}>
     */
    public static function ventesPhares(int $idProduct): array
    {
        $sortie = [];

        foreach (preg_split('/\R/', self::reglage('ventes', $idProduct)) ?: [] as $ligne) {
            $ligne = trim($ligne);

            if ($ligne === '') {
                continue;
            }

            $parts = explode('|', $ligne, 2);
            $nom = trim($parts[0]);
            $cible = trim($parts[1] ?? $parts[0]);

            if ($nom === '' || $cible === '') {
                continue;
            }

            $sortie[] = ['nom' => $nom, 'cible' => $cible];
        }

        return $sortie;
    }

    /**
     * Les réassurances affichées sous le bouton de commande.
     *
     * Une ligne par argument : `Libellé|icône`. L'icône est soit l'un des
     * pictogrammes que le module dessine — `origine`, `livraison`, `fichier`,
     * `paiement` — soit le chemin d'une image de la boutique.
     *
     * ⚠️ AUCUNE VALEUR PAR DÉFAUT. Ce sont des ENGAGEMENTS : « livraison
     * offerte », « paiement sécurisé », « fabriqué en région ». Les écrire à la
     * place du marchand reviendrait à promettre en son nom.
     *
     * ⚠️ Et aucun logo officiel n'est embarqué. Une marque territoriale ou un
     * label appartient à son émetteur et ne se distribue pas dans un module
     * public : le marchand pointe le sien, déposé sur sa propre boutique.
     *
     * @return list<array{nom: string, icone: string}>
     */
    public static function reassurances(int $idProduct): array
    {
        $sortie = [];

        foreach (preg_split('/\R/', self::reglage('reassurances', $idProduct)) ?: [] as $ligne) {
            $ligne = trim($ligne);

            if ($ligne === '') {
                continue;
            }

            $parts = explode('|', $ligne, 2);
            $nom = trim($parts[0]);

            if ($nom === '') {
                continue;
            }

            $sortie[] = ['nom' => $nom, 'icone' => self::icone(trim($parts[1] ?? ''))];
        }

        return $sortie;
    }

    /**
     * Une icône : un pictogramme connu, ou un chemin d'image accepté.
     *
     * Tout le reste est ramené au pictogramme neutre. Un chemin est accepté
     * s'il reste DANS la boutique ou pointe une adresse http(s) — de quoi
     * afficher un logo déposé par le marchand, sans laisser une valeur de
     * réglage devenir n'importe quelle URL.
     */
    private static function icone(string $brut): string
    {
        if (in_array($brut, ['origine', 'livraison', 'fichier', 'paiement'], true)) {
            return $brut;
        }

        if ($brut !== '' && preg_match('#^(/[^\s"\'<>]*|https?://[^\s"\'<>]+)$#', $brut) === 1) {
            return $brut;
        }

        return 'origine';
    }

    /**
     * Décode une saisie « Libellé|prix » en options.
     *
     * Un prix absent ou illisible vaut ZÉRO, jamais un refus : une ligne mal
     * tapée doit rendre l'option gratuite, pas faire disparaître la prestation
     * entière. Le marchand voit alors son erreur à l'écran plutôt que de
     * chercher pourquoi son bloc a disparu.
     *
     * @return list<array{nom: string, centimes: int}>
     */
    private static function lireOptions(string $brut): array
    {
        $sortie = [];

        foreach (preg_split('/\R/', $brut) ?: [] as $ligne) {
            $ligne = trim($ligne);

            if ($ligne === '') {
                continue;
            }

            $parts = explode('|', $ligne, 2);
            $nom = trim($parts[0]);

            if ($nom === '') {
                continue;
            }

            $prix = isset($parts[1]) ? str_replace(',', '.', trim($parts[1])) : '0';

            $sortie[] = [
                'nom' => $nom,
                'centimes' => is_numeric($prix) ? (int) round(((float) $prix) * 100) : 0,
            ];
        }

        return $sortie;
    }

    /**
     * Le supplément d'une sélection de prestations, en centimes.
     *
     * ⚠️ Calculé ICI, côté serveur, et jamais dans le navigateur. Un supplément
     * additionné côté client serait un montant que la boutique n'a pas vérifié
     * — et qui pourrait donc être n'importe lequel.
     *
     * @param  array<string, string>  $choix  clé de prestation => nom d'option
     */
    public static function supplement(int $idProduct, array $choix): int
    {
        $total = 0;

        foreach (self::services($idProduct) as $service) {
            $voulu = $choix[$service['cle']] ?? '';

            foreach ($service['options'] as $o) {
                if ($o['nom'] === $voulu) {
                    $total += $o['centimes'];
                    break;
                }
            }
        }

        return $total;
    }
}
