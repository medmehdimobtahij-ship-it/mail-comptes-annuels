<?php
/**
 * Comptes de connexion des collaborateurs : data/comptes.json, mots de passe hachés (password_hash), jamais en clair.
 *
 * Les comptes du réglage « comptes » sont créés par outils/initialiser.php avec un mot de passe provisoire affiché une seule
 * fois (à changer dans Paramètres). En mode démo : « demo » / « demo ».
 *
 * Chaque compte peut être lié à son compte Microsoft du cabinet (« microsoft » : oid + tenant, identifiants fixes de
 * Microsoft Entra, jamais l'adresse seule) : on se connecte alors avec Microsoft et Microsoft Authenticator (securite.php).
 * Le mot de passe n'est plus qu'un secours (mode « Microsoft ou mot de passe »).
 */
declare(strict_types=1);

const LONGUEUR_MINI = 12;
const SESSION_INACTIVITE = 2 * 3600;   // secondes sans rien faire : reconnexion demandée
const SESSION_DUREE_MAX = 12 * 3600;   // une journée de travail au plus, même active
define('DOMAINE_CABINET', strtolower(reglage('domaine', 'example.fr')));  // domaine des adresses Microsoft du cabinet

/** Comptes créés au premier lancement : réglage « comptes » (« identifiant:Nom affiché,… »), noms d'exemple par défaut. */
function comptes_initiaux(): array
{
    $out = [];
    foreach (explode(',', reglage('comptes', 'claire:Claire Moreau,paul:Paul Bernard')) as $morceau) {
        [$id, $nom] = array_pad(explode(':', $morceau, 2), 2, '');
        if (trim($id) !== '') {
            $out[strtolower(trim($id))] = trim($nom) ?: trim($id);
        }
    }
    return $out;
}

function comptes_lire(): array
{
    return lire_json(chemin_data('comptes.json'), []);
}

function comptes_ecrire(array $comptes): void
{
    ecrire_json(chemin_data('comptes.json'), $comptes);
}

function mot_de_passe_provisoire(): string
{
    $b = fn() => rtrim(strtr(base64_encode(random_bytes(4)), '+/', '-_'), '=');
    return $b() . '-' . $b() . '-' . $b();
}

/** Crée les comptes manquants. Retourne [identifiant => mot de passe provisoire] des comptes créés. */
function comptes_initialiser(): array
{
    $comptes = comptes_lire();
    $crees = [];
    foreach (comptes_initiaux() as $id => $nom) {
        if (!isset($comptes[$id])) {
            $mdp = mot_de_passe_provisoire();
            $comptes[$id] = ['nom' => $nom, 'hash' => password_hash($mdp, PASSWORD_DEFAULT)];
            $crees[$id] = $mdp;
        }
    }
    if ($crees) {
        comptes_ecrire($comptes);
    }
    return $crees;
}

/** Le compte [identifiant, nom] si le mot de passe est bon, sinon null (compte sans mot de passe : jamais). */
function comptes_verifier(?string $identifiant, ?string $mdp): ?array
{
    $id = strtolower(trim((string)$identifiant));
    $c = comptes_lire()[$id] ?? null;
    if ($c && ($c['hash'] ?? '') !== '' && $mdp !== null && $mdp !== '' && password_verify($mdp, $c['hash'])) {
        return ['identifiant' => $id, 'nom' => $c['nom']];
    }
    return null;
}

/** Règle de la CNIL (délibération 2022-100) : 12 caractères avec majuscules, minuscules, chiffres et caractères spéciaux,
 *  ou 14 caractères avec au moins trois de ces sortes, ou une phrase de passe de 7 mots. Ni l'identifiant ni le nom dedans. */
function mot_de_passe_refuse(string $mdp, string $identifiant = '', string $nom = ''): ?string
{
    $sortes = (int)preg_match('/\p{Lu}/u', $mdp) + (int)preg_match('/\p{Ll}/u', $mdp) + (int)preg_match('/\d/', $mdp)
        + (int)preg_match('/[^\p{L}\d]/u', $mdp);
    $mots = count(preg_split('/[\s\-_.]+/u', trim($mdp), -1, PREG_SPLIT_NO_EMPTY));
    $l = mb_strlen($mdp);
    if (!(($l >= LONGUEUR_MINI && $sortes === 4) || ($l >= 14 && $sortes >= 3) || ($mots >= 7 && $l >= 20))) {
        return 'Au moins 12 caractères avec majuscule, minuscule, chiffre et caractère spécial (ou 14 caractères sans caractère spécial).';
    }
    $bas = mb_strtolower(sans_accents($mdp));
    foreach (array_merge([$identifiant], preg_split('/\s+/', $nom)) as $morceau) {
        $m = mb_strtolower(sans_accents((string)$morceau));
        if (mb_strlen($m) >= 4 && str_contains($bas, $m)) {
            return 'Le mot de passe ne doit pas contenir votre nom ni votre identifiant.';
        }
    }
    return null;
}

function comptes_changer(string $identifiant, ?string $nouveau): void
{
    $comptes = comptes_lire();
    if ($refus = mot_de_passe_refuse((string)$nouveau, $identifiant, (string)($comptes[$identifiant]['nom'] ?? ''))) {
        throw new InvalidArgumentException($refus);
    }
    $comptes[$identifiant]['hash'] = password_hash((string)$nouveau, PASSWORD_DEFAULT);
    $comptes[$identifiant]['generation'] = ($comptes[$identifiant]['generation'] ?? 0) + 1;  // ses autres sessions sont fermées
    comptes_ecrire($comptes);
}

/** Ferme toutes les sessions ouvertes d'un compte (autres navigateurs, autres PC). Retourne la nouvelle génération. */
function comptes_fermer_sessions(string $identifiant): int
{
    return sous_verrou(function () use ($identifiant) {
        $comptes = comptes_lire();
        $comptes[$identifiant]['generation'] = ($comptes[$identifiant]['generation'] ?? 0) + 1;
        comptes_ecrire($comptes);
        return $comptes[$identifiant]['generation'];
    });
}

// ---------- Compte Microsoft lié ----------
/** Le compte lié à ce compte Microsoft (oid + tenant), ou null. */
function compte_par_microsoft(string $oid, string $tid): ?array
{
    foreach (comptes_lire() as $id => $c) {
        if ($oid !== '' && ($c['microsoft']['oid'] ?? '') === $oid && ($c['microsoft']['tid'] ?? '') === $tid) {
            return ['identifiant' => $id, 'nom' => $c['nom']];
        }
    }
    return null;
}

/** Première connexion avec Microsoft d'un compte pas encore lié : le compte dont c'est le compte Microsoft du cabinet.
 *  Il faut une adresse du domaine du cabinet (domaine vérifié dans Microsoft Entra : personne d'autre ne peut l'avoir), de
 *  l'organisation du cabinet si elle est déjà connue, et le nom de la personne (nom affiché, ou nom de famille dans l'adresse). */
function compte_a_lier(array $infos): ?array
{
    $adresse = strtolower((string)($infos['preferred_username'] ?? $infos['email'] ?? ''));
    [$local, $domaine] = array_pad(explode('@', $adresse, 2), 2, '');
    $tid = (string)($infos['tid'] ?? '');
    if ($domaine !== DOMAINE_CABINET || ($tenant = tenant_cabinet()) !== '' && $tenant !== $tid) {
        return null;
    }
    $plat = fn(string $t) => preg_replace('/[^a-z]/', '', strtolower(sans_accents($t)));
    $trouves = [];
    foreach (comptes_lire() as $id => $c) {
        $mots = preg_split('/\s+/', trim($c['nom']));
        $famille = $plat((string)end($mots));
        if (empty($c['microsoft']) && ($plat((string)($infos['name'] ?? '')) === $plat($c['nom'])
                || (strlen($famille) >= 4 && str_contains($plat($local), $famille)))) {
            $trouves[] = ['identifiant' => $id, 'nom' => $c['nom']];
        }
    }
    return count($trouves) === 1 ? $trouves[0] : null;  // jamais de doute : un seul compte possible
}

/** Lie un compte à un compte Microsoft du cabinet. Retourne null, ou la raison du refus (clé de message). */
function comptes_lier_microsoft(string $identifiant, array $ms): ?string
{
    if ($ms['oid'] === '' || $ms['tid'] === '' || $ms['tid'] === TENANT_PERSONNEL) {
        return 'personnel';
    }
    return sous_verrou(function () use ($identifiant, $ms) {
        $comptes = comptes_lire();
        foreach ($comptes as $id => $c) {
            if ($id !== $identifiant && ($c['microsoft']['oid'] ?? '') === $ms['oid']) {
                return 'deja';
            }
            if (!empty($c['microsoft']['tid']) && $c['microsoft']['tid'] !== $ms['tid']) {
                return 'organisation';  // un seul cabinet : le même Microsoft Entra pour tous les comptes
            }
        }
        if (!isset($comptes[$identifiant])) {
            return 'inconnu';
        }
        $comptes[$identifiant]['microsoft'] = ['oid' => $ms['oid'], 'tid' => $ms['tid'], 'adresse' => $ms['adresse'], 'lie_le' => date('c')];
        comptes_ecrire($comptes);
        return null;
    });
}

function comptes_delier_microsoft(string $identifiant): void
{
    sous_verrou(function () use ($identifiant) {
        $comptes = comptes_lire();
        unset($comptes[$identifiant]['microsoft']);
        comptes_ecrire($comptes);
    });
}

/** Organisation Microsoft du cabinet (tenant), connue dès qu'un compte est lié. */
function tenant_cabinet(): string
{
    foreach (comptes_lire() as $c) {
        if (!empty($c['microsoft']['tid'])) {
            return $c['microsoft']['tid'];
        }
    }
    return '';
}

// ---------- Session (cookie protégé : HttpOnly, SameSite=Strict, Secure et préfixe __Host- en HTTPS) ----------
function nom_session(): string
{
    return en_https() ? '__Host-mail_comptes' : 'mail_comptes';
}

function session_demarrer(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $dossier = chemin_data('sessions');
    if (!is_dir($dossier)) {
        mkdir($dossier, 0770, true);
    }
    session_save_path($dossier);
    session_name(nom_session());
    ini_set('session.gc_maxlifetime', (string)SESSION_DUREE_MAX);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.sid_length', '48');
    ini_set('session.sid_bits_per_character', '6');
    session_set_cookie_params(['lifetime' => SESSION_DUREE_MAX, 'path' => '/', 'secure' => en_https(), 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
}

/** Empreinte du navigateur : un cookie de session volé ne sert pas depuis un autre navigateur. */
function empreinte_navigateur(): string
{
    return hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . cle_secrete());
}

/** Ouvre la session d'un compte (nouvel identifiant de session : pas de fixation). $methode : microsoft ou mot_de_passe. */
function ouvrir_session(array $compte, string $methode, ?bool $mfa = null): void
{
    session_demarrer();
    session_regenerate_id(true);
    $t = time();
    $_SESSION = ['identifiant' => $compte['identifiant'], 'nom' => $compte['nom'], 'methode' => $methode, 'mfa' => $mfa,
        'debut' => $t, 'vu' => $t, 'empreinte' => empreinte_navigateur(),
        'generation' => comptes_lire()[$compte['identifiant']]['generation'] ?? 0];
    session_write_close();
}

/** Personne connectée, ou 401. Les POST doivent venir de l'interface (en-tête X-Outil). $activite : la requête compte comme
 *  une action de la personne (les relectures automatiques de l'interface, elles, ne repoussent pas l'expiration). */
function exiger_connexion(bool $activite = true): array
{
    session_demarrer();
    $s = $_SESSION;
    $c = comptes_lire()[$s['identifiant'] ?? ''] ?? null;
    $t = time();
    if (!$c || empty($s['debut']) || $t - $s['debut'] > SESSION_DUREE_MAX || $t - ($s['vu'] ?? 0) > SESSION_INACTIVITE
        || !hash_equals($s['empreinte'] ?? '', empreinte_navigateur()) || ($s['generation'] ?? 0) !== ($c['generation'] ?? 0)) {
        $_SESSION = [];
        session_destroy();
        arret(401, 'Session expirée : reconnectez-vous.');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_SERVER['HTTP_X_OUTIL'] ?? '') !== '1') {
        arret(403, 'Requête refusée.');
    }
    if ($activite && $t - $s['vu'] > 60) {
        $_SESSION['vu'] = $t;
    }
    $moi = ['identifiant' => $s['identifiant'], 'nom' => $s['nom'], 'methode' => $s['methode'] ?? 'mot_de_passe', 'mfa' => $s['mfa'] ?? null];
    session_write_close();  // session lue : libérée aussitôt, les requêtes d'une même personne ne s'attendent pas
    return $moi;
}

/** Anti-devinette : 5 connexions ratées en 5 minutes depuis une même adresse bloquent 5 minutes ; 10 ratées sur un même
 *  compte en 15 minutes (quelle que soit l'adresse) bloquent ce compte 15 minutes. */
function essais_trop_nombreux(string $ip, string $compte = ''): bool
{
    $essais = lire_json(chemin_data('essais.json'), []);
    $recents = fn(string $k, int $duree) => count(array_filter($essais[$k] ?? [], fn($t) => time() - $t < $duree));
    return $recents($ip, 300) >= 5 || ($compte !== '' && $recents("compte:$compte", 900) >= 10);
}

function noter_essai_rate(string $ip, string $compte = ''): void
{
    sous_verrou(function () use ($ip, $compte) {
        $essais = lire_json(chemin_data('essais.json'), []);
        foreach ($essais as $k => $ts) {  // ménage : on ne garde que les 15 dernières minutes
            $essais[$k] = array_values(array_filter($ts, fn($t) => time() - $t < 900));
            if (!$essais[$k]) {
                unset($essais[$k]);
            }
        }
        $essais[$ip][] = time();
        if ($compte !== '' && isset(comptes_lire()[$compte])) {
            $essais["compte:$compte"][] = time();
        }
        ecrire_json(chemin_data('essais.json'), $essais, false);
    });
}
