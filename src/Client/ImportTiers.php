<?php

/**
 * EKO Sync — Imprimerie
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

/**
 * Crée un compte boutique à partir d'un tiers E-KO.
 *
 * Sens ERP → PrestaShop. L'ERP fait foi sur l'identité commerciale ; la
 * boutique n'invente rien, elle recopie et rattache.
 *
 * ⚠️ AUCUN MOT DE PASSE N'EST CHOISI, AFFICHÉ NI ENVOYÉ ICI. Le compte est créé
 * avec un secret aléatoire que personne ne connaît — pas même l'opérateur qui
 * lance l'import — et le client le remplace par la procédure normale de
 * réinitialisation. Distribuer un mot de passe par un canal détourné est le
 * défaut de sécurité classique de ce genre d'import.
 *
 * La correspondance est mémorisée dans une petite table, créée à la première
 * utilisation plutôt qu'à l'installation du module : sur cet hébergement,
 * `Module::install()` meurt dans Language.php et tout ce qu'il devait créer
 * n'existerait pas.
 */

declare(strict_types=1);

namespace Eko\SyncImprimerie\Client;

class ImportTiers
{
    public function __construct(
        private readonly DepotEko $depot,
        private readonly ClientEko $client,
        private readonly Groupes $groupes,
    ) {}

    /**
     * Cherche des tiers par nom ou par e-mail.
     *
     * @return array{ok: bool, lignes: list<array<string,mixed>>, erreur: string}
     */
    public function chercher(string $terme, int $limite = 20): array
    {
        $terme = trim($terme);

        if (mb_strlen($terme) < 3) {
            return ['ok' => false, 'lignes' => [], 'erreur' => 'Saisir au moins trois caractères.'];
        }

        $r = $this->client->appeler(
            'GET',
            '/api/v1/tiers?search=' . rawurlencode($terme) . '&per_page=' . max(1, min(100, $limite))
        );

        if (!$r['ok']) {
            return ['ok' => false, 'lignes' => [], 'erreur' => $r['erreur']];
        }

        $brut = $r['donnees']['data'] ?? $r['donnees'];

        return [
            'ok' => true,
            'lignes' => is_array($brut) ? array_values(array_filter($brut, 'is_array')) : [],
            'erreur' => '',
        ];
    }

    /**
     * Crée — ou rattache — le compte boutique correspondant à un tiers.
     *
     * ⚠️ Le paramètre est le CODE du tiers (« CU0000042 »), pas son identifiant
     * numérique. La route `/api/v1/tiers/{tier}` lie par `code` — le modèle
     * surcharge `getRouteKeyName()` — alors que la ressource expose un champ
     * `id` numérique. Lire `id` et le passer à la route rend un 404 dont le
     * message parle du modèle, pas de la clé : « No query results for model
     * [Tier] 42 ».
     *
     * @return array{ok: bool, cree: bool, id_customer: int, message: string}
     */
    public function importer(string $tierCode): array
    {
        $tierCode = trim($tierCode);

        if ($tierCode === '') {
            return $this->echec('Aucun code de tiers fourni.');
        }

        $r = $this->client->appeler('GET', '/api/v1/tiers/' . rawurlencode($tierCode));

        if (!$r['ok']) {
            return $this->echec('Tiers introuvable dans E-KO : ' . $r['erreur']);
        }

        $tier = $r['donnees']['data'] ?? $r['donnees'];
        $tierId = (int) ($tier['id'] ?? 0);
        $email = trim(mb_strtolower((string) ($tier['email'] ?? '')));

        // Sans adresse e-mail, pas de compte : c'est l'identifiant de connexion
        // de PrestaShop, et la seule clé de rapprochement entre les deux
        // systèmes. Mieux vaut refuser que créer un compte inutilisable.
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->echec(sprintf(
                'Le tiers « %s » n\'a pas d\'adresse e-mail exploitable : compte impossible.',
                (string) ($tier['name'] ?? $tierCode)
            ));
        }

        if (!($tier['is_customer'] ?? false)) {
            return $this->echec(sprintf(
                'Le tiers « %s » n\'est pas un client dans E-KO. Import refusé.',
                (string) ($tier['name'] ?? $tierCode)
            ));
        }

        $existant = (int) \Customer::customerExists($email, true);

        if ($existant > 0) {
            $this->lier($existant, $tierId);
            $deplace = $this->classer($existant, $tier);
            $commercial = $this->rafraichirChampsB2b($existant, $tier);
            $adresses = $this->importerAdresses($tierCode, $existant, $tier);

            return [
                'ok' => true,
                'cree' => false,
                'id_customer' => $existant,
                'message' => sprintf(
                    'Un compte existait déjà pour %s : il a été rattaché au tiers %d.%s%s%s',
                    $email,
                    $tierId,
                    $deplace === '' ? '' : ' ' . $deplace,
                    $commercial === '' ? '' : ' ' . $commercial,
                    $this->resumeAdresses($adresses)
                ),
            ];
        }

        // L'etat civil vient du premier contact de la fiche, pas de la raison
        // sociale : une societe n'a ni prenom ni nom, et repeter son enseigne
        // dans les deux champs donne « ATELIER ATELIER » partout ou PrestaShop
        // affiche « prenom nom ». Sans contact exploitable, on retombe sur la
        // raison sociale nettoyee.
        $contact = $this->premierContact($tierCode);

        $client = new \Customer();
        $client->email = $email;
        $client->firstname = $this->prenom($tier, $contact);
        $client->lastname = $this->nom($tier, $contact);
        $client->id_gender = $this->civilite($contact);
        $client->company = (string) ($tier['commercial_name'] ?? $tier['name'] ?? '');
        $client->website = (string) ($tier['website'] ?? '');
        $client->active = (bool) ($tier['is_active'] ?? true);
        $client->newsletter = false;
        $client->optin = false;

        // Mode B2B : `id_risk` vaut 0 par défaut sur l'objet, or aucun risque ne
        // porte ce numéro — la fiche du back-office joint dessus. On pose le
        // premier risque réel (« Aucun »), pas un identifiant qui ne résout pas.
        $client->id_risk = (int) (\Db::getInstance()->getValue(
            'SELECT MIN(`id_risk`) FROM `' . _DB_PREFIX_ . 'risk`'
        ) ?: 1);

        $this->poserChampsB2b($client, $tier);

        // Secret aléatoire jamais communiqué : le client passe par « mot de
        // passe oublié ». On ne le stocke nulle part et on ne l'affiche pas.
        $client->passwd = \Tools::hash(bin2hex(random_bytes(24)));

        $groupe = $this->groupes->groupeDe($tier);

        if ($groupe > 0) {
            $client->id_default_group = $groupe;
        }

        if (!$client->add()) {
            return $this->echec('PrestaShop a refusé la création du compte.');
        }

        if ($groupe > 0) {
            // Le groupe par défaut ET le groupe natif : PrestaShop attend qu'un
            // compte appartienne au groupe des clients enregistrés, faute de
            // quoi certaines règles de la boutique cessent de le voir.
            $client->updateGroup($this->appartenances($client, $groupe));
        }

        $this->lier((int) $client->id, $tierId);
        $adresses = $this->importerAdresses($tierCode, (int) $client->id, $tier);

        return [
            'ok' => true,
            'cree' => true,
            'id_customer' => (int) $client->id,
            'message' => sprintf(
                'Compte créé pour %s (%s), groupe %s. Le client définira son mot de passe par « mot de passe oublié ».%s',
                $email,
                $client->company !== '' ? $client->company : $client->lastname,
                $this->groupes->roleDe($tier),
                $this->resumeAdresses($adresses)
            ),
        ];
    }

    /**
     * Pose sur le compte PrestaShop les informations commerciales du tiers.
     *
     * Ces champs ne s'AFFICHENT au back-office que si le mode B2B est actif,
     * mais PrestaShop les persiste dans tous les cas. On les écrit donc
     * toujours : ne pas le faire laisserait des comptes incomplets le jour où
     * le marchand activerait le mode, sans qu'aucune reprise ne repasse dessus.
     *
     * ─── Le code APE, et pourquoi on ne le devine pas ─────────────────────
     *
     * `ape` vient d'E-KO (1.95.99 et supérieur) et de nulle part ailleurs. Le
     * déduire du SIREN serait une invention : deux entreprises aux numéros
     * voisins exercent des métiers sans rapport, et un code faux se recopie
     * ensuite partout où il passe. Sans code chez l'ERP, le champ reste vide.
     *
     * `Validate::isApe()` de PrestaShop exige 4 chiffres et une lettre — la
     * forme rangée, celle qu'E-KO enregistre. Un code qui ne passe pas est
     * laissé de côté plutôt qu'écrit de travers : PrestaShop REFUSERAIT
     * l'enregistrement du client entier sur un `ape` invalide, et le compte ne
     * serait pas créé du tout.
     *
     * ─── Les deux correspondances qui ne sont pas des égalités ─────────────
     *
     * Un encours ABSENT dans E-KO veut dire « aucun plafond fixé », pas
     * « plafond de zéro ». PrestaShop n'a pas de valeur pour dire « illimité » :
     * zéro y signifie « aucun crédit ». On retient donc zéro, qui est le côté
     * prudent de l'erreur — accorder un découvert que personne n'a décidé
     * serait le mauvais sens.
     *
     * `end_of_month` n'a aucun équivalent : PrestaShop ne connaît qu'un nombre
     * de jours. « 30 jours fin de mois » vaut donc 30 ici, quand l'échéance
     * réelle peut aller jusqu'à 60. On sous-estime plutôt qu'on ne surestime :
     * `max_payment_days` sert à surveiller un risque, pas à facturer.
     *
     * @param  array<string, mixed>  $tier
     */
    private function poserChampsB2b(\Customer $client, array $tier): void
    {
        // Le SIRET n'accepte qu'un vrai SIRET : 14 chiffres et une clé de
        // Luhn. Un numéro qui ne passe pas est laissé de côté plutôt qu'écrit
        // de travers dans un champ qu'un comptable lit comme un SIRET.
        $siret = preg_replace('/\D/', '', (string) ($tier['siret'] ?? '')) ?? '';
        $client->siret = \Validate::isSiret($siret) ? $siret : '';

        // Le point de « 18.12Z » est de la présentation : E-KO enregistre la
        // forme rangée, mais on la range de nouveau ici — un intégrateur tiers
        // peut avoir écrit dans l'ERP par un autre chemin.
        $ape = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) ($tier['code_ape'] ?? '')) ?? '');
        $client->ape = \Validate::isApe($ape) ? $ape : '';

        $encours = $tier['encours_limit'] ?? null;
        $client->outstanding_allow_amount = $encours === null ? 0.0 : max(0.0, (float) $encours);

        $conditions = $tier['payment_terms'] ?? null;
        $client->max_payment_days = is_array($conditions)
            ? max(0, (int) ($conditions['lead_days'] ?? 0))
            : 0;
    }

    /**
     * Remet à jour les informations commerciales d'un compte qui existait déjà.
     *
     * Sans cela, un compte créé avant que l'ERP ne connaisse son encours — ou
     * avant que l'API ne sache l'exposer — restait à zéro pour toujours :
     * aucune reprise ne repassait sur ces champs. Le plafond de crédit d'un
     * client peut changer, et c'est l'ERP qui en décide.
     *
     * Le changement est RAPPORTÉ, jamais silencieux : modifier l'encours d'un
     * compte sans le dire, c'est fermer ou ouvrir un crédit sans trace.
     *
     * @param  array<string, mixed>  $tier
     */
    private function rafraichirChampsB2b(int $idCustomer, array $tier): string
    {
        $client = new \Customer($idCustomer);

        if (!\Validate::isLoadedObject($client)) {
            return '';
        }

        $avant = [
            'siret' => (string) $client->siret,
            'ape' => (string) $client->ape,
            'encours' => (float) $client->outstanding_allow_amount,
            'delai' => (int) $client->max_payment_days,
        ];

        $this->poserChampsB2b($client, $tier);

        $apres = [
            'siret' => (string) $client->siret,
            'ape' => (string) $client->ape,
            'encours' => (float) $client->outstanding_allow_amount,
            'delai' => (int) $client->max_payment_days,
        ];

        if ($avant === $apres) {
            return '';
        }

        if (!$client->update()) {
            return 'Les informations commerciales n\'ont pas pu être mises à jour.';
        }

        $changes = [];

        if ($avant['siret'] !== $apres['siret']) {
            $changes[] = 'SIRET';
        }

        if ($avant['ape'] !== $apres['ape']) {
            $changes[] = 'code APE';
        }

        if (abs($avant['encours'] - $apres['encours']) > 0.005) {
            $changes[] = sprintf('encours %s → %s €', $avant['encours'], $apres['encours']);
        }

        if ($avant['delai'] !== $apres['delai']) {
            $changes[] = sprintf('délai de paiement %d → %d jours', $avant['delai'], $apres['delai']);
        }

        return 'Informations commerciales mises à jour (' . implode(', ', $changes) . ').';
    }

    /**
     * Range un compte existant dans le groupe que dit E-KO, s'il n'y est pas.
     *
     * L'ERP fait foi : un client passé revendeur là-bas doit voir les tarifs
     * revendeurs ici. Le déplacement est RAPPORTÉ — changer en silence le
     * groupe d'un compte, donc les prix qu'il voit, serait le genre d'effet de
     * bord qu'on ne retrouve plus six mois plus tard.
     *
     * @param  array<string,mixed>  $tier
     */
    private function classer(int $idCustomer, array $tier): string
    {
        $vise = $this->groupes->groupeDe($tier);

        if ($vise === 0) {
            return '';
        }

        $client = new \Customer($idCustomer);

        if (!\Validate::isLoadedObject($client) || (int) $client->id_default_group === $vise) {
            return '';
        }

        $client->id_default_group = $vise;
        $client->update();
        $client->updateGroup($this->appartenances($client, $vise));

        return sprintf('Groupe corrigé d\'après E-KO : %s.', $this->groupes->roleDe($tier));
    }

    /**
     * Ce que la reprise des adresses a donné, en une phrase.
     *
     * Le détail sort intégralement : une adresse refusée sans qu'on dise
     * laquelle ni pourquoi, c'est une donnée perdue que personne ne cherchera.
     *
     * @param  list<array{eko_id: int, action: string}>  $rapport
     */
    private function resumeAdresses(array $rapport): string
    {
        if ($rapport === []) {
            return ' Aucune adresse dans l\'ERP.';
        }

        return ' Adresses : ' . implode(' ; ', array_map(
            static fn (array $l): string => ($l['eko_id'] > 0 ? '#' . $l['eko_id'] . ' ' : '') . $l['action'],
            $rapport
        )) . '.';
    }

    /**
     * Recopie les adresses du tiers dans le carnet du compte boutique.
     *
     * PrestaShop gère le multi-adresse comme l'ERP : la reprise est donc une
     * copie, pas un choix. Deux garde-fous méritent d'être connus :
     *
     * 1. `address1` et `city` sont OBLIGATOIRES côté PrestaShop. Une adresse
     *    d'ERP qui n'a pas de voie ou pas de ville est refusée et RAPPORTÉE —
     *    la créer amputée donnerait un bon de livraison inutilisable.
     * 2. Le pays doit exister dans la boutique. S'il n'y est pas activé,
     *    l'adresse est créée quand même mais le rapport le signale : le client
     *    ne pourrait pas s\'en servir en commande tant que le marchand ne
     *    l\'active pas.
     *
     * L\'opération est idempotente : une petite table retient quelle adresse
     * d\'ERP a produit quelle adresse boutique, si bien qu\'un second import ne
     * duplique rien.
     *
     * @param  array<string,mixed>  $tier
     * @return list<array{eko_id: int, action: string}>
     */
    public function importerAdresses(string $tierCode, int $idCustomer, array $tier): array
    {
        $r = $this->client->appeler('GET', '/api/v1/tiers/' . rawurlencode($tierCode) . '/addresses');

        if (!$r['ok']) {
            return [['eko_id' => 0, 'action' => 'lecture impossible : ' . $r['erreur']]];
        }

        $lignes = $r['donnees']['data'] ?? $r['donnees'];

        if (!is_array($lignes)) {
            return [];
        }

        $client = new \Customer($idCustomer);

        if (!\Validate::isLoadedObject($client)) {
            return [['eko_id' => 0, 'action' => 'compte boutique introuvable']];
        }

        $this->tableAdresses();
        $rapport = [];

        foreach ($lignes as $a) {
            if (!is_array($a)) {
                continue;
            }

            $rapport[] = $this->reprendreUneAdresse($a, $client, $tier);
        }

        return $rapport;
    }

    /**
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $tier
     * @return array{eko_id: int, action: string}
     */
    private function reprendreUneAdresse(array $a, \Customer $client, array $tier): array
    {
        $ekoId = (int) ($a['id'] ?? 0);

        if ($ekoId <= 0) {
            return ['eko_id' => 0, 'action' => 'adresse sans identifiant : ignorée'];
        }

        $deja = (int) \Db::getInstance()->getValue(
            'SELECT `id_address` FROM `' . _DB_PREFIX_ . 'ekosync_address`'
            . ' WHERE `eko_address_id` = ' . $ekoId . ' AND `id_customer` = ' . (int) $client->id
        );

        if ($deja > 0 && \Validate::isLoadedObject(new \Address($deja))) {
            return ['eko_id' => $ekoId, 'action' => 'déjà reprise (adresse #' . $deja . ')'];
        }

        $voie = trim((string) ($a['street'] ?? ''));
        $ville = trim((string) ($a['city'] ?? ''));

        // PrestaShop exige les deux. Une adresse amputée vaut moins que pas
        // d'adresse : elle produirait un bon de livraison inutilisable.
        if ($voie === '' || $ville === '') {
            return ['eko_id' => $ekoId, 'action' => 'refusée : ' . ($voie === '' ? 'aucune voie' : 'aucune ville')];
        }

        $iso = strtoupper(trim((string) ($a['country'] ?? '')));
        $idPays = $iso === '' ? 0 : (int) \Country::getByIso($iso);

        if ($idPays <= 0) {
            return ['eko_id' => $ekoId, 'action' => 'refusée : pays « ' . htmlspecialchars($iso) . ' » inconnu de la boutique'];
        }

        $adresse = new \Address();
        $adresse->id_customer = (int) $client->id;
        $adresse->id_country = $idPays;
        $adresse->alias = $this->alias($a);
        $adresse->firstname = $client->firstname;
        $adresse->lastname = $client->lastname;
        $adresse->company = (string) ($tier['commercial_name'] ?? $tier['name'] ?? '');
        $adresse->address1 = mb_substr($voie, 0, 128);
        $adresse->address2 = mb_substr(trim((string) ($a['street2'] ?? '')), 0, 128);
        $adresse->postcode = mb_substr(trim((string) ($a['postal_code'] ?? '')), 0, 12);
        $adresse->city = mb_substr($ville, 0, 64);
        $adresse->phone = mb_substr(trim((string) ($a['contact_phone'] ?? '')), 0, 32);
        $adresse->other = mb_substr(trim((string) ($a['notes'] ?? '')), 0, 300);

        // La TVA intracommunautaire vit sur l'ADRESSE dans PrestaShop, pas sur
        // le client : c'est l'adresse de facturation qui porte le numéro sur
        // une facture. Le module l'écrivait sur le `Customer`, où aucun champ
        // `vat_number` n'existe — ni dans la définition de l'objet, ni en base.
        // `ObjectModel` n'émet que les champs qu'il déclare : la valeur était
        // jetée en silence, à la création comme à la mise à jour, sans la
        // moindre erreur. Le numéro que l'ERP donnait n'arrivait nulle part.
        //
        // E-KO porte le numéro PAR TIERS, PrestaShop PAR ADRESSE : on le pose
        // donc sur chacune des adresses reprises. C'est le seul choix qui ne
        // perd rien ; l'inverse — n'en servir qu'une — laisserait les autres
        // factures sans numéro.
        $adresse->vat_number = mb_substr(trim((string) ($tier['vat_intracom'] ?? '')), 0, 32);

        $erreur = $adresse->validateFields(false, true);

        if ($erreur !== true) {
            return ['eko_id' => $ekoId, 'action' => 'refusée par PrestaShop : ' . (string) $erreur];
        }

        if (!$adresse->add()) {
            return ['eko_id' => $ekoId, 'action' => 'refusée : PrestaShop n\'a pas enregistré l\'adresse'];
        }

        \Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'ekosync_address` (`id_address`, `eko_address_id`, `id_customer`, `date_add`) '
            . 'VALUES (' . (int) $adresse->id . ', ' . $ekoId . ', ' . (int) $client->id . ', NOW()) '
            . 'ON DUPLICATE KEY UPDATE `eko_address_id` = ' . $ekoId
        );

        $actif = (bool) \Db::getInstance()->getValue(
            'SELECT `active` FROM `' . _DB_PREFIX_ . 'country` WHERE `id_country` = ' . $idPays
        );

        return [
            'eko_id' => $ekoId,
            'action' => 'créée (adresse #' . (int) $adresse->id . ')'
                . ($actif ? '' : ' — ⚠ le pays ' . htmlspecialchars($iso) . ' n\'est pas activé dans la boutique'),
        ];
    }

    /**
     * Un libellé d'adresse que PrestaShop accepte, et qui reste lisible.
     *
     * `alias` est limité à 32 caractères et validé par `isGenericName`. Le
     * libellé de l'ERP passe en premier ; à défaut, le type de l'adresse, qui
     * dit au moins de quoi il s'agit.
     *
     * @param  array<string,mixed>  $a
     */
    private function alias(array $a): string
    {
        // Le client voit cet intitulé dans son carnet d'adresses : « main » y
        // serait un code technique échappé jusqu'à l'écran.
        $parType = [
            'main' => 'Adresse principale',
            'billing' => 'Facturation',
            'delivery' => 'Livraison',
            'shipping' => 'Livraison',
            'warehouse' => 'Entrepôt',
            'site' => 'Chantier',
        ];

        $type = strtolower(trim((string) ($a['type'] ?? '')));

        foreach ([
            (string) ($a['label'] ?? ''),
            $parType[$type] ?? '',
            $type,
        ] as $candidat) {
            $propre = trim((string) preg_replace('/\s+/u', ' ', $candidat));
            $propre = mb_substr($propre, 0, 32);

            if ($propre !== '' && \Validate::isGenericName($propre)) {
                return $propre;
            }
        }

        return 'Adresse';
    }

    /**
     * Retient quelle adresse d'ERP a produit quelle adresse boutique.
     *
     * Sans elle, chaque import recréerait le carnet entier. La clé primaire est
     * l'adresse BOUTIQUE : une adresse d'ERP peut légitimement produire une
     * adresse chez plusieurs comptes du même tiers.
     */
    private function tableAdresses(): void
    {
        \Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ekosync_address` ('
            . '`id_address` INT UNSIGNED NOT NULL,'
            . '`eko_address_id` INT UNSIGNED NOT NULL,'
            . '`id_customer` INT UNSIGNED NOT NULL,'
            . '`date_add` DATETIME NOT NULL,'
            . 'PRIMARY KEY (`id_address`),'
            . 'KEY `eko_customer` (`eko_address_id`, `id_customer`)'
            . ') ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
        );
    }

    /**
     * Les groupes que doit porter ce compte, sans lui en retirer d'autres.
     *
     * `Customer::updateGroup()` commence par `cleanGroups()`, qui SUPPRIME
     * toutes les appartenances avant d'ajouter la liste fournie : passer les
     * deux seuls groupes du module effacerait les groupes métier du marchand
     * (« VIP », « Grossiste »…), et avec eux les règles de catalogue, de prix
     * spécifiques et de transporteurs qui s'y accrochent.
     *
     * On repart donc des appartenances existantes, on retire seulement les
     * AUTRES groupes gérés par ce module — un client ne peut pas être à la
     * fois revendeur et association — et on ajoute le sien.
     *
     * @return list<int>
     */
    private function appartenances(\Customer $client, int $groupe): array
    {
        $actuelles = array_map('intval', $client->getGroups() ?: []);

        $notres = [];
        foreach (array_keys(Groupes::ROLES) as $role) {
            $id = $this->groupes->identifiant($role);
            if ($id > 0) {
                $notres[] = $id;
            }
        }

        $gardees = array_filter($actuelles, static fn (int $id): bool => !in_array($id, $notres, true));

        return array_values(array_unique(array_filter(array_merge(
            $gardees,
            [$groupe, (int) \Configuration::get('PS_CUSTOMER_GROUP')]
        ))));
    }

    /**
     * Le tiers E-KO d'un client — retrouvé, ou créé s'il n'en a pas.
     *
     * ─── POURQUOI CETTE MÉTHODE EXISTE ────────────────────────────────────
     *
     * `tierDe()` ne lit qu'une table de correspondance locale, alimentée par
     * l'import descendant. Un client qui commande en ligne sans exister au
     * préalable dans l'ERP n'y figure donc pas, et sa commande s'arrêtait sur
     * « Client sans tiers E-KO » — mesuré le 2026-08-20, commande #11. La
     * boutique encaissait, l'atelier ne voyait rien.
     *
     * Trois temps, du moins coûteux au plus engageant :
     *
     *   1. la correspondance locale ;
     *   2. une recherche par ADRESSE dans l'ERP — le client a pu commander au
     *      comptoir avant de créer un compte en ligne, et créer un doublon
     *      couperait son historique en deux ;
     *   3. la création, en dernier recours.
     *
     * ⚠️ `code` est INTERDIT au POST — mesuré dans `TiersController::store()`,
     * règle `prohibited`. L'ERP l'attribue par son compteur et le rend dans
     * `data.code`. L'envoyer produit un 422 ; ne pas l'envoyer et supposer
     * l'avoir choisi produit pire — un 201 suivi de 404 sur toutes les URL
     * bâties avec la valeur qu'on croyait posée.
     *
     * En cas d'ambiguïté — plusieurs tiers sur la même adresse — on REFUSE.
     * Rattacher un client au mauvais tiers lui montrerait les documents d'un
     * autre.
     *
     * @return array{ok: bool, tier_id?: int, origine?: string, message?: string}
     */
    public function tierPour(\Customer $client): array
    {
        $idCustomer = (int) $client->id;

        if ($idCustomer <= 0) {
            return ['ok' => false, 'message' => 'Client sans identifiant.'];
        }

        $connu = $this->tierDe($idCustomer);

        if ($connu !== null) {
            return ['ok' => true, 'tier_id' => $connu, 'origine' => 'correspondance'];
        }

        $email = trim((string) $client->email);

        // ── 2. Déjà dans l'ERP sous cette adresse ? ──
        // `$this->depot` est déjà injecté : inutile d'en fabriquer un second.
        $recherche = $this->depot->tierParEmail($email);

        if (($recherche['ambigu'] ?? false) === true) {
            return ['ok' => false, 'message' => $recherche['erreur']];
        }

        if (($recherche['trouve'] ?? false) === true) {
            $id = (int) (($recherche['tier']['id'] ?? 0));

            if ($id > 0) {
                $this->lier($idCustomer, $id);

                return ['ok' => true, 'tier_id' => $id, 'origine' => 'retrouvé par e-mail'];
            }
        }

        // ── 3. Le créer ──
        $nom = trim((string) $client->company);

        if ($nom === '') {
            $nom = trim($client->firstname . ' ' . $client->lastname);
        }

        if ($nom === '') {
            $nom = $email !== '' ? $email : ('Client boutique #' . $idCustomer);
        }

        $charge = [
            'name' => mb_substr($nom, 0, 255),
            'is_customer' => true,
            'is_active' => true,
        ];

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $charge['email'] = $email;
        }

        $r = $this->client->appeler('POST', '/api/v1/tiers', $charge);

        if (!$r['ok']) {
            return ['ok' => false, 'message' => 'Création du tiers refusée : ' . $r['erreur']];
        }

        $donnees = $r['donnees']['data'] ?? [];
        $id = (int) ($donnees['id'] ?? 0);

        if ($id <= 0) {
            return ['ok' => false, 'message' => 'Tiers créé sans identifiant exploitable.'];
        }

        $this->lier($idCustomer, $id);

        \PrestaShopLogger::addLog(
            'ekosyncimprimerie — tiers E-KO créé pour le client ' . $idCustomer
            . ' : #' . $id . ' (' . (string) ($donnees['code'] ?? 'sans code') . ')',
            1,
            null,
            'Customer',
            $idCustomer
        );

        return ['ok' => true, 'tier_id' => $id, 'origine' => 'créé'];
    }

    /** Identifiant du tiers E-KO rattaché à un client, s'il existe. */
    public function tierDe(int $idCustomer): ?int
    {
        $this->table();

        $v = \Db::getInstance()->getValue(
            'SELECT `tier_id` FROM `' . _DB_PREFIX_ . 'ekosync_tier` WHERE `id_customer` = ' . $idCustomer
        );

        return $v ? (int) $v : null;
    }

    private function lier(int $idCustomer, int $tierId): void
    {
        $this->table();

        \Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'ekosync_tier` (`id_customer`, `tier_id`, `date_add`) '
            . 'VALUES (' . $idCustomer . ', ' . $tierId . ', NOW()) '
            . 'ON DUPLICATE KEY UPDATE `tier_id` = ' . $tierId
        );
    }

    /**
     * Crée la table de correspondance si besoin.
     *
     * `id_customer` est la clé primaire : un compte boutique ne peut être
     * rattaché qu'à UN tiers. L'inverse est permis — plusieurs comptes pour un
     * même tiers, ce qui arrive quand une entreprise a plusieurs interlocuteurs.
     */
    private function table(): void
    {
        \Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ekosync_tier` ('
            . '`id_customer` INT UNSIGNED NOT NULL,'
            . '`tier_id` INT UNSIGNED NOT NULL,'
            . '`date_add` DATETIME NOT NULL,'
            . 'PRIMARY KEY (`id_customer`),'
            . 'KEY `tier_id` (`tier_id`)'
            . ') ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
        );
    }

    /**
     * Un nom que PrestaShop accepte, à partir de ce que porte l'ERP.
     *
     * `Validate::isName()` refuse les CHIFFRES et une longue liste de symboles.
     * « ATELIER 12 » est donc un nom de famille invalide — et les raisons
     * sociales à chiffres sont tout sauf rares. Le refus est net et bloque la
     * création du compte, sans rien dire d'utile : « La propriété
     * Customer->lastname n'est pas valide. »
     *
     * On ne triche pas sur l'identité : la raison sociale COMPLÈTE, chiffres
     * compris, va dans `company`, qui l'accepte. Ce nettoyage ne sert qu'aux
     * deux champs d'état civil que PrestaShop impose et contraint.
     *
     * Le garde final est `Validate::isName()` lui-même plutôt qu'une copie de
     * son expression régulière : une copie divergerait au premier changement
     * de version.
     */
    private function nomAcceptable(string $brut, string $defaut): string
    {
        // Les chiffres et les symboles que le validateur refuse, retirés
        // plutôt que remplacés : « ATELIER 12 » devient « ATELIER ».
        $propre = preg_replace('/[0-9!<>,;?=+()@#"°{}_$%:¤|]/u', ' ', $brut) ?? '';
        $propre = trim((string) preg_replace('/\s+/u', ' ', $propre));
        $propre = mb_substr($propre, 0, 32);

        return $propre !== '' && \Validate::isName($propre) ? $propre : $defaut;
    }

    /**
     * Le premier contact exploitable de la fiche, ou `null`.
     *
     * Un contact marque prive appartient a la personne, pas a la societe : on
     * ne s'en sert pas pour nommer un compte d'entreprise. L'ordre rendu par
     * l'ERP fait foi pour le reste — le multi-contact PrestaShop viendra plus
     * tard, un compte ne portant aujourd'hui qu'un seul etat civil.
     *
     * @return array<string,mixed>|null
     */
    private function premierContact(string $tierCode): ?array
    {
        $r = $this->client->appeler('GET', '/api/v1/tiers/' . rawurlencode($tierCode) . '/contacts');

        if (!$r['ok']) {
            return null;
        }

        $lignes = $r['donnees']['data'] ?? $r['donnees'];

        if (!is_array($lignes)) {
            return null;
        }

        foreach ($lignes as $c) {
            if (!is_array($c) || ($c['is_private'] ?? false)) {
                continue;
            }

            $prenom = trim((string) ($c['first_name'] ?? ''));
            $nom = trim((string) ($c['last_name'] ?? ''));

            if ($prenom !== '' || $nom !== '') {
                return $c;
            }
        }

        return null;
    }

    /**
     * La civilite PrestaShop correspondant a celle de l'ERP.
     *
     * `gender.type` vaut 0 pour le masculin et 1 pour le feminin ; les
     * identifiants, eux, dependent de la boutique et ne sont pas garantis.
     * On interroge donc la table plutot que d'ecrire 1 et 2 en dur.
     *
     * @param  array<string,mixed>|null  $contact
     */
    private function civilite(?array $contact): int
    {
        $brut = strtoupper(trim((string) ($contact['civility'] ?? '')));

        $type = match ($brut) {
            'MR', 'M', 'M.', 'MONSIEUR' => 0,
            'MME', 'MRS', 'MS', 'MADAME' => 1,
            default => null,
        };

        if ($type === null) {
            return 0;
        }

        return (int) (\Db::getInstance()->getValue(
            'SELECT MIN(`id_gender`) FROM `' . _DB_PREFIX_ . 'gender` WHERE `type` = ' . $type
        ) ?: 0);
    }

    /**
     * @param  array<string,mixed>  $tier
     * @param  array<string,mixed>|null  $contact
     */
    private function prenom(array $tier, ?array $contact = null): string
    {
        $duContact = trim((string) ($contact['first_name'] ?? ''));

        if ($duContact !== '') {
            return $this->nomAcceptable($duContact, 'Client');
        }

        $nom = trim((string) ($tier['name'] ?? ''));
        $morceaux = preg_split('/\s+/', $nom) ?: [];

        // Pour une entreprise, PrestaShop exige quand même un nom et un prénom.
        // On ne fabrique pas un faux état civil : on répète la raison sociale
        // plutôt que d'inventer « M. Dupont » à partir de « DUPONT SARL ».
        // Un particulier a un vrai prénom. Une entreprise n'en a pas : répéter
        // sa raison sociale dans les deux champs donnait « ATELIER ATELIER »
        // partout où PrestaShop affiche « prénom nom ». On pose un libellé
        // neutre et vrai — c'est bien le contact de cette société — plutôt que
        // d'inventer un état civil ou de doubler le nom.
        if (($tier['type_tiers'] ?? '') !== 'PARTICULIER') {
            return 'Contact';
        }

        return $this->nomAcceptable(count($morceaux) > 1 ? $morceaux[0] : $nom, 'Client');
    }

    /**
     * @param  array<string,mixed>  $tier
     * @param  array<string,mixed>|null  $contact
     */
    private function nom(array $tier, ?array $contact = null): string
    {
        $duContact = trim((string) ($contact['last_name'] ?? ''));

        if ($duContact !== '') {
            return $this->nomAcceptable($duContact, 'E-KO');
        }

        $nom = trim((string) ($tier['name'] ?? ''));
        $morceaux = preg_split('/\s+/', $nom) ?: [];

        $candidat = count($morceaux) > 1 && ($tier['type_tiers'] ?? '') === 'PARTICULIER'
            ? implode(' ', array_slice($morceaux, 1))
            : $nom;

        return $this->nomAcceptable($candidat, 'E-KO');
    }

    /** @return array{ok: bool, cree: bool, id_customer: int, message: string} */
    private function echec(string $message): array
    {
        return ['ok' => false, 'cree' => false, 'id_customer' => 0, 'message' => $message];
    }
}
