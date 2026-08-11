<?php

/**
 * EKO Sync — Imprimerie
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

/**
 * Les groupes de clients de la boutique, et ce à quoi ils correspondent dans E-KO.
 *
 * ⚠️ CES GROUPES NE PORTENT AUCUNE REMISE, et c'est le cœur du dispositif.
 *
 * PrestaShop sait appliquer une remise par groupe : poser 60 % sur « Revendeur »
 * serait le geste naturel. Ce serait aussi une SECONDE implémentation du barème,
 * avec ses propres arrondis, à côté de celle d'E-KO. Les deux tomberaient juste
 * la plupart du temps et divergeraient d'un centime le reste du temps — or la
 * règle posée est que le prix du site soit identique, au centime, à un devis
 * établi dans E-KO.
 *
 * Le prix remisé vient donc d'E-KO, toujours. Ces groupes ne servent qu'à deux
 * choses : décider si l'on affiche HT ou TTC, et savoir quel tarif demander pour
 * le client connecté.
 */

declare(strict_types=1);

namespace Eko\SyncImprimerie\Client;

class Groupes
{
    /** Prix affichés taxes comprises. */
    public const AFFICHAGE_TTC = 0;

    /** Prix affichés hors taxes. */
    public const AFFICHAGE_HT = 1;

    public const B2B = 'B2B';
    public const B2C = 'B2C';
    public const ASSOCIATION = 'ASSOCIATION';
    public const REVENDEUR = 'REVENDEUR';

    /**
     * Le rôle, son libellé boutique, son affichage, et la clé qui retient son id.
     *
     * L'ordre compte à l'écran : du plus courant au plus particulier.
     *
     * @var array<string, array{nom: string, affichage: int, cle: string, natif: bool}>
     */
    public const ROLES = [
        self::B2C => [
            'nom' => 'B2C',
            'affichage' => self::AFFICHAGE_TTC,
            'cle' => 'EKOSYNC_GROUPE_B2C',
            // Le groupe natif « Client » fait déjà exactement cela : tout compte
            // y entre à la création. En créer un second n'ajouterait qu'un
            // doublon que personne ne penserait à maintenir.
            'natif' => true,
        ],
        self::B2B => [
            'nom' => 'B2B',
            'affichage' => self::AFFICHAGE_HT,
            'cle' => 'EKOSYNC_GROUPE_B2B',
            'natif' => false,
        ],
        self::ASSOCIATION => [
            'nom' => 'Association',
            'affichage' => self::AFFICHAGE_TTC,
            'cle' => 'EKOSYNC_GROUPE_ASSO',
            'natif' => false,
        ],
        self::REVENDEUR => [
            'nom' => 'Revendeur',
            'affichage' => self::AFFICHAGE_HT,
            'cle' => 'EKOSYNC_GROUPE_REVENDEUR',
            'natif' => false,
        ],
    ];

    /**
     * Le rôle qui s'applique à un tiers E-KO.
     *
     * Le statut commercial PRIME sur la nature fiscale : une entreprise
     * revendeuse est un revendeur. C'est la seule règle de priorité, et elle va
     * dans ce sens parce que le statut commercial ne dit rien d'autre que « à
     * quel titre on lui vend » — exactement la question posée ici. La nature
     * fiscale, elle, commande la TVA et n'a pas à trancher un tarif.
     *
     * @param  array<string,mixed>  $tier  la fiche telle que l'API la rend
     */
    public function roleDe(array $tier): string
    {
        if ((string) ($tier['statut_commercial'] ?? '') === 'REVENDEUR') {
            return self::REVENDEUR;
        }

        return match ((string) ($tier['type_tiers'] ?? '')) {
            'PARTICULIER' => self::B2C,
            'ASSOCIATION' => self::ASSOCIATION,
            // ENTREPRISE, ADMINISTRATION, et l'absence de valeur : E-KO retient
            // ENTREPRISE par défaut, on ne s'en écarte pas ici.
            default => self::B2B,
        };
    }

    /** L'identifiant de groupe boutique d'un tiers, ou 0 si les groupes ne sont pas posés. */
    public function groupeDe(array $tier): int
    {
        return $this->identifiant($this->roleDe($tier));
    }

    /** L'identifiant retenu pour un rôle, ou 0 s'il n'a pas encore été posé. */
    public function identifiant(string $role): int
    {
        $cle = self::ROLES[$role]['cle'] ?? null;

        return $cle === null ? 0 : (int) \Configuration::get($cle);
    }

    /**
     * Crée ce qui manque, corrige ce qui a dérivé, et DIT ce qu'elle a fait.
     *
     * Idempotente : deux appels de suite ne changent rien au second. Elle ne
     * touche jamais en silence — chaque correction ressort dans le rapport,
     * parce qu'un réglage remis d'office sans le dire est indistinguable d'un
     * réglage que l'on n'a jamais fait.
     *
     * @return list<array{role: string, nom: string, id: int, affichage: int, action: string}>
     */
    public function assurer(): array
    {
        $rapport = [];

        foreach (self::ROLES as $role => $def) {
            $id = $this->identifiant($role);

            if ($id > 0 && !$this->existe($id)) {
                // L'identifiant retenu pointe dans le vide : quelqu'un a
                // supprimé le groupe côté boutique. On repart de zéro plutôt
                // que de rattacher des clients à un groupe fantôme.
                $id = 0;
            }

            if ($id === 0) {
                $id = $this->adopter($role, $def);
            }

            if ($id === 0) {
                $id = $this->creer($def);
                $action = $id > 0 ? 'créé' : 'ÉCHEC de création';
            } else {
                $action = 'réutilisé';
            }

            if ($id > 0) {
                \Configuration::updateValue($def['cle'], $id);
                $ecarts = $this->ecarts($id, $def['affichage']);

                if ($ecarts !== []) {
                    $action .= ' — À VÉRIFIER : ' . implode(', ', $ecarts);
                }
            }

            $rapport[] = [
                'role' => $role,
                'nom' => $def['nom'],
                'id' => $id,
                'affichage' => $def['affichage'],
                'action' => $action,
            ];
        }

        return $rapport;
    }

    /** Le groupe existe-t-il encore ? */
    private function existe(int $id): bool
    {
        return (bool) \Db::getInstance()->getValue(
            'SELECT `id_group` FROM `' . _DB_PREFIX_ . 'group` WHERE `id_group` = ' . $id
        );
    }

    /**
     * Reprend un groupe déjà présent plutôt que d'en créer un jumeau.
     *
     * Deux cas : le groupe natif de la boutique pour le B2C, et un groupe
     * portant déjà le bon nom — celui-ci existait avant le module.
     *
     * @param  array{nom: string, affichage: int, cle: string, natif: bool}  $def
     */
    private function adopter(string $role, array $def): int
    {
        if ($def['natif']) {
            $natif = (int) \Configuration::get('PS_CUSTOMER_GROUP');

            if ($natif > 0 && $this->existe($natif)) {
                return $natif;
            }
        }

        $trouve = \Db::getInstance()->getValue(
            'SELECT gl.`id_group` FROM `' . _DB_PREFIX_ . 'group_lang` gl'
            . ' WHERE gl.`name` = "' . pSQL($def['nom']) . '"'
            . ' ORDER BY gl.`id_group` ASC'
        );

        return $trouve ? (int) $trouve : 0;
    }

    /** @param  array{nom: string, affichage: int, cle: string, natif: bool}  $def */
    private function creer(array $def): int
    {
        $groupe = new \Group();
        $groupe->reduction = 0;
        $groupe->price_display_method = $def['affichage'];
        $groupe->show_prices = true;

        // Le nom se pose dans TOUTES les langues : un groupe sans libellé dans
        // une langue s'affiche vide dans le back-office de cette langue-là.
        $groupe->name = [];

        foreach (\Language::getLanguages(false) as $langue) {
            $groupe->name[(int) $langue['id_lang']] = $def['nom'];
        }

        return $groupe->add() ? (int) $groupe->id : 0;
    }

    /**
     * Ce qui s'écarte de ce que le module attend, SANS rien modifier.
     *
     * Une version antérieure corrigeait d'office. C'était une faute : les
     * groupes repris préexistent au module et leurs réglages appartiennent au
     * marchand. Forcer `price_display_method` sur le groupe client natif
     * bascule l'affichage de TOUTE la boutique ; effacer la remise d'un groupe
     * « Revendeur » déjà en place fait perdre son tarif à chaque revendeur —
     * en un clic, sans confirmation, et le rapport n'arrivait qu'après.
     *
     * On signale donc, et le marchand tranche.
     *
     * @return list<string>
     */
    private function ecarts(int $id, int $affichage): array
    {
        $groupe = new \Group($id);

        if (!\Validate::isLoadedObject($groupe)) {
            return [];
        }

        $ecarts = [];

        if ((float) $groupe->reduction != 0.0) {
            $ecarts[] = sprintf(
                'ce groupe applique une remise de %s %% ; le prix venant d\'E-KO, elle se cumulerait',
                rtrim(rtrim((string) (float) $groupe->reduction, '0'), '.')
            );
        }

        if ((int) $groupe->price_display_method !== $affichage) {
            $ecarts[] = sprintf(
                'affichage en %s, attendu %s',
                (int) $groupe->price_display_method === self::AFFICHAGE_HT ? 'HT' : 'TTC',
                $affichage === self::AFFICHAGE_HT ? 'HT' : 'TTC'
            );
        }

        return $ecarts;
    }
}
