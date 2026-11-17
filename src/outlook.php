<?php
/**
 * Boîte Outlook de chaque utilisateur (Microsoft Graph). Chacun connecte SA boîte : le mail des comptes annuels y est déposé
 * en brouillon (avec sa signature), prêt à être relu, complété des pièces jointes et envoyé depuis Outlook.
 *
 * Connexion par la page Microsoft (code d'autorisation + PKCE, client public). Retour sur l'application elle-même quand c'est
 * possible (localhost, ou adresse du site déclarée dans l'application Microsoft Entra du cabinet) : la connexion se termine
 * seule ; sinon retour sur http://localhost et la personne colle l'adresse de la page de retour. Jetons chiffrés
 * (AES-256-GCM) dans data/outlook/<identifiant>.json, renouvelés automatiquement. OAuth 2.0 écrit à la main, sans MSAL.
 */
declare(strict_types=1);

define('GRAPH', reglage('graph', 'https://graph.microsoft.com/v1.0'));                                // remplaçables pour les tests
define('AUTORITE', reglage('autorite', 'https://login.microsoftonline.com/common/oauth2/v2.0'));     // (faux Microsoft local)
define('EMETTEUR', reglage('emetteur', 'https://login.microsoftonline.com'));  // « iss » des jetons d'identité : EMETTEUR/<tenant>/v2.0
const PORTEES = 'offline_access openid profile User.Read Mail.ReadWrite';  // brouillons et lecture de la signature des mails envoyés
define('CLIENT_ID_DEFAUT', reglage('microsoft_client_id'));  // application Microsoft Entra du cabinet (config.json ou MCA_MICROSOFT_CLIENT_ID)
const RETOUR_MICROSOFT = 'http://localhost';
const TENANT_PERSONNEL = '9188040d-6c67-4c5b-b112-36a304b66dad';  // tenant public de Microsoft pour les comptes personnels (outlook.com…)
const LIEN_CONSENTEMENT = 'https://login.microsoftonline.com/organizations/v2.0/adminconsent?client_id=';

final class ErreurOutlook extends Exception
{
    public function __construct(string $message, public ?array $action = null) { parent::__construct($message); }
}

/** Erreur Microsoft -> [message clair, action] : la mise en place doit pouvoir se faire sans aide. */
function expliquer_microsoft(string $t, string $client_id): array
{
    if (str_contains($t, 'AADSTS65001') || str_contains($t, 'AADSTS90094') || stripos($t, 'consent') !== false) {
        return ['Le cabinet doit autoriser une fois l\'application Microsoft (approbation administrateur).',
            ['type' => 'consentement', 'lien' => lien_consentement($client_id),
                'etapes' => ['Un administrateur Microsoft du cabinet ouvre le lien et accepte.', 'Ensuite, chacun peut connecter sa boîte.']]];
    }
    if (str_contains($t, 'AADSTS700016')) {
        return ['L\'identifiant d\'application Microsoft est inconnu.', ['type' => 'client_id']];
    }
    if (str_contains($t, 'AADSTS700025')) {
        return ['Un secret Microsoft est enregistré, mais l\'adresse du site est encore sous « Applications mobiles et de bureau » : déplacez-la sous « Web » (Microsoft Entra › application › Authentification).', null];
    }
    if (str_contains($t, 'AADSTS7000218')) {
        return ['L\'adresse du site est sous « Web » dans Microsoft Entra : enregistrez le secret de l\'application (Paramètres › Messagerie Outlook › Réglages avancés).', null];
    }
    if (str_contains($t, 'AADSTS7000215') || str_contains($t, 'AADSTS7000222')) {
        return ['Secret de l\'application Microsoft invalide ou expiré : créez-en un nouveau dans Microsoft Entra et enregistrez-le ici.', null];
    }
    if (str_contains($t, 'AADSTS50076') || str_contains($t, 'AADSTS50079')) {  // le cabinet exige la double authentification
        return ['Double authentification exigée par le cabinet : validez dans Microsoft Authenticator.', ['type' => 'mfa']];
    }
    if (str_contains($t, 'invalid_grant') || str_contains($t, 'AADSTS70008') || str_contains($t, 'AADSTS54005')) {
        return ['Connexion trop ancienne ou déjà utilisée : recommencez « Connecter ma boîte ».', null];
    }
    if (stripos($t, 'access_denied') !== false || str_contains($t, 'AADSTS65004')) {
        return ['Connexion refusée sur la page Microsoft.', null];
    }
    return [mb_substr(strtok($t, "\n") ?: 'Erreur Microsoft.', 0, 300), null];
}

/** Approbation administrateur, une fois pour tout le cabinet, des autorisations demandées. */
function lien_consentement(string $client_id): string
{
    $portees = implode(' ', array_map(fn($p) => in_array($p, ['offline_access', 'openid', 'profile'], true) ? $p : "https://graph.microsoft.com/$p",
        explode(' ', PORTEES)));
    return LIEN_CONSENTEMENT . rawurlencode($client_id) . '&' . http_build_query(['scope' => $portees, 'redirect_uri' => RETOUR_MICROSOFT], '', '&', PHP_QUERY_RFC3986);
}

/** Secret de l'application Microsoft (adresse du site déclarée en plateforme « Web ») : le serveur prouve à Microsoft que c'est
 *  bien lui qui échange le code ; Microsoft fait alors valider Authenticator dans la page de connexion. Gardé chiffré. */
function microsoft_secret(): string
{
    $p = lire_json(chemin_data('parametres.json'), []);
    return isset($p['MessagerieSecret']) ? (string)(dechiffrer((string)$p['MessagerieSecret'], 'microsoft-secret') ?? '') : '';
}

function outlook_client_id(): string
{
    $p = lire_json(chemin_data('parametres.json'), []);
    return trim((string)($p['MessagerieClientId'] ?? '')) ?: CLIENT_ID_DEFAUT;
}

/** Requête HTTP brute : [code, corps, en-têtes]. */
function http(string $methode, string $url, array $entetes = [], $corps = null, int $delai = 60): array
{
    if (MODE_DEMO) {  // démo hors ligne : aucune requête ne sort, quoi qu'il arrive
        throw new ErreurOutlook(DEMO_MESSAGE);
    }
    $c = curl_init($url);
    $recus = [];
    curl_setopt_array($c, [CURLOPT_CUSTOMREQUEST => $methode, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $delai,
        CURLOPT_HTTPHEADER => $entetes, CURLOPT_USERAGENT => 'MailComptesAnnuels/1.0',
        CURLOPT_HEADERFUNCTION => function ($c, $ligne) use (&$recus) {
            if (str_contains($ligne, ':')) {
                [$k, $v] = explode(':', $ligne, 2);
                $recus[strtolower(trim($k))] = trim($v);
            }
            return strlen($ligne);
        }]);
    if ($corps !== null) {
        curl_setopt($c, CURLOPT_POSTFIELDS, $corps);
    }
    $r = curl_exec($c);
    $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
    $err = curl_error($c);
    curl_close($c);
    if ($r === false) {
        throw new ErreurOutlook("Microsoft injoignable ($err).");
    }
    return [$code, (string)$r, $recus];
}

/** base64url -> octets (jetons Microsoft). */
function b64url(string $t): string
{
    return (string)base64_decode(strtr($t, '-_', '+/') . str_repeat('=', (4 - strlen($t) % 4) % 4));
}

/** Clé publique RSA (module n, exposant e d'une clé Microsoft) au format PEM, lisible par openssl. */
function cle_rsa_pem(string $n, string $e): string
{
    $longueur = function (int $l): string {
        $o = ltrim(pack('N', $l), "\0");
        return $l < 128 ? chr($l) : chr(0x80 | strlen($o)) . $o;
    };
    $der = fn(int $balise, string $v) => chr($balise) . $longueur(strlen($v)) . $v;
    $entier = fn(string $o) => $der(0x02, (ord($o[0] ?? "\0") > 0x7f ? "\0" : '') . $o);
    $cle = $der(0x30, $der(0x30, hex2bin('06092a864886f70d0101010500')) . $der(0x03, "\0" . $der(0x30, $entier(b64url($n)) . $entier(b64url($e)))));
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($cle), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** Clés publiques de Microsoft (signature des jetons d'identité), gardées 24 h ; relues si une clé inconnue apparaît. */
function microsoft_cles(bool $relire = false): array
{
    $chemin = chemin_data('microsoft-cles.json');
    $cache = lire_json($chemin, []);
    if (!$relire && ($cache['lu'] ?? 0) > time() - 86400 && !empty($cache['keys'])) {
        return $cache['keys'];
    }
    [$code, $corps] = http('GET', preg_replace('#/oauth2/v2\.0$#', '', AUTORITE) . '/discovery/v2.0/keys', [], null, 15);
    $cles = json_decode($corps, true)['keys'] ?? null;
    if ($code !== 200 || !is_array($cles)) {
        throw new ErreurOutlook('Microsoft injoignable (clés de signature) : réessayez dans un instant.');
    }
    ecrire_json($chemin, ['lu' => time(), 'keys' => $cles], false);
    return $cles;
}

/** Jeton d'identité Microsoft vérifié : signé (RS256) par une clé Microsoft, émis pour l'application par l'organisation du
 *  compte, encore valable, et pour CETTE connexion (nonce). Retourne ses informations ; sinon la connexion est refusée. */
function verifier_id_token(string $jwt, string $client_id, ?string $nonce): array
{
    $p = explode('.', $jwt);
    $entete = count($p) === 3 ? (json_decode(b64url($p[0]), true) ?: []) : [];
    $infos = count($p) === 3 ? (json_decode(b64url($p[1]), true) ?: []) : [];
    if (($entete['alg'] ?? '') !== 'RS256' || empty($entete['kid'])) {
        throw new ErreurOutlook('Réponse de Microsoft non signée : connexion refusée.');
    }
    $trouver = fn(array $cles) => array_values(array_filter($cles, fn($k) => ($k['kid'] ?? '') === $entete['kid'] && ($k['kty'] ?? '') === 'RSA'))[0] ?? null;
    $cle = $trouver(microsoft_cles()) ?? $trouver(microsoft_cles(true));
    if (!$cle || openssl_verify("$p[0].$p[1]", b64url($p[2]), cle_rsa_pem((string)$cle['n'], (string)$cle['e']), OPENSSL_ALGO_SHA256) !== 1) {
        throw new ErreurOutlook('Signature Microsoft invalide : connexion refusée.');
    }
    $tid = (string)($infos['tid'] ?? '');
    $t = time();
    if (($infos['aud'] ?? '') !== $client_id || $tid === '' || ($infos['iss'] ?? '') !== EMETTEUR . "/$tid/v2.0"
        || (int)($infos['exp'] ?? 0) < $t - 300 || (int)($infos['nbf'] ?? $infos['iat'] ?? 0) > $t + 300
        || ($nonce !== null && !hash_equals($nonce, (string)($infos['nonce'] ?? '')))) {
        throw new ErreurOutlook('Jeton Microsoft non valable pour cette application : connexion refusée.');
    }
    return $infos;
}

/** Double authentification faite chez Microsoft (Authenticator, clé FIDO, Windows Hello) ? null si Microsoft ne le dit pas.
 *  Lue dans le jeton d'identité, sinon dans le jeton d'accès (reçu directement de Microsoft, en HTTPS). */
function double_authentification(array $infos, string $acces): ?bool
{
    $amr = $infos['amr'] ?? null;
    if (!is_array($amr)) {
        $p = explode('.', $acces);
        $amr = count($p) === 3 ? (json_decode(b64url($p[1]), true)['amr'] ?? null) : null;
    }
    return is_array($amr) ? (bool)array_intersect(['mfa', 'ngcmfa', 'fido'], $amr) : null;
}

/** Code d'autorisation (ou jeton de renouvellement) -> jetons Microsoft.
 *  $confidentiel : retour sur une adresse HTTPS (plateforme « Web ») : le secret de l'application accompagne la demande. */
function microsoft_jetons(array $champs, string $client_id, string $autorite = AUTORITE, bool $confidentiel = false): array
{
    $secret = $confidentiel ? microsoft_secret() : '';
    if ($secret !== '') {
        $champs['client_secret'] = $secret;
    }
    [$code, $corps] = http('POST', $autorite . '/token', ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query($champs + ['client_id' => $client_id, 'scope' => PORTEES]));
    $r = json_decode($corps, true) ?: [];
    if ($code >= 400 || empty($r['access_token'])) {
        error_log('Mail comptes annuels, réponse Microsoft : ' . mb_substr((string)($r['error_description'] ?? $corps), 0, 300));
        [$m, $a] = expliquer_microsoft(($r['error_description'] ?? '') . ' ' . ($r['error'] ?? $corps), $client_id);
        if (($a['type'] ?? '') === 'mfa' && is_string($r['claims'] ?? null)) {
            $a['claims'] = $r['claims'];  // défi de Microsoft, à renvoyer à la page de connexion
        }
        throw new ErreurOutlook($m, $a);
    }
    return $r + ['confidentiel' => $secret !== ''];
}

/** Page d'autorisation Microsoft (code + PKCE + nonce). Retourne [url, vérificateur, nonce]. */
function microsoft_autorisation(string $client_id, string $retour, string $etat, ?string $invite = 'select_account', string $autorite = AUTORITE,
    ?string $defi = null): array
{
    $verificateur = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $nonce = bin2hex(random_bytes(16));
    $url = $autorite . '/authorize?' . http_build_query(array_filter(['client_id' => $client_id, 'response_type' => 'code',
        'redirect_uri' => $retour, 'response_mode' => 'query', 'scope' => PORTEES, 'state' => $etat, 'nonce' => $nonce,
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verificateur, true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256', 'prompt' => $invite, 'claims' => $defi], fn($v) => $v !== null));
    return [$url, $verificateur, $nonce];
}

final class Boite
{
    private ?array $jetons = null;

    public function __construct(public string $identifiant, public string $client_id = CLIENT_ID_DEFAUT)
    {
        $paquet = lire_json($this->chemin(), null);
        if (is_array($paquet) && isset($paquet['chiffre'])) {
            $clair = dechiffrer($paquet['chiffre'], 'outlook');
            $this->jetons = $clair ? json_decode($clair, true) : null;
        }
    }

    private function chemin(string $suffixe = ''): string
    {
        return chemin_data('outlook', $this->identifiant . $suffixe . '.json');
    }

    public function adresse(): string
    {
        return strtolower((string)($this->jetons['adresse'] ?? ''));
    }

    /** Compte Microsoft personnel (outlook.com, hotmail…) : jamais lié à un compte du cabinet. */
    public function personnel(): bool
    {
        $domaine = substr(strrchr($this->adresse(), '@') ?: '', 1);
        return ($this->jetons['tenant'] ?? '') === TENANT_PERSONNEL
            || in_array($domaine, ['outlook.com', 'outlook.fr', 'hotmail.com', 'hotmail.fr', 'live.com', 'live.fr', 'msn.com', 'gmail.com'], true);
    }

    private function sauver(): void
    {
        ecrire_json($this->chemin(), ['chiffre' => chiffrer(json_encode($this->jetons), 'outlook')]);
    }

    public function deconnecter(): void
    {
        @unlink($this->chemin());
        @unlink($this->chemin('.flux'));
        $this->jetons = null;
    }

    // --- Connexion : code d'autorisation + PKCE ---
    public function demarrer(string $retour = RETOUR_MICROSOFT): array
    {
        $etat = bin2hex(random_bytes(16));
        [$url, $verificateur, $nonce] = microsoft_autorisation($this->client_id, $retour, $etat);
        ecrire_json($this->chemin('.flux'), ['etat' => $etat, 'verificateur' => $verificateur, 'nonce' => $nonce, 'expire' => time() + 900,
            'statut' => 'attente', 'retour' => $retour]);
        return ['url' => $url, 'etat' => $etat];
    }

    /** L'application Microsoft existe-t-elle ? (identifiant inconnu = seule erreur de mise en place possible ici). */
    public function verifier_application(): void
    {
        if ($this->client_id === '') {
            throw new ErreurOutlook("Aucune application Microsoft déclarée.", ['type' => 'client_id',
                'etapes' => ['Créez une application dans Microsoft Entra (Inscriptions d\'applications), autorisations Graph : User.Read et Mail.ReadWrite.',
                    'Collez son identifiant (ID d\'application) dans « Réglages avancés » ci-dessous, ou dans config.json (microsoft_client_id).']]);
        }
        [, $corps] = http('POST', AUTORITE . '/devicecode', ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['client_id' => $this->client_id, 'scope' => PORTEES]), 15);
        if (str_contains($corps, 'AADSTS700016') || str_contains($corps, 'AADSTS900023')) {
            throw new ErreurOutlook("L'identifiant d'application Microsoft est inconnu.", ['type' => 'client_id',
                'etapes' => ["Vérifiez l'identifiant dans « Réglages avancés » ci-dessous (application du cabinet dans Microsoft Entra).",
                    "Laissez la case vide pour reprendre celui de config.json."]]);
        }
    }

    public function flux(): ?array
    {
        return lire_json($this->chemin('.flux'), null);
    }

    /** Termine la connexion à partir de l'adresse de retour Microsoft (…/?code=…&state=…). */
    public function terminer(string $adresse_retour): string
    {
        parse_str((string)parse_url(trim($adresse_retour), PHP_URL_QUERY), $q);
        $flux = $this->flux();
        if (!$flux || ($q['state'] ?? '') !== $flux['etat'] || $flux['expire'] < time() || ($flux['statut'] ?? '') !== 'attente') {
            throw new ErreurOutlook('Adresse non reconnue : recommencez « Connecter ma boîte » et collez l\'adresse de la page de retour.');
        }
        if (isset($q['error'])) {
            [$m, $a] = expliquer_microsoft(($q['error_description'] ?? '') . ' ' . $q['error'], $this->client_id);
            ecrire_json($this->chemin('.flux'), ['statut' => 'erreur', 'message' => $m, 'action' => $a] + $flux);
            throw new ErreurOutlook($m, $a);
        }
        try {
            $r = microsoft_jetons(['grant_type' => 'authorization_code', 'code' => (string)($q['code'] ?? ''),
                'redirect_uri' => $flux['retour'] ?? RETOUR_MICROSOFT, 'code_verifier' => $flux['verificateur']], $this->client_id, AUTORITE,
                str_starts_with($flux['retour'] ?? '', 'https://'));
            $this->adopter($r, verifier_id_token((string)($r['id_token'] ?? ''), $this->client_id, (string)($flux['nonce'] ?? '')));
        } catch (ErreurOutlook $e) {  // affiché aussi dans la fenêtre « Connecter ma boîte » restée ouverte
            ecrire_json($this->chemin('.flux'), ['statut' => 'erreur', 'message' => $e->getMessage(), 'action' => $e->action] + $flux);
            throw $e;
        }
        $flux['statut'] = 'connecte';
        ecrire_json($this->chemin('.flux'), $flux);
        return $this->adresse();
    }

    /** Jetons d'une connexion Microsoft vérifiée ($infos : jeton d'identité vérifié) : gardés, chiffrés. Sert aussi à la
     *  connexion à l'application avec Microsoft : la boîte est connectée du même coup. */
    public function adopter(array $r, array $infos): void
    {
        $this->jetons = ['acces' => $r['access_token'], 'renouvellement' => $r['refresh_token'] ?? '',
            'expire' => time() + (int)($r['expires_in'] ?? 3600) - 120,
            'adresse' => strtolower((string)($infos['preferred_username'] ?? $infos['email'] ?? '')), 'tenant' => (string)$infos['tid'],
            'oid' => (string)($infos['oid'] ?? ''), 'confidentiel' => !empty($r['confidentiel'])];
        $this->sauver();
    }

    /** Compte Microsoft de la boîte : oid, tenant, adresse. */
    public function identite(): array
    {
        return ['oid' => (string)($this->jetons['oid'] ?? ''), 'tid' => (string)($this->jetons['tenant'] ?? ''), 'adresse' => $this->adresse()];
    }

    private function renouveler(): void
    {
        $r = microsoft_jetons(['grant_type' => 'refresh_token', 'refresh_token' => $this->jetons['renouvellement']], $this->client_id, AUTORITE,
            !empty($this->jetons['confidentiel']));  // jetons obtenus avec le secret : renouvelés avec lui
        $this->jetons = ['acces' => $r['access_token'], 'renouvellement' => $r['refresh_token'] ?? $this->jetons['renouvellement'],
            'expire' => time() + (int)($r['expires_in'] ?? 3600) - 120] + $this->jetons;
        $this->sauver();
    }

    private function jeton(): string
    {
        if (!$this->jetons) {
            throw new ErreurOutlook('Boîte Outlook non connectée.', ['type' => 'reconnecter']);
        }
        if ($this->jetons['expire'] < time()) {
            $this->renouveler_ou_expliquer();
        }
        return $this->jetons['acces'];
    }

    /** Renouvelle le jeton ; « reconnectez votre boîte » seulement si Microsoft refuse vraiment (jeton révoqué, secret…),
     *  pas pour une coupure réseau ou une saturation passagère (la boîte reste connectée, on réessaie plus tard). */
    private function renouveler_ou_expliquer(): void
    {
        try {
            $this->renouveler();
        } catch (ErreurOutlook $e) {
            $m = $e->getMessage();
            $definitif = str_starts_with($m, 'Connexion trop ancienne') || stripos($m, 'secret') !== false || stripos($m, 'Entra') !== false
                || stripos($m, 'autoriser') !== false || stripos($m, "identifiant d'application") !== false || stripos($m, 'Double authentification') !== false;
            if ($definitif) {
                throw new ErreurOutlook('Connexion Outlook expirée : reconnectez votre boîte (Paramètres › Messagerie Outlook).', ['type' => 'reconnecter']);
            }
            throw new ErreurOutlook('Microsoft ne répond pas pour le moment : réessayez dans un instant.');
        }
    }

    // --- Appels Graph ---
    public function appel(string $methode, string $url, array $options = [])
    {
        $url = str_starts_with($url, 'http') ? $url : GRAPH . $url;
        if (!empty($options['params'])) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($options['params'], '', '&', PHP_QUERY_RFC3986);
        }
        $renouvele = false;
        for ($essai = 0; $essai < 4; $essai++) {
            $entetes = ['Authorization: Bearer ' . $this->jeton()];
            $corps = null;
            if (array_key_exists('json', $options)) {
                $entetes[] = 'Content-Type: application/json';
                $corps = json_encode($options['json'], JSON_UNESCAPED_UNICODE);
            } elseif ($methode === 'POST') {
                $entetes[] = 'Content-Length: 0';
                $corps = '';
            }
            [$code, $r, $h] = http($methode, $url, $entetes, $corps, 120);
            // Limitation Microsoft : on attend et on réessaie. Une écriture (POST) n'est rejouée que sur 429 (jamais exécutée) :
            // sur 503/504 Microsoft a pu l'exécuter, la rejouer ferait un doublon.
            if (($code === 429 || ($methode === 'GET' && in_array($code, [503, 504], true))) && $essai < 3) {
                sleep(min((int)($h['retry-after'] ?? 3) ?: 3, 10));
                continue;
            }
            if ($code === 401 && !$renouvele && !empty($this->jetons['renouvellement'])) {  // jeton refusé avant son expiration
                $renouvele = true;                                                          // (révoqué, mot de passe changé) : renouvelé une fois
                $this->renouveler_ou_expliquer();
                $essai--;
                continue;
            }
            if ($code === 404) {
                return null;
            }
            if ($code >= 400) {
                $detail = json_decode($r, true)['error']['message'] ?? mb_substr($r, 0, 200);
                throw new ErreurOutlook("Outlook a refusé la demande ($code) : $detail");
            }
            return $r === '' ? [] : (json_decode($r, true) ?? []);
        }
        throw new ErreurOutlook('Outlook est saturé : réessayez dans un instant.');
    }

    /** Brouillon dans la boîte (dossier Brouillons), signature de la personne ajoutée : [id, lien pour l'ouvrir dans Outlook].
     *  Rien n'est envoyé. Les images intégrées (logo de la signature, data:) deviennent des pièces jointes « en ligne » (cid:) :
     *  elles s'affichent chez tous les destinataires. */
    public function brouillon(array $a, string $objet, string $html, array $cc = []): array
    {
        $corps = avec_signature($html, $this->identifiant);
        $en_ligne = [];
        $corps = preg_replace_callback('#src="data:(image/(?:png|jpe?g|gif));base64,([A-Za-z0-9+/=\s]+)"#i', function ($m) use (&$en_ligne) {
            $cid = 'image-' . count($en_ligne) . '-' . bin2hex(random_bytes(4));
            $en_ligne[] = [$cid, strtolower($m[1]), preg_replace('/\s+/', '', $m[2])];
            return "src=\"cid:$cid\"";
        }, $corps) ?? $corps;
        $propres = fn(array $l) => array_values(array_filter(array_map(fn($x) => trim((string)$x), $l), fn($x) => $x !== ''));
        $b = $this->appel('POST', '/me/messages', ['json' => ['subject' => $objet, 'body' => ['contentType' => 'HTML', 'content' => $corps],
            'toRecipients' => array_map(fn($x) => ['emailAddress' => ['address' => $x]], $propres($a)),
            'ccRecipients' => array_map(fn($x) => ['emailAddress' => ['address' => $x]], $propres($cc))]]);
        try {
            foreach ($en_ligne as $i => [$cid, $type, $donnees]) {
                $this->appel('POST', '/me/messages/' . rawurlencode($b['id']) . '/attachments', ['json' => [
                    '@odata.type' => '#microsoft.graph.fileAttachment', 'name' => "image$i." . (str_contains($type, 'png') ? 'png' : (str_contains($type, 'gif') ? 'gif' : 'jpg')),
                    'contentType' => $type, 'contentBytes' => $donnees, 'isInline' => true, 'contentId' => $cid]]);
            }
        } catch (ErreurOutlook $e) {  // image refusée : le brouillon incomplet est retiré (sinon un brouillon de plus à chaque essai)
            try {
                $this->appel('DELETE', '/me/messages/' . rawurlencode($b['id']));
            } catch (ErreurOutlook) {
            }
            throw $e;
        }
        return ['id' => $b['id'] ?? '', 'lien' => $b['webLink'] ?? ''];
    }

    /** Signature Outlook de la personne, reprise de ses derniers mails envoyés (Microsoft ne la donne pas autrement) : HTML avec
     *  le logo intégré, ou null. Outlook la marque : bloc « Signature » (Outlook web, nouvel Outlook) ou signet « _MailAutoSig »
     *  (Outlook classique). On s'arrête avant le message cité d'une réponse. */
    public function signature_outlook(): ?string
    {
        $r = $this->appel('GET', '/me/mailFolders/sentitems/messages', ['params' => ['$select' => 'id,body,hasAttachments', '$top' => '15',
            '$orderby' => 'sentDateTime desc']]);
        foreach ($r['value'] ?? [] as $m) {
            $sig = extraire_signature((string)($m['body']['content'] ?? ''));
            if ($sig === null) {
                continue;
            }
            if (str_contains($sig, 'cid:')) {  // logo : image jointe au mail, remise dans la signature
                $pj = $this->appel('GET', '/me/messages/' . rawurlencode($m['id']) . '/attachments', []) ?? [];
                foreach ($pj['value'] ?? [] as $x) {
                    if (!empty($x['contentId']) && !empty($x['contentBytes']) && str_starts_with((string)($x['contentType'] ?? ''), 'image/')
                        && strlen($x['contentBytes']) < 700000) {
                        $sig = str_replace('cid:' . $x['contentId'], 'data:' . $x['contentType'] . ';base64,' . $x['contentBytes'], $sig);
                    }
                }
            }
            $propre = nettoyer_html($sig);
            if (trim(strip_tags($propre, '<img>')) !== '') {
                return $propre;
            }
        }
        return null;
    }
}

/** Signature des mails de la personne : data/signatures/<identifiant>.html, logo intégré (data:). null : aucune. */
function signature_enregistree(string $identifiant): ?string
{
    $chemin = chemin_data('signatures', $identifiant . '.html');
    return is_file($chemin) ? (string)file_get_contents($chemin) : null;
}

/** Signature mise en bas des mails : celle enregistrée ; sinon une signature simple (nom, cabinet, adresse de la boîte). */
function signature_html(string $identifiant, string $nom = '', string $adresse = ''): string
{
    $html = signature_enregistree($identifiant);
    if ($html !== null) {
        return $html;
    }
    $nom = $nom !== '' ? $nom : (comptes_lire()[$identifiant]['nom'] ?? $identifiant);
    return '<p><b>' . htmlspecialchars($nom) . '</b><br>' . htmlspecialchars(cabinet_nom()) . ' · Expertise comptable'
        . ($adresse !== '' ? '<br><a href="mailto:' . htmlspecialchars($adresse) . '">' . htmlspecialchars($adresse) . '</a>' : '') . '</p>';
}

/** Mail préparé + signature de la personne. */
function avec_signature(string $html, string $identifiant): string
{
    $b = new Boite($identifiant);
    return $html . '<br><div style="font-family:Calibri,Arial,sans-serif;font-size:11pt">' . signature_html($identifiant, '', $b->adresse()) . '</div>';
}

/** HTML de l'éditeur (ou collé depuis Word / Outlook), réduit à la mise en forme d'un mail : gras, italique, souligné,
 *  couleur, taille, listes, liens. Tout le reste (scripts, styles Word, attributs) disparaît ; le texte reste. */
function nettoyer_html(string $html): string
{
    $garder = ['p', 'div', 'br', 'b', 'strong', 'i', 'em', 'u', 's', 'strike', 'span', 'font', 'ul', 'ol', 'li', 'a', 'blockquote', 'h1', 'h2', 'h3', 'sub', 'sup',
        'table', 'tbody', 'thead', 'tr', 'td', 'th', 'img', 'hr'];  // tableaux et logo : la mise en page des signatures Outlook
    $jeter = ['script', 'style', 'iframe', 'object', 'embed', 'head', 'title', 'meta', 'link', 'svg', 'math', 'form', 'input', 'button', 'textarea', 'select', 'video', 'audio'];
    $styles = ['color', 'background-color', 'font-size', 'font-weight', 'font-style', 'text-decoration', 'text-align', 'font-family', 'width', 'height',
        'vertical-align', 'line-height', 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'border', 'border-top', 'border-right',
        'border-bottom', 'border-left', 'border-collapse', 'white-space'];
    $d = new DOMDocument();
    @$d->loadHTML('<?xml encoding="utf-8"?><div id="racine">' . $html . '</div>', LIBXML_NONET | LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED);
    $racine = $d->getElementById('racine') ?? $d->documentElement;
    $nettoyer = function (DOMNode $n) use (&$nettoyer, $garder, $jeter, $styles, $d): void {
        foreach (iterator_to_array($n->childNodes) as $c) {
            if ($c instanceof DOMComment || $c instanceof DOMProcessingInstruction) {
                $n->removeChild($c);
                continue;
            }
            if (!$c instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($c->localName ?? $c->nodeName);
            if (in_array($tag, $jeter, true)) {
                $n->removeChild($c);
                continue;
            }
            $nettoyer($c);
            if (!in_array($tag, $garder, true)) {  // balise inconnue (o:p de Word…) : on garde son contenu
                while ($c->firstChild) {
                    $n->insertBefore($c->firstChild, $c);
                }
                $n->removeChild($c);
                continue;
            }
            $attrs = [];
            if ($tag === 'a' && preg_match('#^(https?://|mailto:|tel:)#i', trim($c->getAttribute('href')))) {
                $attrs['href'] = trim($c->getAttribute('href'));
            }
            if ($tag === 'img') {  // logo : image intégrée (reprise de la signature Outlook) ou hébergée en https ; rien d'autre
                $src = trim($c->getAttribute('src'));
                if (!preg_match('#^(data:image/(png|jpe?g|gif);base64,[A-Za-z0-9+/=\s]+|https://\S+)$#i', $src)) {
                    $n->removeChild($c);
                    continue;
                }
                $attrs['src'] = $src;
                foreach (['width', 'height', 'alt'] as $k) {
                    if (preg_match('/^[\w %.\-]{1,60}$/u', $c->getAttribute($k))) {
                        $attrs[$k] = $c->getAttribute($k);
                    }
                }
            }
            if (in_array($tag, ['td', 'th'], true)) {
                foreach (['colspan', 'rowspan', 'valign', 'align', 'width'] as $k) {
                    if (preg_match('/^[\w%]{1,10}$/', $c->getAttribute($k))) {
                        $attrs[$k] = $c->getAttribute($k);
                    }
                }
            }
            if ($tag === 'table') {
                foreach (['cellpadding', 'cellspacing', 'border', 'width'] as $k) {
                    if (preg_match('/^[\w%]{1,10}$/', $c->getAttribute($k))) {
                        $attrs[$k] = $c->getAttribute($k);
                    }
                }
            }
            if ($tag === 'font') {
                foreach (['color' => '/^#?[0-9a-z]{3,20}$/i', 'size' => '/^[1-7]$/', 'face' => '/^[\w ,\-"\']{1,80}$/u'] as $k => $motif) {
                    if (preg_match($motif, $c->getAttribute($k))) {
                        $attrs[$k] = $c->getAttribute($k);
                    }
                }
            }
            $propres = [];
            foreach (explode(';', $c->getAttribute('style')) as $decl) {
                [$k, $v] = array_pad(array_map('trim', explode(':', $decl, 2)), 2, '');
                if (in_array(strtolower($k), $styles, true) && $v !== '' && !preg_match('/url\(|expression|javascript|[<>]/i', $v)) {
                    $propres[] = strtolower($k) . ':' . $v;
                }
            }
            if (in_array($tag, ['p', 'div'], true)) {  // même espacement qu'à l'écran : pas de marge ajoutée par la messagerie du client
                array_unshift($propres, 'margin:0');
            }
            if ($propres) {
                $attrs['style'] = implode(';', $propres);
            }
            while ($c->attributes->length) {
                $c->removeAttributeNode($c->attributes->item(0));
            }
            foreach ($attrs as $k => $v) {
                $c->setAttribute($k, $v);
            }
        }
    };
    $nettoyer($racine);
    $out = '';
    foreach ($racine->childNodes as $c) {
        $out .= $d->saveHTML($c);
    }
    return trim($out);
}

/** Signature par défaut de chaque compte d'Outlook classique (registre Windows) : [adresse => nom de la signature].
 *  null si le registre n'est pas lisible (serveur hors Windows, ou tests). */
function signatures_par_defaut_outlook(): ?array
{
    if (PHP_OS_FAMILY !== 'Windows' || reglage('sans_registre') === '1' || !function_exists('shell_exec')) {
        return null;
    }
    $reg = '"' . (getenv('SystemRoot') ?: 'C:\Windows') . '\System32\reg.exe"';  // chemin complet : le serveur PHP n'a pas toujours le PATH
    $sortie = (string)@shell_exec($reg . ' query "HKCU\Software\Microsoft\Office\16.0\Outlook\Profiles" /s');
    $texte = fn(string $hex) => rtrim((string)mb_convert_encoding((string)hex2bin($hex), 'UTF-8', 'UTF-16LE'), "\0");
    $cles = [];
    $cle = '';
    foreach (preg_split('/\r?\n/', $sortie) as $l) {
        if (str_starts_with($l, 'HKEY_')) {
            $cle = trim($l);
        } elseif ($cle !== '' && preg_match('/^\s+(Account Name|New Signature)\s+(REG_BINARY|REG_SZ)\s+(.+?)\s*$/', $l, $m)) {
            $cles[$cle][$m[1]] = $m[2] === 'REG_SZ' ? $m[3] : $texte($m[3]);  // texte, ou binaire UTF-16 selon la version d'Outlook
        }
    }
    $out = [];
    foreach ($cles as $v) {
        if (str_contains($v['Account Name'] ?? '', '@')) {
            $out[strtolower($v['Account Name'])] = $v['New Signature'] ?? '';
        }
    }
    return $out;
}

/** Fichier de signature d'Outlook (.htm, en UTF-16, UTF-8 ou Windows-1252) -> HTML nettoyé, images remises dedans (data:).
 *  $image(chemin relatif « signature_fichiers/image001.png ») : contenu de l'image, ou null si elle manque. */
function signature_depuis_htm(string $brut, callable $image): string
{
    if (str_starts_with($brut, "\xFF\xFE")) {
        $brut = mb_convert_encoding(substr($brut, 2), 'UTF-8', 'UTF-16LE');
    } elseif (str_starts_with($brut, "\xEF\xBB\xBF")) {
        $brut = substr($brut, 3);
    } elseif (!mb_check_encoding($brut, 'UTF-8')) {
        $brut = mb_convert_encoding($brut, 'UTF-8', 'Windows-1252');
    }
    $brut = preg_replace('/<meta[^>]+charset[^>]*>/i', '', $brut) ?? $brut;
    if (preg_match('#<body[^>]*>(.*)</body>#is', $brut, $m)) {
        $brut = $m[1];
    }
    $brut = preg_replace_callback('#src="([^"]+)"#i', function ($m) use ($image) {  // logo dans « signature_fichiers/image001.png »
        if (preg_match('#^(data:|https?:|cid:)#i', $m[1])) {
            return $m[0];
        }
        $chemin = str_replace('\\', '/', rawurldecode(html_entity_decode($m[1])));
        $type = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif'][strtolower(pathinfo($chemin, PATHINFO_EXTENSION))] ?? null;
        $octets = $type ? $image($chemin) : null;
        return $octets !== null && strlen($octets) < 700000 ? 'src="data:' . $type . ';base64,' . base64_encode($octets) . '"' : $m[0];
    }, $brut) ?? $brut;
    return nettoyer_html($brut);
}

/** Signatures d'Outlook enregistrées sur ce PC (%APPDATA%\Microsoft\Signatures) pour le compte $adresse, la signature par défaut
 *  d'Outlook en premier : [{nom, html, defaut}]. Images jointes au fichier remises dedans. Vide hors de ce PC. */
function signatures_outlook_locales(string $adresse): array
{
    $dossier = reglage('signatures') ?: (getenv('APPDATA') ? getenv('APPDATA') . '\Microsoft\Signatures' : '');
    if ($dossier === '' || !is_dir($dossier)) {
        return [];
    }
    $defauts = signatures_par_defaut_outlook();
    if ($defauts !== null && $defauts && !array_key_exists(strtolower($adresse), $defauts)) {
        return [];  // le compte connecté n'est pas dans l'Outlook de ce PC : ce ne sont pas ses signatures
    }
    $defaut = $defauts[strtolower($adresse)] ?? null;
    $out = [];
    foreach (glob($dossier . '/*.htm') ?: [] as $f) {
        $html = signature_depuis_htm((string)file_get_contents($f), fn($c) => is_file("$dossier/$c") ? (string)file_get_contents("$dossier/$c") : null);
        if (trim(strip_tags($html, '<img>')) === '') {
            continue;
        }
        $nom = pathinfo($f, PATHINFO_FILENAME);
        $out[] = ['nom' => $nom, 'html' => $html, 'defaut' => $defaut !== null ? $nom === $defaut : str_contains(strtolower($nom), strtolower($adresse))];
    }
    usort($out, fn($a, $b) => $b['defaut'] <=> $a['defaut'] ?: strcmp($a['nom'], $b['nom']));
    return $out;
}

/** Signature d'un mail envoyé par Outlook (voir Boite::signature_outlook), ou null si le mail n'en a pas de marquée. */
function extraire_signature(string $html): ?string
{
    foreach (['id="divRplyFwdMsg"', 'id="appendonsend"', 'id="mail-editor-reference-message-container"', '<div style="border:none;border-top:solid #E1E1E1',
                 '<div style=\'border:none;border-top:solid #E1E1E1', 'class="gmail_quote"', '-----Original Message-----', '-----Message d\'origine-----'] as $cite) {
        $i = stripos($html, $cite);
        if ($i !== false) {
            $html = substr($html, 0, $i);
        }
    }
    $d = new DOMDocument();
    @$d->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NONET);
    $x = new DOMXPath($d);
    $bloc = $x->query('//*[@id="Signature" or @id="signature" or @id="x_Signature"]')->item(0);
    if ($bloc) {
        return $d->saveHTML($bloc);
    }
    $signet = $x->query('//a[@name="_MailAutoSig"]')->item(0);  // Outlook classique : de ce signet jusqu'à la fin du message
    if ($signet) {
        $debut = $signet;
        while ($debut->parentNode && !in_array(strtolower($debut->parentNode->nodeName), ['body', 'div'], true)) {
            $debut = $debut->parentNode;
        }
        $out = '';
        for ($n = $debut; $n; $n = $n->nextSibling) {
            $out .= $d->saveHTML($n);
        }
        return $out;
    }
    return null;
}
