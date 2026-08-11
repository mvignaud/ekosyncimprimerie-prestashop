<?php

/**
 * EKO Sync — Imprimerie
 *
 * @author    2M Numérique
 * @copyright 2026 2M Numérique
 * @license   MIT — voir le fichier LICENSE à la racine du dépôt
 */

/**
 * EKO Sync — Imprimerie
 *
 * Relie la boutique à l'ERP E-KO : catalogue, tarifs, documents, fichiers.
 *
 * PRINCIPE FONDATEUR : le tarif affiché sur le
 * site doit être RIGOUREUSEMENT celui d'un devis produit dans E-KO. Ce module
 * ne calcule donc aucun prix. Il demande, il met en cache la réponse, il
 * affiche. Toute tentative de recalculer localement — même « juste pour un
 * repli » — recrée deux vérités et brise l'égalité.
 *
 * Le partage des responsabilités qui en découle :
 *   PrestaShop  → la présentation : produits publiés, options montrées, ordre,
 *                 libellés, visuels, groupes de clients.
 *   E-KO        → les coûts, les barèmes, les clés de variables et leurs
 *                 valeurs autorisées.
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/src/Client/ClientEko.php';
require_once __DIR__ . '/src/Client/DepotEko.php';
require_once __DIR__ . '/src/Client/Groupes.php';
require_once __DIR__ . '/src/Client/ImportTiers.php';

use Eko\SyncImprimerie\Client\ClientEko;
use Eko\SyncImprimerie\Client\DepotEko;
use Eko\SyncImprimerie\Client\Groupes;
use Eko\SyncImprimerie\Client\ImportTiers;

class Ekosyncimprimerie extends Module
{
    public const CLE_BASE = 'EKOSYNC_BASE_URL';
    public const CLE_JETON = 'EKOSYNC_JETON';
    public const CLE_CACHE_S = 'EKOSYNC_CACHE_SECONDES';

    /**
     * Durée de cache par défaut, en secondes.
     *
     * Une CONSTANTE et non une valeur écrite à l'installation : sur cet
     * hébergement, `Module::install()` lève « Call to a member function get()
     * on null » dans Language.php — `SymfonyContainer::getInstance()` rend null
     * hors du back-office. Le module se retrouvait enregistré mais sans aucun
     * réglage, et l'échec passait inaperçu.
     *
     * Ne rien faire écrire à install() supprime le problème à la racine :
     * le module fonctionne qu'il ait été posé depuis le BO, un script ou la
     * ligne de commande.
     */
    public const CACHE_DEFAUT = 900;

    public function __construct()
    {
        $this->name = 'ekosyncimprimerie';
        $this->tab = 'front_office_features';
        $this->version = '0.2.0';
        $this->author = '2M Numérique';
        $this->need_instance = 0;
        // PrestaShop 9 impose PHP 8.1, que ce module exige (proprietes promues
        // en lecture seule). Annoncer PrestaShop 8, qui tourne encore sur PHP
        // 7.2, laisserait un marchand deposer le module puis perdre son
        // back-office sur une erreur fatale : les `require_once` sont evalues
        // avant que le moindre garde puisse s'executer.
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('EKO Sync — Imprimerie', [], 'Modules.Ekosyncimprimerie.Admin');
        $this->description = $this->trans(
            'Relie la boutique à l\'ERP E-KO : catalogue, tarifs, documents et fichiers clients. Les tarifs affichés sont ceux calculés par E-KO, sans recalcul local.',
            [],
            'Modules.Ekosyncimprimerie.Admin'
        );
        $this->confirmUninstall = $this->trans(
            'Désinstaller le module coupera la liaison avec E-KO. Les tarifs ne seront plus disponibles sur les fiches configurables.',
            [],
            'Modules.Ekosyncimprimerie.Admin'
        );
    }

    public function install(): bool
    {
        // Aucun hook enregistré à ce stade : le module ne rend encore rien côté
        // boutique. On les ajoutera quand ils serviront — un hook déclaré et
        // vide se paie à chaque page rendue, pour rien.
        // Aucune écriture ici : voir CACHE_DEFAUT. Les réglages n'existent
        // qu'une fois saisis, et leur absence est gérée à la lecture.
        return parent::install();
    }

    public function uninstall(): bool
    {
        // On retire les réglages : un jeton d'API qui survit à une
        // désinstallation est un secret qui traîne.
        //
        // Là non plus pas de && : `deleteByName` sur une clé absente rend
        // false, et la désinstallation échouerait pour la seule raison qu'un
        // réglage n'avait jamais été saisi.
        Configuration::deleteByName(self::CLE_BASE);
        Configuration::deleteByName(self::CLE_JETON);
        Configuration::deleteByName(self::CLE_CACHE_S);

        // Les identifiants de groupes : sans quoi « reinitialiser le module »
        // ne remet rien a zero et les gardes restent satisfaits par un id
        // perime, pointant vers un groupe qui n'existe peut-etre plus.
        foreach (Groupes::ROLES as $def) {
            Configuration::deleteByName($def['cle']);
        }

        ClientEko::viderCache();

        // La correspondance compte boutique -> tiers relie une personne
        // identifiable a un identifiant d'un systeme tiers. La laisser apres
        // desinstallation, c'est laisser une donnee personnelle qu'aucune
        // procedure de la boutique ne connait.
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'ekosync_tier`');
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'ekosync_address`');

        return parent::uninstall();
    }

    public function getContent(): string
    {
        $sortie = '';

        if (Tools::isSubmit('submitEkosync')) {
            $sortie .= $this->enregistrer();
        }

        if (Tools::isSubmit('testerEkosync')) {
            $sortie .= $this->tester();
        }

        if (Tools::isSubmit('sonderEkosync')) {
            $sortie .= $this->sonder();
        }

        if (Tools::isSubmit('groupesEkosync')) {
            $sortie .= $this->poserGroupes();
        }

        if (Tools::isSubmit('importerEkosync')) {
            $sortie .= $this->importerTiers();
        }

        return $sortie . $this->tableauGroupes() . $this->formulaire();
    }

    public function groupes(): Groupes
    {
        return new Groupes();
    }

    /** Crée ce qui manque, corrige ce qui a dérivé, et rend le compte rendu. */
    private function poserGroupes(): string
    {
        $lignes = [];

        $echec = false;

        foreach ($this->groupes()->assurer() as $r) {
            $echec = $echec || $r['id'] === 0;
            $lignes[] = sprintf(
                '%s → groupe #%d (%s) : %s',
                htmlspecialchars($r['nom']),
                $r['id'],
                $r['affichage'] === Groupes::AFFICHAGE_HT ? 'HT' : 'TTC',
                htmlspecialchars($r['action'])
            );
        }

        $texte = implode('<br>', $lignes);

        return $echec ? $this->displayError($texte) : $this->displayConfirmation($texte);
    }

    /**
     * L'état de la correspondance, visible sans avoir à cliquer.
     *
     * Un réglage qu'il faut déclencher pour connaître est un réglage dont on
     * ignore l'état le reste du temps.
     */
    private function tableauGroupes(): string
    {
        $correspondance = [
            Groupes::B2C => 'type_tiers = PARTICULIER',
            Groupes::B2B => 'type_tiers = ENTREPRISE ou ADMINISTRATION',
            Groupes::ASSOCIATION => 'type_tiers = ASSOCIATION',
            Groupes::REVENDEUR => 'statut_commercial = REVENDEUR (prime sur la nature fiscale)',
        ];

        $lignes = '';

        foreach (Groupes::ROLES as $role => $def) {
            $id = $this->groupes()->identifiant($role);

            $lignes .= sprintf(
                '<tr><td><strong>%s</strong></td><td>%s</td><td>%s</td><td>%s</td></tr>',
                htmlspecialchars($def['nom']),
                htmlspecialchars($correspondance[$role] ?? ''),
                $def['affichage'] === Groupes::AFFICHAGE_HT ? 'HT' : 'TTC',
                $id > 0
                    ? '#' . $id
                    : '<span class="text-danger">' . $this->trans('non posé', [], 'Modules.Ekosyncimprimerie.Admin') . '</span>'
            );
        }

        return '<div class="panel"><div class="panel-heading"><i class="icon-users"></i> '
            . $this->trans('Groupes de clients', [], 'Modules.Ekosyncimprimerie.Admin')
            . '</div><p>'
            . $this->trans(
                'Ces groupes ne portent AUCUNE remise. Le prix remisé vient d\'E-KO : une remise posée ici serait un second calcul, avec ses propres arrondis, et le site cesserait de coïncider au centime près avec un devis.',
                [],
                'Modules.Ekosyncimprimerie.Admin'
            )
            . '</p><table class="table"><thead><tr><th>'
            . $this->trans('Groupe', [], 'Modules.Ekosyncimprimerie.Admin') . '</th><th>'
            . $this->trans('Correspond à', [], 'Modules.Ekosyncimprimerie.Admin') . '</th><th>'
            . $this->trans('Affichage', [], 'Modules.Ekosyncimprimerie.Admin') . '</th><th>'
            . $this->trans('Identifiant boutique', [], 'Modules.Ekosyncimprimerie.Admin')
            . '</th></tr></thead><tbody>' . $lignes . '</tbody></table>'
            . '<form method="post"><button type="submit" name="groupesEkosync" class="btn btn-default">'
            . '<i class="icon-refresh"></i> '
            . $this->trans('Poser / vérifier les groupes', [], 'Modules.Ekosyncimprimerie.Admin')
            . '</button></form></div>';
    }

    private function enregistrer(): string
    {
        $base = trim((string) Tools::getValue(self::CLE_BASE));
        $jeton = trim((string) Tools::getValue(self::CLE_JETON));
        $cache = (int) Tools::getValue(self::CLE_CACHE_S);

        if ($base !== '' && !filter_var($base, FILTER_VALIDATE_URL)) {
            return $this->displayError($this->trans(
                'L\'adresse de l\'API n\'est pas une URL valide.',
                [],
                'Modules.Ekosyncimprimerie.Admin'
            ));
        }

        if ($base !== '' && !str_starts_with($base, 'https://')) {
            // Le jeton circule dans un en-tête : en clair, il est lisible par
            // tout intermédiaire. On refuse plutôt que d'avertir.
            return $this->displayError($this->trans(
                'L\'API doit être appelée en HTTPS : le jeton transiterait sinon en clair.',
                [],
                'Modules.Ekosyncimprimerie.Admin'
            ));
        }

        $ancienneBase = (string) Configuration::get(self::CLE_BASE);

        Configuration::updateValue(self::CLE_BASE, $base);
        Configuration::updateValue(self::CLE_CACHE_S, max(0, $cache));

        // Changer d'instance doit oublier ce qu'on savait de la precedente :
        // les reponses en cache portent l'identite de l'ancienne.
        if ($base !== $ancienneBase) {
            ClientEko::viderCache();
        }

        // Un champ jeton laissé vide ne doit PAS effacer le jeton enregistré :
        // le formulaire le réaffiche masqué, et l'enregistrer tel quel le
        // remplacerait par des astérisques.
        if ($jeton !== '' && !preg_match('/^\*+$/', $jeton)) {
            Configuration::updateValue(self::CLE_JETON, $jeton);
        }

        return $this->displayConfirmation($this->trans(
            'Réglages enregistrés.',
            [],
            'Modules.Ekosyncimprimerie.Admin'
        ));
    }

    private function tester(): string
    {
        $resultat = $this->client()->diagnostiquer();
        $texte = implode('<br>', array_map('htmlspecialchars', $resultat['messages']));

        return $resultat['ok'] ? $this->displayConfirmation($texte) : $this->displayError($texte);
    }

    /**
     * Interroge E-KO sur une adresse e-mail et rend ce qu'il en sait.
     *
     * Outil de contrôle, et futur outil de support : quand un client dira « je
     * ne vois pas ma facture », c'est ici qu'on saura s'il est rattaché, à quel
     * tiers, et ce que l'ERP porte à son nom.
     */
    private function sonder(): string
    {
        $email = trim((string) Tools::getValue('EKOSYNC_EMAIL_SONDE'));

        if ($email === '') {
            return $this->displayError($this->trans(
                'Renseignez une adresse e-mail à rechercher.',
                [],
                'Modules.Ekosyncimprimerie.Admin'
            ));
        }

        $depot = new DepotEko($this->client());
        $r = $depot->tierParEmail($email);

        if (!$r['trouve']) {
            $motif = $r['erreur'] !== ''
                ? $r['erreur']
                : sprintf('Aucun tiers E-KO pour « %s ».', $email);

            return $r['ambigu']
                ? $this->displayWarning(htmlspecialchars($motif))
                : $this->displayError(htmlspecialchars($motif));
        }

        $tier = $r['tier'];
        $id = (int) ($tier['id'] ?? 0);

        $lignes = [sprintf(
            'Tiers <strong>%s</strong> (id %d) trouvé.',
            htmlspecialchars((string) ($tier['name'] ?? '—')),
            $id
        )];

        $role = $this->groupes()->roleDe($tier);
        $lignes[] = sprintf(
            'Tarifs applicables : <strong>%s</strong>%s.',
            htmlspecialchars($role),
            $role === Groupes::REVENDEUR ? ' (le prix public reste affiché comme prix conseillé)' : ''
        );

        foreach ($depot->documentsDuTier($id) as $type => $bloc) {
            $lignes[] = $bloc['ok']
                ? sprintf('%s : <strong>%d</strong>', htmlspecialchars($type), $bloc['nombre'])
                : sprintf('%s : erreur — %s', htmlspecialchars($type), htmlspecialchars($bloc['erreur']));
        }

        // Le CODE, pas l'identifiant numérique : la route de l'API lie par
        // `code`. Passer l'`id` que la ressource expose pourtant rend un 404.
        $lignes[] = sprintf(
            '<form method="post" style="margin-top:8px">'
            . '<input type="hidden" name="EKOSYNC_TIER_CODE" value="%s">'
            . '<button type="submit" name="importerEkosync" class="btn btn-default">'
            . '<i class="icon-user"></i> %s</button></form>',
            htmlspecialchars((string) ($tier['code'] ?? '')),
            $this->trans('Créer le compte boutique', [], 'Modules.Ekosyncimprimerie.Admin')
        );

        return $this->displayConfirmation(implode('<br>', $lignes));
    }

    /**
     * Crée le compte boutique d'un tiers E-KO, à la demande.
     *
     * Sens ERP → PrestaShop. Aucun mot de passe n'est choisi ni transmis : le
     * client passe par « mot de passe oublié ». Voir ImportTiers.
     */
    private function importerTiers(): string
    {
        $code = trim((string) Tools::getValue('EKOSYNC_TIER_CODE'));

        if ($code === '') {
            return $this->displayError($this->trans(
                'Aucun tiers désigné.',
                [],
                'Modules.Ekosyncimprimerie.Admin'
            ));
        }

        if ($this->groupes()->identifiant(Groupes::B2B) === 0) {
            // Créer les comptes avant les groupes les rangerait tous dans le
            // groupe par défaut, et il faudrait les reprendre un par un.
            return $this->displayError($this->trans(
                'Posez d\'abord les groupes de clients : sans eux, le compte serait créé sans tarif applicable.',
                [],
                'Modules.Ekosyncimprimerie.Admin'
            ));
        }

        $client = $this->client();
        $import = new ImportTiers(new DepotEko($client), $client, $this->groupes());
        $r = $import->importer($code);

        return $r['ok']
            ? $this->displayConfirmation(htmlspecialchars($r['message']))
            : $this->displayError(htmlspecialchars($r['message']));
    }

    /** Durée de cache effective, défaut compris. */
    public function dureeCache(): int
    {
        $v = Configuration::get(self::CLE_CACHE_S);

        return ($v === false || $v === '') ? self::CACHE_DEFAUT : max(0, (int) $v);
    }

    public function client(): ClientEko
    {
        return new ClientEko(
            (string) Configuration::get(self::CLE_BASE),
            (string) Configuration::get(self::CLE_JETON),
            $this->dureeCache()
        );
    }

    private function formulaire(): string
    {
        $champs = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Liaison avec E-KO', [], 'Modules.Ekosyncimprimerie.Admin'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->trans('Adresse de l\'API', [], 'Modules.Ekosyncimprimerie.Admin'),
                        'name' => self::CLE_BASE,
                        'desc' => $this->trans(
                            'Racine du service, sans /api/v1. HTTPS obligatoire.',
                            [],
                            'Modules.Ekosyncimprimerie.Admin'
                        ),
                        'required' => true,
                    ],
                    [
                        'type' => 'password',
                        'label' => $this->trans('Jeton', [], 'Modules.Ekosyncimprimerie.Admin'),
                        'name' => self::CLE_JETON,
                        'desc' => $this->trans(
                            'Jeton dédié à ce module, aux portées minimales. Laisser vide pour conserver le jeton actuel.',
                            [],
                            'Modules.Ekosyncimprimerie.Admin'
                        ),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Durée du cache (secondes)', [], 'Modules.Ekosyncimprimerie.Admin'),
                        'name' => self::CLE_CACHE_S,
                        'desc' => $this->trans(
                            'Le cache ne conserve que des réponses d\'E-KO, jamais un calcul local. 0 désactive le cache.',
                            [],
                            'Modules.Ekosyncimprimerie.Admin'
                        ),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Rechercher un client', [], 'Modules.Ekosyncimprimerie.Admin'),
                        'name' => 'EKOSYNC_EMAIL_SONDE',
                        'desc' => $this->trans(
                            'Adresse e-mail d\'un client : affiche le tiers E-KO correspondant et le nombre de documents à son nom. Aucune écriture.',
                            [],
                            'Modules.Ekosyncimprimerie.Admin'
                        ),
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Enregistrer', [], 'Modules.Ekosyncimprimerie.Admin'),
                    'name' => 'submitEkosync',
                ],
                'buttons' => [
                    [
                        'type' => 'submit',
                        'title' => $this->trans('Tester la liaison', [], 'Modules.Ekosyncimprimerie.Admin'),
                        'icon' => 'process-icon-refresh',
                        'name' => 'testerEkosync',
                        'class' => 'btn btn-default pull-right',
                    ],
                    [
                        'type' => 'submit',
                        'title' => $this->trans('Rechercher', [], 'Modules.Ekosyncimprimerie.Admin'),
                        'icon' => 'process-icon-search',
                        'name' => 'sonderEkosync',
                        'class' => 'btn btn-default pull-right',
                    ],
                ],
            ],
        ];

        $aide = new HelperForm();
        $aide->module = $this;
        $aide->name_controller = $this->name;
        $aide->token = Tools::getAdminTokenLite('AdminModules');
        $aide->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $aide->submit_action = 'submitEkosync';

        // Le jeton n'est JAMAIS renvoyé au navigateur : on affiche un masque de
        // longueur fixe. Sans ça, il apparaît en clair dans le HTML de la page
        // de configuration, lisible par toute personne ayant accès au BO.
        $aide->fields_value = [
            self::CLE_BASE => Configuration::get(self::CLE_BASE),
            self::CLE_JETON => Configuration::get(self::CLE_JETON) ? '••••••••' : '',
            self::CLE_CACHE_S => $this->dureeCache(),
            'EKOSYNC_EMAIL_SONDE' => Tools::getValue('EKOSYNC_EMAIL_SONDE', ''),
        ];

        return $aide->generateForm([$champs]);
    }
}
