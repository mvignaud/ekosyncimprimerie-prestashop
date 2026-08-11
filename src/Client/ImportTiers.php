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

            return [
                'ok' => true,
                'cree' => false,
                'id_customer' => $existant,
                'message' => sprintf(
                    'Un compte existait déjà pour %s : il a été rattaché au tiers %d.%s',
                    $email,
                    $tierId,
                    $deplace === '' ? '' : ' ' . $deplace
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
        $client->vat_number = (string) ($tier['vat_intracom'] ?? '');
        $client->website = (string) ($tier['website'] ?? '');
        $client->active = (bool) ($tier['is_active'] ?? true);
        $client->newsletter = false;
        $client->optin = false;

        // Le SIRET n'accepte qu'un vrai SIRET (14 chiffres, clé de Luhn). E-KO
        // n'expose aujourd'hui que le SIREN (9 chiffres) : l'y écrire donnerait
        // un numéro faux dans un champ qu'un comptable lit comme un SIRET.
        // Mieux vaut le laisser vide que le remplir de travers.
        $siret = preg_replace('/\D/', '', (string) ($tier['siret'] ?? '')) ?? '';
        $client->siret = \Validate::isSiret($siret) ? $siret : '';

        // Mode B2B : `id_risk` vaut 0 par défaut sur l'objet, or aucun risque ne
        // porte ce numéro — la fiche du back-office joint dessus. On pose le
        // premier risque réel (« Aucun »), pas un identifiant qui ne résout pas.
        $client->id_risk = (int) (\Db::getInstance()->getValue(
            'SELECT MIN(`id_risk`) FROM `' . _DB_PREFIX_ . 'risk`'
        ) ?: 1);

        // Encours et délai de paiement restent à zéro : E-KO les porte
        // (`encours_limit`, conditions de règlement) mais ne les expose pas
        // encore par l'API. Zéro = aucun crédit accordé, le défaut prudent.
        $client->outstanding_allow_amount = 0;
        $client->max_payment_days = 0;

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

        return [
            'ok' => true,
            'cree' => true,
            'id_customer' => (int) $client->id,
            'message' => sprintf(
                'Compte créé pour %s (%s), groupe %s. Le client définira son mot de passe par « mot de passe oublié ».',
                $email,
                $client->company !== '' ? $client->company : $client->lastname,
                $this->groupes->roleDe($tier)
            ),
        ];
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
