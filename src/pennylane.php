<?php
/**
 * API Pennylane du cabinet (jeton « cabinet ») : liste des dossiers du cabinet et extraction des infos d'un dossier au moment
 * où on le choisit (exercices, comptes bancaires, balance de l'exercice clos).
 *
 * Deux jetons, gardés chiffrés dans data/pennylane.json : le principal et un jeton de lecture de secours (Pennylane exige
 * « fiscal_years:readonly » pour lire les exercices, qu'un jeton cabinet n'a pas toujours).
 * Routes : firm/v1/companies ; v2/fiscal_years, bank_accounts, ledger_accounts, trial_balance (pagination cursor / limit).
 * Réglage « pennylane » (MCA_PENNYLANE) : autre adresse (tests, faux Pennylane local).
 */
declare(strict_types=1);

define('PENNYLANE', rtrim(reglage('pennylane', 'https://app.pennylane.com/api/external'), '/'));
define('FIRM_V1', PENNYLANE . '/firm/v1');
define('PL_V2', PENNYLANE . '/v2');

class ErreurPennylane extends Exception
{
}

function jetons_pennylane(): array
{
    $p = lire_json(chemin_data('pennylane.json'), []);
    $lire = fn($k) => isset($p[$k]) ? (dechiffrer($p[$k], 'pennylane') ?? '') : '';
    return ['principal' => $lire('principal'), 'lecture' => $lire('lecture')];
}

function enregistrer_jetons_pennylane(?string $principal, ?string $lecture): void
{
    sous_verrou(function () use ($principal, $lecture) {
        $p = lire_json(chemin_data('pennylane.json'), []);
        foreach (['principal' => $principal, 'lecture' => $lecture] as $k => $v) {
            if ($v === null) {
                continue;  // champ laissé tel quel
            }
            $v = trim($v);
            if ($v === '') {
                unset($p[$k]);
            } else {
                $p[$k] = chiffrer($v, 'pennylane');
            }
        }
        ecrire_json(chemin_data('pennylane.json'), $p);
    });
}

class Pennylane
{
    private string $jeton;
    private string $secours;

    public function __construct(?string $jeton = null, ?string $secours = null, private float $pause = 0.25)
    {
        $j = jetons_pennylane();
        $this->jeton = $jeton ?? $j['principal'];
        $this->secours = $secours ?? $j['lecture'];
        if ($this->jeton === '') {
            throw new ErreurPennylane('Jeton API Pennylane absent : le coller dans Paramètres.');
        }
    }

    /** GET JSON ; X-Company-Id pour les routes v2. Réessaie sur 429 / 5xx, et avec le jeton de lecture si Pennylane refuse
     *  faute de droit (403 « requires scope »). */
    public function requete(string $methode, string $url, ?string $dossier = null, $corps = null, ?string $jeton = null)
    {
        if (MODE_DEMO) {  // démo hors ligne : aucune requête ne sort, quoi qu'il arrive
            throw new ErreurPennylane(DEMO_MESSAGE);
        }
        $jeton ??= $this->jeton;
        for ($essai = 0; $essai < 4; $essai++) {
            if ($this->pause > 0) {
                usleep((int)($this->pause * 1e6));  // limite de débit de l'API
            }
            $entetes = ['Authorization: Bearer ' . $jeton, 'Accept: application/json'];
            if ($dossier) {
                $entetes[] = 'X-Company-Id: ' . $dossier;
            }
            if ($corps !== null) {
                $entetes[] = 'Content-Type: application/json';
            }
            $c = curl_init($url);
            curl_setopt_array($c, [CURLOPT_CUSTOMREQUEST => $methode, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45,
                CURLOPT_HTTPHEADER => $entetes, CURLOPT_USERAGENT => 'MailComptesAnnuels/1.0', CURLOPT_HEADER => true]);
            if ($corps !== null) {
                curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($corps, JSON_UNESCAPED_UNICODE));
            }
            $r = curl_exec($c);
            $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
            $taille_entetes = (int)curl_getinfo($c, CURLINFO_HEADER_SIZE);
            $err = curl_error($c);
            curl_close($c);
            $relisible = $methode === 'GET';  // une écriture n'est jamais rejouée : Pennylane a pu l'enregistrer (doublon)
            if ($r === false) {
                if ($essai < 3 && $relisible) {
                    sleep(2 ** $essai);
                    continue;
                }
                throw new ErreurPennylane("API Pennylane injoignable ($err).");
            }
            $entete = substr($r, 0, $taille_entetes);
            $texte = substr($r, $taille_entetes);
            if (($code === 429 || ($relisible && in_array($code, [500, 502, 503, 504], true))) && $essai < 3) {
                $attente = preg_match('/retry-after:\s*(\d+)/i', $entete, $m) ? (int)$m[1] : 2 ** $essai;
                sleep(min(max($attente, 1), 20));
                continue;
            }
            if ($code === 401) {
                throw new ErreurPennylane('Jeton API Pennylane refusé (401) : vérifier le jeton dans Paramètres.');
            }
            if ($code === 403 && $this->secours !== '' && $jeton !== $this->secours && str_contains($texte, 'scope')) {
                return $this->requete($methode, $url, $dossier, $corps, $this->secours);
            }
            if ($code === 404) {
                return null;
            }
            if ($code >= 400) {
                $detail = json_decode($texte, true);
                $detail = is_array($detail) ? ($detail['error'] ?? $detail['message'] ?? json_encode($detail, JSON_UNESCAPED_UNICODE)) : mb_substr($texte, 0, 200);
                throw new ErreurPennylane("API Pennylane : erreur $code sur " . parse_url($url, PHP_URL_PATH) . ' — '
                    . (is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE)));
            }
            return $texte === '' ? [] : json_decode($texte, true);
        }
        throw new ErreurPennylane('API Pennylane saturée : réessayer dans un instant.');
    }

    public function get(string $url, ?string $dossier = null)
    {
        return $this->requete('GET', $url, $dossier);
    }

    /** Toutes les lignes d'une route v2 paginée par curseur. */
    public function liste(string $chemin, string $dossier, array $params = []): array
    {
        $lignes = [];
        $curseur = null;
        do {
            $q = $params + ['limit' => 100] + ($curseur ? ['cursor' => $curseur] : []);
            $r = $this->get(PL_V2 . "/$chemin?" . http_build_query($q), $dossier) ?? [];
            $lignes = array_merge($lignes, $r['items'] ?? []);
            $curseur = !empty($r['has_more']) ? ($r['next_cursor'] ?? null) : null;
        } while ($curseur);
        return $lignes;
    }

    /** Tous les dossiers du cabinet (firm/v1/companies, toutes les pages) : [{id, name, siren, …}]. */
    public function dossiers_du_cabinet(): array
    {
        $out = [];
        for ($page = 1; $page < 100; $page++) {
            $r = $this->get(FIRM_V1 . "/companies?page=$page&per_page=100") ?? [];
            $out = array_merge($out, $r['items'] ?? []);
            if ($page >= (int)($r['total_pages'] ?? 1)) {
                break;
            }
        }
        return $out;
    }
}

/** Client Pennylane : le vrai, ou en démo le Pennylane simulé (données fictives de src/demo.php, aucune requête réseau). */
function pennylane(float $pause = 0.25): Pennylane
{
    return MODE_DEMO ? new PennylaneDemo() : new Pennylane(null, null, $pause);
}

/** Dossiers du cabinet gardés 30 minutes (data/pennylane-dossiers.json) : la liste s'affiche tout de suite. */
function dossiers_cabinet(bool $frais = false): array
{
    $cache = lire_json(chemin_data('pennylane-dossiers.json'), []);
    if (!$frais && isset($cache['t'], $cache['dossiers']) && time() - $cache['t'] < 1800) {
        return $cache['dossiers'];
    }
    try {
        $liste = array_map(fn($c) => ['id' => (string)$c['id'], 'nom' => (string)($c['name'] ?? ''), 'siren' => (string)($c['siren'] ?? '')],
            pennylane(0)->dossiers_du_cabinet());
    } catch (ErreurPennylane $e) {
        if (isset($cache['dossiers'])) {
            return $cache['dossiers'];  // hors ligne : la dernière liste connue suffit
        }
        throw $e;
    }
    usort($liste, fn($a, $b) => strcasecmp($a['nom'], $b['nom']));
    ecrire_json(chemin_data('pennylane-dossiers.json'), ['t' => time(), 'dossiers' => $liste], false);
    return $liste;
}

// ---------- Extraction d'un dossier ----------

/** Dernier exercice terminé (fin <= aujourd'hui) parmi [{start, finish}], ou null. */
function exercice_termine(array $exercices, ?string $aujourdhui = null): ?array
{
    $aujourdhui ??= aujourdhui();
    $termines = array_values(array_filter($exercices, fn($e) => ($e['finish'] ?? '9999') <= $aujourdhui));
    usort($termines, fn($a, $b) => strcmp($b['finish'], $a['finish']));
    return $termines[0] ?? null;
}

/** Montant exact en centimes (entier) : les sommes ne perdent jamais un centime. */
function centimes($valeur): int
{
    return (int)round(((float)str_replace(',', '.', (string)($valeur ?? 0))) * 100);
}

/** Balance -> [résultat net, IS] en centimes. Résultat = crédits - débits des classes 6 et 7 ; IS = débits - crédits 695. */
function resultat_et_impot(array $balance): array
{
    $resultat = $impot = 0;
    foreach ($balance as $l) {
        $n = (string)($l['number'] ?? '');
        $solde = centimes($l['credits'] ?? 0) - centimes($l['debits'] ?? 0);
        if (in_array($n[0] ?? '', ['6', '7'], true)) {
            $resultat += $solde;
        }
        if (str_starts_with($n, '695')) {
            $impot -= $solde;
        }
    }
    return [$resultat, $impot];
}

/** [loyers 6132, indemnité d'occupation] en centimes ; l'indemnité n'est reconnue que par son libellé. */
function loyers_et_indemnite(array $balance): array
{
    $loyers = $occupation = 0;
    foreach ($balance as $l) {
        $n = (string)($l['number'] ?? '');
        $charge = centimes($l['debits'] ?? 0) - centimes($l['credits'] ?? 0);
        if (str_starts_with($n, '6132')) {
            $loyers += $charge;
        }
        if (($n[0] ?? '') === '6' && str_contains(mb_strtolower((string)($l['label'] ?? '')), 'occupation')) {
            $occupation += $charge;
        }
    }
    return [$loyers, $occupation];
}

function en_decimal(int $c): string
{
    return ($c < 0 ? '-' : '') . intdiv(abs($c), 100) . '.' . str_pad((string)(abs($c) % 100), 2, '0', STR_PAD_LEFT);
}

/** Infos d'un dossier pour le mail (valeurs en chaînes, même forme que les fiches de data/cabinet/dossiers/<id>.json). */
function extraire_dossier(Pennylane $pl, string $id, string $nom = '', ?string $aujourdhui = null): array
{
    $societe = $pl->get(FIRM_V1 . "/companies/$id") ?? [];
    $exercices = $pl->liste('fiscal_years', $id);
    $comptes = [];
    foreach ($pl->liste('bank_accounts', $id) as $c) {
        $libelle = (string)($c['name'] ?? '');
        $compte_512 = $c['ledger_account']['id'] ?? null;
        if ($compte_512) {  // « Banque Exemple - Compte courant » : nom de la banque et du compte
            $libelle = (string)(($pl->get(PL_V2 . "/ledger_accounts/$compte_512", $id) ?? [])['label'] ?? '') ?: $libelle;
        }
        [$banque, $compte] = array_pad(explode(' - ', $libelle, 2), 2, '');
        $compte = trim(preg_replace('/\s*-?\s*Account\s*-\s*[0-9A-Fa-f-]{20,}$/', '', $compte), ' -');  // identifiant technique
        $comptes[] = ['banque' => trim($banque), 'libelle' => $compte, 'iban' => '', 'solde' => (string)($c['balance'] ?? '')];
    }
    $resultat = [];
    $ex = exercice_termine($exercices, $aujourdhui);
    if ($ex) {
        $balance = $pl->liste('trial_balance', $id, ['period_start' => $ex['start'], 'period_end' => $ex['finish']]);
        [$net, $impot] = resultat_et_impot($balance);
        [$loyers, $occupation] = loyers_et_indemnite($balance);
        $resultat = ['debut' => $ex['start'], 'fin' => $ex['finish'], 'resultat_net' => en_decimal($net),
            'impot_societes' => en_decimal($impot), 'loyers_6132' => en_decimal($loyers), 'indemnite_occupation' => en_decimal($occupation)];
    }
    return [
        'pennylane_id' => $id, 'nom' => $nom ?: (string)($societe['name'] ?? ''), 'siren' => (string)($societe['siren'] ?? ''),
        'forme_juridique' => (string)($societe['legal_form'] ?? $societe['legal_form_code'] ?? ''),
        'categorie_fiscale' => (string)($societe['fiscal_category'] ?? ''), 'frequence_tva' => (string)($societe['vat_frequency'] ?? ''),
        'exercices' => array_map(fn($e) => ['debut' => (string)($e['start'] ?? ''), 'fin' => (string)($e['finish'] ?? ''),
            'statut' => (string)($e['status'] ?? '')], $exercices),
        'contact' => [], 'associes' => [], 'comptes' => $comptes, 'resultat' => $resultat, 'source' => 'api',
    ];
}
