<?php
/**
 * Connexion avec le compte Microsoft du cabinet (Microsoft Entra ID, Microsoft Authenticator) et journal de sécurité.
 *
 * Connexion : OpenID Connect, code d'autorisation + PKCE + nonce, retour sur l'application elle-même. Le jeton d'identité est
 * vérifié (signature Microsoft, application, organisation, dates, nonce : outlook.php) et le compte Microsoft (oid + tenant)
 * doit être lié au compte (lié tout seul à la première connexion : compte_a_lier). La même connexion ouvre la boîte Outlook.
 * Le retour de Microsoft arrive d'un autre site : le cookie de session (SameSite=Strict) n'y est pas envoyé, la connexion en
 * cours est donc rattachée au navigateur par un cookie à part (SameSite=Lax, 15 minutes, usage unique).
 *
 * Mode de connexion : en ligne, Microsoft avec double authentification par défaut (dès que l'application Microsoft est
 * déclarée) ; en local ou sans application Microsoft, mot de passe.
 *
 * Journal (data/journal-securite.jsonl) : connexions, refus, changements de sécurité, avec l'adresse IP et le navigateur.
 * Gardé 12 mois puis effacé (CNIL : journaux de sécurité de 6 mois à 1 an).
 */
declare(strict_types=1);

const JOURNAL_MOIS = 12;
const FLUX_DUREE = 900;
const MODES_CONNEXION = ['' => 'Microsoft ou mot de passe', 'microsoft' => 'Microsoft seulement',
    'microsoft_mfa' => 'Microsoft avec double authentification seulement'];
define('MESSAGES_MICROSOFT', [
    'refuse' => 'Connexion annulée sur la page Microsoft.',
    'inconnu' => 'Ce compte Microsoft n\'a pas de compte ici. Utilisez votre adresse du cabinet (@' . DOMAINE_CABINET . ').',
    'mfa' => 'Double authentification exigée : validez la connexion dans Microsoft Authenticator. Si Microsoft ne vous la demande pas, un administrateur doit l\'activer pour le cabinet (Microsoft Entra).',
    'expire' => 'Connexion trop ancienne, ou commencée dans un autre navigateur : recommencez.',
    'indisponible' => 'La connexion Microsoft n\'est pas activée pour cette adresse (application Microsoft Entra à déclarer, HTTPS et adresse de retour).',
    'personnel' => 'Utilisez votre compte Microsoft du cabinet, pas un compte personnel.',
    'deja' => 'Ce compte Microsoft est déjà lié à un autre compte.',
    'organisation' => 'Ce compte Microsoft n\'appartient pas à l\'organisation du cabinet.',
    'consentement' => 'Le cabinet doit d\'abord autoriser l\'application Microsoft (un administrateur, une seule fois).',
    'mot_de_passe' => 'La connexion se fait avec votre compte Microsoft.',
    'erreur' => 'Microsoft n\'a pas pu confirmer la connexion : réessayez.',
]);

// ---------- Journal de sécurité ----------
/** Navigateur et système en clair (« Edge · Windows »), sans garder toute la chaîne du navigateur. */
function navigateur_court(string $ua): string
{
    $nav = match (true) {
        str_contains($ua, 'Edg/') => 'Edge', str_contains($ua, 'OPR/') => 'Opera', str_contains($ua, 'Firefox/') => 'Firefox',
        str_contains($ua, 'Chrome/') => 'Chrome', str_contains($ua, 'Safari/') => 'Safari', default => 'Navigateur',
    };
    $os = match (true) {
        str_contains($ua, 'Windows') => 'Windows', str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
        str_contains($ua, 'Android') => 'Android', str_contains($ua, 'Mac OS') => 'macOS', str_contains($ua, 'Linux') => 'Linux', default => '',
    };
    return $os ? "$nav · $os" : $nav;
}

function journal_securite(string $evenement, ?string $compte, array $plus = []): void
{
    $ligne = ['t' => date('c'), 'evt' => $evenement, 'compte' => $compte, 'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'nav' => navigateur_court($_SERVER['HTTP_USER_AGENT'] ?? '')] + $plus;
    $chemin = chemin_data('journal-securite.jsonl');
    if (!is_dir(DATA)) {
        mkdir(DATA, 0770, true);
    }
    file_put_contents($chemin, json_encode($ligne, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    if (random_int(1, 200) === 1) {
        journal_purger();
    }
}

/** Efface les lignes de plus de 12 mois (minimisation des données personnelles). */
function journal_purger(): void
{
    sous_verrou(function () {
        $limite = date('c', strtotime('-' . JOURNAL_MOIS . ' months'));
        $garder = array_filter(journal_lignes(), fn($l) => ($l['t'] ?? '') >= $limite);
        ecrire_fichier(chemin_data('journal-securite.jsonl'), implode('', array_map(
            fn($l) => json_encode($l, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", $garder)));
    });
}

function journal_lignes(): array
{
    $chemin = chemin_data('journal-securite.jsonl');
    if (!is_file($chemin)) {
        return [];
    }
    return array_values(array_filter(array_map(fn($l) => json_decode($l, true), file($chemin, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []), 'is_array'));
}

/** Les derniers événements d'un compte, du plus récent au plus ancien. */
function journal_du_compte(string $compte, int $n = 20): array
{
    $limite = date('c', strtotime('-' . JOURNAL_MOIS . ' months'));
    $l = array_filter(journal_lignes(), fn($x) => ($x['compte'] ?? null) === $compte && ($x['t'] ?? '') >= $limite);
    return array_slice(array_reverse(array_values($l)), 0, $n);
}

// ---------- Réglages ----------
/** Mode choisi dans Paramètres › Sécurité ; sans choix : double authentification exigée en ligne (si l'application Microsoft
 *  est déclarée), mot de passe en local ou sur le réseau du cabinet (http : Microsoft exige HTTPS ou localhost). */
function mode_connexion(): string
{
    $p = lire_json(chemin_data('parametres.json'), []);
    if (!array_key_exists('ModeConnexion', $p)) {
        return en_local() || (PHP_SAPI === 'cli-server' && !en_https()) || !connexion_microsoft_disponible() ? '' : 'microsoft_mfa';
    }
    $m = (string)$p['ModeConnexion'];
    return array_key_exists($m, MODES_CONNEXION) ? $m : 'microsoft_mfa';  // réglage illisible : le plus sûr
}

/** L'adresse du site est-elle déclarée dans l'application Microsoft (URI de redirection) ? Case des Réglages avancés de la
 *  messagerie ; sans réglage : oui en HTTPS (le site en ligne, déclaré à la mise en ligne). */
function retour_declare(): bool
{
    $p = lire_json(chemin_data('parametres.json'), []);
    return array_key_exists('MessagerieRetour', $p) ? $p['MessagerieRetour'] === '1' : en_https();
}

/** Adresse où Microsoft renvoie après la connexion : l'application elle-même si elle est sur ce PC (localhost, toujours
 *  accepté par Microsoft) ou si son adresse est déclarée dans l'application Microsoft ; sinon http://localhost (à coller). */
function adresse_retour_microsoft(): string
{
    $hote = strtolower((string)parse_url(origine(), PHP_URL_HOST));
    $port = parse_url(origine(), PHP_URL_PORT);
    if ($hote === 'localhost' || $hote === '127.0.0.1' || $hote === '[::1]') {
        return 'http://localhost' . ($port ? ":$port" : '');
    }
    return retour_declare() ? origine() : RETOUR_MICROSOFT;
}

/** Connexion Microsoft possible ici : application Microsoft déclarée, retour automatique sur le site (sinon il faudrait
 *  coller une adresse, sans être connecté), en HTTPS ou sur ce PC. Jamais en démo. */
function connexion_microsoft_disponible(): bool
{
    if (MODE_DEMO || outlook_client_id() === '') {
        return false;
    }
    $r = adresse_retour_microsoft();
    return $r !== RETOUR_MICROSOFT && (str_starts_with($r, 'https://') || str_starts_with($r, 'http://localhost'));
}

// ---------- Connexion Microsoft en cours ----------
function nom_cookie_flux(): string
{
    return en_https() ? '__Host-mail_comptes_flux' : 'mail_comptes_flux';
}

function chemin_flux(string $etat): string
{
    return chemin_data('connexions-microsoft', $etat . '.json');
}

/** Ouvre la page Microsoft. $but : « connexion » ou « lier » (compte Microsoft de $identifiant, déjà connecté).
 *  $defi : « claims » renvoyé par Microsoft quand il exige la double authentification (AADSTS50076) : la page la fait alors faire. */
function microsoft_flux_demarrer(string $but, ?string $identifiant = null, ?string $defi = null): string
{
    foreach (glob(chemin_data('connexions-microsoft', '*.json')) ?: [] as $f) {  // ménage : connexions abandonnées
        if (filemtime($f) < time() - 3600) {
            @unlink($f);
        }
    }
    $etat = bin2hex(random_bytes(16));
    $lien = bin2hex(random_bytes(32));
    $tid = tenant_cabinet();
    $autorite = $tid ? str_replace('/common/', '/' . rawurlencode($tid) . '/', AUTORITE) : str_replace('/common/', '/organizations/', AUTORITE);
    $retour = adresse_retour_microsoft();
    [$url, $verificateur, $nonce] = microsoft_autorisation(outlook_client_id(), $retour, $etat,
        $but === 'lier' ? 'select_account' : null, $autorite, $defi);
    // Ni max_age=0 (code refusé ensuite : invalid_grant) ni amr_values (refusé par Microsoft : AADSTS901002).
    ecrire_json(chemin_flux($etat), ['but' => $but, 'identifiant' => $identifiant, 'defi' => $defi !== null,
        'verificateur' => $verificateur, 'nonce' => $nonce,
        'retour' => $retour, 'autorite' => $autorite, 'lien' => hash('sha256', $lien), 'expire' => time() + FLUX_DUREE], false);
    setcookie(nom_cookie_flux(), $lien, ['expires' => time() + FLUX_DUREE, 'path' => '/', 'secure' => en_https(), 'httponly' => true, 'samesite' => 'Lax']);
    return $url;
}

/** Une connexion Microsoft attend-elle ce retour ? (Sinon c'est le retour de « Connecter ma boîte ».) */
function microsoft_flux_attendu(string $etat): bool
{
    return preg_match('/^[0-9a-f]{32}$/', $etat) === 1 && is_file(chemin_flux($etat));
}

/** Retour de Microsoft : vérifie tout et retourne [flux, jetons, infos du jeton d'identité, double authentification].
 *  Usage unique : la connexion en cours est effacée dès sa lecture. Lève ErreurOutlook avec une clé de MESSAGES_MICROSOFT. */
function microsoft_flux_terminer(array $q): array
{
    $etat = (string)($q['state'] ?? '');
    $flux = lire_json(chemin_flux($etat), null);
    @unlink(chemin_flux($etat));
    setcookie(nom_cookie_flux(), '', ['expires' => 1, 'path' => '/', 'secure' => en_https(), 'httponly' => true, 'samesite' => 'Lax']);
    $lien = (string)($_COOKIE[nom_cookie_flux()] ?? '');
    if (!$flux || $flux['expire'] < time() || $lien === '' || !hash_equals($flux['lien'], hash('sha256', $lien))) {
        throw new ErreurOutlook('expire');
    }
    if (isset($q['error'])) {
        $texte = ($q['error_description'] ?? '') . ' ' . $q['error'];
        throw new ErreurOutlook(stripos($texte, 'access_denied') !== false ? 'refuse'
            : (str_contains($texte, 'AADSTS65001') || stripos($texte, 'consent') !== false ? 'consentement' : 'erreur'));
    }
    $client_id = outlook_client_id();
    try {
        $r = microsoft_jetons(['grant_type' => 'authorization_code', 'code' => (string)($q['code'] ?? ''), 'redirect_uri' => $flux['retour'],
            'code_verifier' => $flux['verificateur']], $client_id, $flux['autorite'], str_starts_with($flux['retour'], 'https://'));
        $infos = verifier_id_token((string)($r['id_token'] ?? ''), $client_id, $flux['nonce']);
    } catch (ErreurOutlook $e) {
        error_log('Mail comptes annuels, connexion Microsoft : ' . $e->getMessage());
        if (($e->action['type'] ?? '') === 'mfa') {  // Microsoft n'a pas fait valider Authenticator : on recommence avec son défi, une fois
            $defi = (string)($e->action['claims'] ?? '');
            empty($flux['defi']) && $defi !== '' ? redirection(microsoft_flux_demarrer($flux['but'], $flux['identifiant'], $defi))
                : throw new ErreurOutlook('mfa');
        }
        throw new ErreurOutlook(($e->action['type'] ?? '') === 'consentement' ? 'consentement' : 'erreur');
    }
    return [$flux, $r, $infos, double_authentification($infos, (string)$r['access_token'])];
}

/** Petite page qui renvoie sur l'application. Navigation lancée par le site lui-même : le cookie de session (Strict) suit. */
function page_redirection(string $vers): never
{
    $v = htmlspecialchars($vers, ENT_QUOTES);
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    echo "<!doctype html><html lang=\"fr\"><meta charset=\"utf-8\"><meta http-equiv=\"refresh\" content=\"0;url=$v\"><title>Comptes annuels</title>"
        . "<p style=\"font-family:sans-serif\"><a href=\"$v\">Continuer</a></p></html>";
    exit;
}

function redirection(string $url): never
{
    header('Location: ' . $url, true, 302);
    header('Cache-Control: no-store');
    exit;
}
