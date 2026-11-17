<?php
/**
 * Mode démo (variable DEMO=1, lancé par demo.bat / demo.sh) : l'outil tourne hors ligne avec des données FICTIVES.
 *
 *   - data-demo/ est rempli au premier lancement : compte « demo » / « demo », fiches du cabinet, réglages du mail ;
 *   - Pennylane est simulé ici même (PennylaneDemo) : mêmes réponses que l'API (exercices, comptes bancaires, balance), lues
 *     par le vrai code d'extraction (src/pennylane.php) et les vraies règles du mail (src/bilan.php) ;
 *   - Microsoft (connexion, Outlook) et les réglages Pennylane ne sont jamais appelés : les routes concernées répondent par un
 *     message clair, le brouillon Outlook est simulé, et les fonctions http() / requete() refusent toute sortie réseau.
 * Toutes les sociétés, personnes, adresses et numéros ci-dessous sont inventés (SIREN commençant par 000, jamais attribué).
 */
declare(strict_types=1);

const DEMO_IDENTIFIANT = 'demo';
const DEMO_NOM = 'Camille Lefèvre';
const DEMO_MESSAGE = 'Mode démo : cette fonction appelle Microsoft 365 ou Pennylane, elle est désactivée ici (aucun compte externe). '
    . 'Le code d\'intégration complet est dans src/outlook.php et src/pennylane.php.';

/** SIREN fictif mais valide (clé de Luhn) : préfixe 000, jamais attribué par l'INSEE. */
function demo_siren(int $n): string
{
    $base = sprintf('000%05d', $n);
    for ($cle = 0; $cle < 10; $cle++) {
        $s = $base . $cle;
        $somme = 0;
        foreach (str_split($s) as $i => $c) {
            $v = (int)$c * ($i % 2 ? 2 : 1);
            $somme += $v > 9 ? $v - 9 : $v;
        }
        if ($somme % 10 === 0) {
            return $s;
        }
    }
    return '';
}

/** Dossiers fictifs, chacun pour un cas des règles du mail : [id => société, exercices, comptes bancaires, balance]. */
function demo_pennylane(): array
{
    $a = (int)date('Y');
    $civils = fn() => [['start' => ($a - 2) . '-01-01', 'finish' => ($a - 2) . '-12-31', 'status' => 'closed'],
        ['start' => ($a - 1) . '-01-01', 'finish' => ($a - 1) . '-12-31', 'status' => 'closed'],
        ['start' => "$a-01-01", 'finish' => "$a-12-31", 'status' => 'open']];
    $decales = [['start' => ($a - 2) . '-07-01', 'finish' => ($a - 1) . '-06-30', 'status' => 'closed'],
        ['start' => ($a - 1) . '-07-01', 'finish' => "$a-06-30", 'status' => 'closed'],
        ['start' => "$a-07-01", 'finish' => ($a + 1) . '-06-30', 'status' => 'open']];
    // Ligne de balance : compte, libellé, débits, crédits
    $l = fn(string $n, string $lib, float $d, float $c = 0) => ['number' => $n, 'label' => $lib, 'debits' => number_format($d, 2, '.', ''),
        'credits' => number_format($c, 2, '.', '')];
    $d = fn(int $id, string $nom, string $forme, string $cat, string $tva, array $comptes, array $balance, ?array $ex = null) => [
        'id' => $id, 'name' => $nom, 'siren' => demo_siren($id), 'legal_form' => $forme, 'fiscal_category' => $cat, 'vat_frequency' => $tva,
        'exercices' => $ex ?? $civils(), 'comptes' => $comptes, 'balance' => $balance];
    return [
        // Société à l'IS, bénéfice, IS à payer plus élevé que la trésorerie : le mail demande d'alimenter le compte
        9101 => $d(9101, 'ATELIER DUPAIN', 'SAS', 'bic_is', 'monthly',
            [['name' => 'Compte courant', 'balance' => '3210.40', 'ledger_account' => ['id' => 51201]], ['name' => 'Banque Exemple - Livret', 'balance' => '0']],
            [$l('706000', 'Prestations de services', 0, 182450), $l('607000', 'Achats de marchandises', 61200), $l('641100', 'Salaires', 48300),
                $l('613200', 'Locations immobilières', 14400), $l('695000', 'Impôt sur les bénéfices', 9104)]),
        // IS couvert par la trésorerie, TVA trimestrielle
        9102 => $d(9102, 'VERGER DES TROIS POMMES', 'SARL', 'bic_is', 'quarterly',
            [['name' => 'Crédit Fictif - Compte pro', 'balance' => '48900.00']],
            [$l('701000', 'Ventes de produits finis', 0, 96300), $l('601000', 'Achats stockés', 41200), $l('641100', 'Salaires', 39800),
                $l('695000', 'Impôt sur les bénéfices', 2350)]),
        // Perte et créance d'IS (acomptes trop versés), TVA annuelle
        9103 => $d(9103, 'STUDIO PIXEL FICTIF', 'SASU', 'bic_is', 'yearly',
            [['name' => 'Banque Exemple - Compte courant', 'balance' => '12045.10']],
            [$l('706000', 'Prestations de services', 0, 95000), $l('622600', 'Honoraires', 30000), $l('641100', 'Salaires', 70000),
                $l('695000', 'Impôt sur les bénéfices', 0, 1200)]),
        // Indemnité d'occupation versée au dirigeant (reconnue par son libellé) : montant à déclarer, case 4BE ; IS néant
        9104 => $d(9104, 'LES SAVEURS IMAGINAIRES', 'SAS', 'bic_is', 'monthly',
            [['name' => 'Banque Exemple - Compte courant', 'balance' => '8730.00']],
            [$l('707000', 'Ventes de marchandises', 0, 128400), $l('607000', 'Achats de marchandises', 70100), $l('641100', 'Salaires', 52280.38),
                $l('613200', 'Indemnité d\'occupation du siège', 4789.62), $l('695000', 'Impôt sur les bénéfices', 0)]),
        // Société civile à l'IR : ni déclaration d'IS ni dépôt des comptes au greffe
        9105 => $d(9105, 'SCI DU PUITS FICTIF', 'Société civile immobilière', 'ir', 'not_applicable',
            [['name' => 'Crédit Fictif - Compte SCI', 'balance' => '15620.00']],
            [$l('706000', 'Loyers', 0, 18000), $l('615000', 'Entretien et réparations', 2000), $l('661100', 'Intérêts des emprunts', 3100)]),
        // Exercice décalé (1er juillet - 30 juin) : « exercice 2025-2026 » dans le mail
        9106 => $d(9106, 'TRANSPORTS FILANTS', 'SARL', 'bic_is', 'monthly',
            [['name' => 'Banque Exemple - Compte courant', 'balance' => '27000.00'], ['name' => 'Crédit Fictif - Compte pro', 'balance' => '1500.00']],
            [$l('706000', 'Transports', 0, 210000), $l('606100', 'Carburant', 64000), $l('641100', 'Salaires', 118000),
                $l('695000', 'Impôt sur les bénéfices', 5600)], $decales),
        // Entrepreneur individuel à l'IR (BIC), TVA annuelle : pas d'IS, pas de greffe
        9107 => $d(9107, 'MARC FARINE EI', 'Entrepreneur individuel', 'bic_ir', 'yearly',
            [['name' => 'Banque Exemple - Compte pro', 'balance' => '6400.00']],
            [$l('707000', 'Ventes', 0, 61000), $l('601000', 'Achats', 22000), $l('625100', 'Déplacements', 3200)]),
        // Pas de fiche du cabinet : contact à saisir (la rédaction est bloquée tant qu'il manque)
        9108 => $d(9108, 'NOVA CONSEIL EXEMPLE', 'SAS', 'bic_is', 'quarterly',
            [['name' => 'Banque Exemple - Compte courant', 'balance' => '31800.00']],
            [$l('706000', 'Conseil', 0, 74000), $l('641100', 'Salaires', 51000), $l('695000', 'Impôt sur les bénéfices', 3450)]),
        // Loyer commercial ordinaire (6132) : montant proposé, case laissée décochée
        9109 => $d(9109, 'BOULANGERIE DU MOULIN BLEU', 'EURL', 'bic_is', 'monthly',
            [['name' => 'Banque Exemple - Compte courant', 'balance' => '14210.55']],
            [$l('707000', 'Ventes', 0, 152000), $l('601000', 'Farines et matières', 58000), $l('641100', 'Salaires', 61000),
                $l('613200', 'Loyer commercial', 9600), $l('695000', 'Impôt sur les bénéfices', 3480)]),
    ];
}

/** Pennylane simulé : mêmes routes et même forme de réponse que l'API (voir Pennylane::requete), sans aucune requête réseau. */
final class PennylaneDemo extends Pennylane
{
    public function __construct()
    {
    }

    public function requete(string $methode, string $url, ?string $dossier = null, $corps = null, ?string $jeton = null)
    {
        $chemin = substr((string)parse_url($url, PHP_URL_PATH), strlen((string)parse_url(PENNYLANE, PHP_URL_PATH)));
        $donnees = demo_pennylane();
        $societe = fn(array $s) => array_intersect_key($s, array_flip(['id', 'name', 'siren', 'legal_form', 'fiscal_category', 'vat_frequency']));
        if ($chemin === '/firm/v1/companies') {
            return ['items' => array_values(array_map($societe, $donnees)), 'total_pages' => 1];
        }
        if (preg_match('#^/firm/v1/companies/(\d+)$#', $chemin, $m)) {
            return isset($donnees[(int)$m[1]]) ? $societe($donnees[(int)$m[1]]) : null;
        }
        $d = $donnees[(int)$dossier] ?? null;
        if (!$d) {
            return null;
        }
        if (preg_match('#^/v2/ledger_accounts/(\d+)$#', $chemin)) {
            return ['label' => 'Banque Exemple - Compte courant'];
        }
        $items = match ($chemin) {
            '/v2/fiscal_years' => $d['exercices'],
            '/v2/bank_accounts' => $d['comptes'],
            '/v2/trial_balance' => $d['balance'],
            default => null,
        };
        return $items === null ? null : ['items' => $items, 'has_more' => false];
    }
}

/** Supprime le contenu d'un dossier de données de démo (jamais un autre dossier). */
function demo_vider(string $dossier): void
{
    if (!is_dir($dossier) || !str_contains(basename($dossier), 'demo')) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dossier, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
}

/** Remplit data-demo/ au premier lancement (ou à nouveau avec $forcer). Ne fait rien hors du mode démo. */
function demo_initialiser(bool $forcer = false): bool
{
    if (!MODE_DEMO || (!$forcer && is_file(chemin_data('comptes.json')))) {
        return false;
    }
    if ($forcer) {
        demo_vider(DATA);
    }
    sous_verrou(function () {
        ecrire_json(chemin_data('comptes.json'), [DEMO_IDENTIFIANT => ['nom' => DEMO_NOM, 'hash' => password_hash('demo', PASSWORD_DEFAULT)]]);
        cle_secrete();
        ecrire_json(chemin_data('parametres.json'), ['ModeConnexion' => '']);
        ecrire_json(chemin_data('bilan', 'parametres.json'), ['Copie' => 'secretariat@example.fr']);

        // Fiches du cabinet : contact du client, associés (civilité), « Tu » ou « Vous ». Tous inventés.
        $f = fn(int $id, string $prenom, string $nom, string $email, string $civ, string $formule, array $associes = []) => [
            'pennylane_id' => (string)$id, 'nom' => demo_pennylane()[$id]['name'], 'formule' => $formule,
            'contact' => ['prenom' => $prenom, 'nom' => $nom, 'email' => $email, 'civilite' => $civ], 'associes' => $associes];
        $associe = fn(string $prenom, string $nom, string $genre) => ['prenom' => $prenom, 'nom' => $nom, 'genre' => $genre];
        ecrire_json(chemin_data('cabinet', 'liste.json'), [
            $f(9101, 'Paul', 'Dupain', 'paul.dupain@example.fr', '', 'Tu', [$associe('Paul', 'DUPAIN', 'mister'), $associe('Julie', 'DUPAIN', 'madam')]),
            $f(9102, 'Lucie', 'Pommier', 'lucie.pommier@example.com', 'Mme', 'Vous'),
            $f(9103, 'Hugo', 'Pixel', 'hugo@example.com', 'M', 'Tu'),
            $f(9104, 'Inès', 'Gourmet', 'ines.gourmet@example.fr', 'Mme', 'Vous', [$associe('Inès', 'GOURMET', 'madam')]),
            $f(9105, 'Louis', 'Brique', 'louis.brique@example.com', 'M', 'Vous'),
            $f(9106, 'Nadia', 'Roulier', 'nadia@example.fr', 'Mme', 'Tu'),
            $f(9107, 'Marc', 'Farine', 'marc.farine@example.com', 'M', 'Tu'),
            $f(9109, 'Yves', 'Levain', 'yves.levain@example.fr', 'M', 'Vous'),
        ]);
        ecrire_json(chemin_data('bilan', 'envois.json'), ['9102' => date('d/m/Y', strtotime('-6 days'))]);
    });
    return true;
}
