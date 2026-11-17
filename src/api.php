<?php
/**
 * L'API JSON de l'application (interface : public/app.js et public/bilan.js). Toute l'API exige une session, sauf la connexion.
 *
 * Le mail des comptes annuels se prépare avec ou sans Pennylane (jeton API du cabinet, sinon fichier importé ou saisie), et
 * part avec ou sans Outlook : brouillon dans la boîte Microsoft de la personne connectée, ou mail ouvert dans sa messagerie
 * (lien mailto), ou fichier .eml à ouvrir ; la case « Mail envoyé » se coche ensuite à la main.
 */
declare(strict_types=1);

final class Api
{
    private array $moi = [];

    public function traiter(string $methode, string $chemin): void
    {
        if ($chemin === '/api/connexion/options' && $methode === 'GET') {
            json_reponse(200, ['microsoft' => connexion_microsoft_disponible(), 'mot_de_passe' => mode_connexion() === '', 'demo' => MODE_DEMO]);
        }
        if (in_array($chemin, ['/api/connexion', '/api/deconnexion'], true) && ($_SERVER['HTTP_X_OUTIL'] ?? '') !== '1') {
            arret(403, 'Requête refusée.');  // seulement depuis l'interface (aucun autre site ne peut connecter ou déconnecter)
        }
        if ($chemin === '/api/connexion' && $methode === 'POST') {
            json_reponse(...$this->connexion());
        }
        if ($chemin === '/api/microsoft/connexion' && $methode === 'GET') {
            connexion_microsoft_disponible() ? redirection(microsoft_flux_demarrer('connexion')) : page_redirection('/?microsoft=indisponible');
        }
        if ($chemin === '/api/deconnexion' && $methode === 'POST') {
            session_demarrer();
            if (!empty($_SESSION['identifiant'])) {
                journal_securite('deconnexion', $_SESSION['identifiant']);
            }
            $_SESSION = [];
            session_destroy();
            json_reponse(200, ['ok' => true]);
        }
        $this->moi = exiger_connexion(($_SERVER['HTTP_X_OUTIL_AUTO'] ?? '') !== '1');  // relectures automatiques : pas une activité
        if (MODE_DEMO && $this->hors_demo($methode, $chemin)) {  // démo hors ligne : Microsoft et Pennylane jamais appelés
            json_reponse(409, ['erreur' => DEMO_MESSAGE, 'demo' => true]);
        }
        $routes = [
            ['GET', '/api/moi', 'moi'], ['POST', '/api/mot-de-passe', 'mot_de_passe'],
            ['GET', '/api/parametres', 'parametres_etat'], ['POST', '/api/parametres/cabinet', 'parametres_cabinet'],
            ['GET', '/api/microsoft/lier', 'microsoft_lier'], ['GET', '/api/securite', 'securite'],
            ['POST', '/api/securite/mode', 'securite_mode'], ['POST', '/api/securite/delier', 'securite_delier'],
            ['POST', '/api/securite/fermer-sessions', 'securite_fermer_sessions'],
            ['POST', '/api/pennylane/jetons', 'pennylane_jetons'],
            ['GET', '/api/outlook', 'outlook_etat'], ['POST', '/api/outlook/verifier', 'outlook_verifier'],
            ['POST', '/api/outlook/connexion', 'outlook_connexion'], ['POST', '/api/outlook/connexion/retour', 'outlook_retour'],
            ['POST', '/api/outlook/deconnexion', 'outlook_deconnexion'], ['POST', '/api/outlook/reglages', 'outlook_reglages'],
            ['GET', '/api/signature', 'signature'], ['POST', '/api/signature', 'signature_enregistrer'], ['POST', '/api/signature/effacer', 'signature_effacer'],
            ['POST', '/api/signature/outlook', 'signature_outlook'], ['GET', '/api/signature/outlook-locales', 'signatures_locales'],
            ['POST', '/api/signature/apercu', 'signature_apercu'],
            // Mail des comptes annuels
            ['GET', '/api/bilan', 'bilan'], ['POST', '/api/bilan/lire', 'bilan_lire'], ['POST', '/api/bilan/indications', 'bilan_indications_api'],
            ['POST', '/api/bilan/saisies', 'bilan_saisies'], ['POST', '/api/bilan/reinitialiser', 'bilan_reinitialiser'], ['POST', '/api/bilan/apercu', 'bilan_apercu'],
            ['POST', '/api/bilan/brouillon', 'bilan_brouillon'], ['POST', '/api/bilan/eml', 'bilan_eml_api'], ['POST', '/api/bilan/envoye', 'bilan_envoye'],
            ['POST', '/api/bilan/parametres', 'bilan_parametres_ecrire'], ['POST', '/api/bilan/import', 'bilan_import'],
            ['POST', '/api/bilan/dossier', 'bilan_dossier_creer'], ['POST', '/api/bilan/dossier/retirer', 'bilan_dossier_retirer'],
            ['GET', '/api/bilan/modele.csv', 'bilan_modele'],
        ];
        $connue = false;
        foreach ($routes as [$verbe, $motif, $nom]) {
            if ($motif === $chemin) {
                $connue = true;
                if ($verbe === $methode) {
                    $r = $this->$nom();
                    json_reponse(...(is_array($r) && isset($r[0], $r[1]) && is_int($r[0]) && count($r) === 2 ? $r : [200, $r]));
                }
            }
        }
        arret($connue ? 405 : 404, $connue ? 'Méthode non permise.' : 'Introuvable.');
    }

    /** Routes qui appellent Microsoft ou Pennylane (ou en changent les réglages) : désactivées en démo. */
    private function hors_demo(string $methode, string $chemin): bool
    {
        return in_array("$methode $chemin", ['GET /api/microsoft/lier', 'POST /api/outlook/connexion', 'POST /api/outlook/connexion/retour',
            'POST /api/outlook/verifier', 'POST /api/outlook/reglages', 'POST /api/signature/outlook', 'POST /api/securite/mode',
            'POST /api/securite/delier', 'POST /api/pennylane/jetons', 'POST /api/mot-de-passe'], true);
    }

    // ---------- Connexion et compte ----------
    private function connexion(): array
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
        $d = corps();
        $id = strtolower(trim((string)($d['identifiant'] ?? '')));
        if (mode_connexion() !== '') {
            return [403, ['erreur' => MESSAGES_MICROSOFT['mot_de_passe'], 'microsoft' => true]];
        }
        if (essais_trop_nombreux($ip, $id)) {
            journal_securite('connexion_bloquee', isset(comptes_lire()[$id]) ? $id : null);
            return [429, ['erreur' => 'Trop d\'essais : réessayez dans 15 minutes.']];
        }
        $compte = comptes_verifier($id, $d['mot_de_passe'] ?? null);
        if (!$compte) {
            noter_essai_rate($ip, $id);
            journal_securite('connexion_refusee', isset(comptes_lire()[$id]) ? $id : null, ['methode' => 'mot_de_passe']);
            return [401, ['erreur' => 'Identifiant ou mot de passe incorrect.']];
        }
        ouvrir_session($compte, 'mot_de_passe');
        journal_securite('connexion', $compte['identifiant'], ['methode' => 'mot_de_passe', 'mfa' => false]);
        return [200, $compte + ['methode' => 'mot_de_passe', 'mfa' => false]];
    }

    /** Retour de la page Microsoft (connexion, ou liaison du compte) : jamais d'erreur brute, toujours un retour au site. */
    public function retour_microsoft(): never
    {
        try {
            [$flux, $r, $infos, $mfa] = microsoft_flux_terminer($_GET);
            $ms = ['oid' => (string)($infos['oid'] ?? ''), 'tid' => (string)$infos['tid'],
                'adresse' => strtolower((string)($infos['preferred_username'] ?? $infos['email'] ?? ''))];
            if ($flux['but'] === 'lier') {
                if ($refus = comptes_lier_microsoft((string)$flux['identifiant'], $ms)) {
                    journal_securite('liaison_refusee', $flux['identifiant'], ['adresse' => $ms['adresse'], 'motif' => $refus]);
                    page_redirection("/?microsoft=$refus");
                }
                (new Boite((string)$flux['identifiant'], outlook_client_id()))->adopter($r, $infos);
                journal_securite('microsoft_lie', $flux['identifiant'], ['adresse' => $ms['adresse'], 'mfa' => $mfa]);
                page_redirection('/?microsoft=lie');
            }
            $compte = compte_par_microsoft($ms['oid'], $ms['tid']);
            if (!$compte && ($compte = compte_a_lier($infos))) {  // première connexion : lié tout seul
                if ($refus = comptes_lier_microsoft($compte['identifiant'], $ms)) {
                    page_redirection("/?microsoft=$refus");
                }
                journal_securite('microsoft_lie', $compte['identifiant'], ['adresse' => $ms['adresse'], 'motif' => 'première connexion']);
            }
            if (!$compte) {
                journal_securite('connexion_refusee', null, ['methode' => 'microsoft', 'adresse' => $ms['adresse'], 'motif' => 'compte non lié']);
                page_redirection('/?microsoft=inconnu');
            }
            if (mode_connexion() === 'microsoft_mfa' && $mfa !== true) {
                journal_securite('connexion_refusee', $compte['identifiant'], ['methode' => 'microsoft', 'motif' => 'sans double authentification']);
                page_redirection('/?microsoft=mfa');
            }
            (new Boite($compte['identifiant'], outlook_client_id()))->adopter($r, $infos);  // la boîte Outlook est connectée du même coup
            ouvrir_session($compte, 'microsoft', $mfa);
            journal_securite('connexion', $compte['identifiant'], ['methode' => 'microsoft', 'mfa' => $mfa, 'adresse' => $ms['adresse']]);
            page_redirection('/');
        } catch (ErreurOutlook $e) {
            $cle = array_key_exists($e->getMessage(), MESSAGES_MICROSOFT) ? $e->getMessage() : 'erreur';
            journal_securite('connexion_refusee', null, ['methode' => 'microsoft', 'motif' => $cle]);
            page_redirection("/?microsoft=$cle");
        } catch (Throwable $e) {
            error_log('Mail comptes annuels : ' . $e);
            page_redirection('/?microsoft=erreur');
        }
    }

    private function moi(): array
    {
        return $this->moi;
    }

    /** Nouvelle génération de sessions (mot de passe changé, sessions fermées) : celle-ci reste ouverte. */
    private function garder_cette_session(int $generation): void
    {
        session_demarrer();
        $_SESSION['generation'] = $generation;
        session_write_close();
    }

    private function mot_de_passe(): array
    {
        $d = corps();
        if (!comptes_verifier($this->moi['identifiant'], $d['ancien'] ?? null)) {
            throw new ErreurSaisie(['ancien' => 'Mot de passe actuel incorrect.']);
        }
        try {
            sous_verrou(fn() => comptes_changer($this->moi['identifiant'], $d['nouveau'] ?? null));
        } catch (InvalidArgumentException $e) {
            throw new ErreurSaisie(['nouveau' => $e->getMessage()]);
        }
        $this->garder_cette_session(comptes_lire()[$this->moi['identifiant']]['generation']);
        journal_securite('mot_de_passe_change', $this->moi['identifiant']);
        return ['ok' => true];
    }

    // ---------- Sécurité : compte Microsoft, mode de connexion, journal ----------
    private function microsoft_lier(): never
    {
        connexion_microsoft_disponible() ? redirection(microsoft_flux_demarrer('lier', $this->moi['identifiant']))
            : page_redirection('/?microsoft=indisponible');
    }

    private function securite(): array
    {
        $comptes = comptes_lire();
        $ms = $comptes[$this->moi['identifiant']]['microsoft'] ?? null;
        return [
            'session' => ['methode' => $this->moi['methode'], 'mfa' => $this->moi['mfa']],
            'microsoft' => $ms ? ['adresse' => $ms['adresse'], 'lie_le' => $ms['lie_le']] : null,
            'comptes' => array_map(fn($id, $c) => ['identifiant' => $id, 'nom' => $c['nom'], 'lie' => !empty($c['microsoft'])], array_keys($comptes), $comptes),
            'mode' => mode_connexion(), 'modes' => MODES_CONNEXION, 'disponible' => connexion_microsoft_disponible(),
            'mot_de_passe' => !empty($comptes[$this->moi['identifiant']]['hash']) && mode_connexion() === '',
            'journal' => journal_du_compte($this->moi['identifiant']),
            'duree' => ['inactivite' => SESSION_INACTIVITE, 'max' => SESSION_DUREE_MAX],
        ];
    }

    /** Mode de connexion du cabinet. Jamais de blocage : Microsoft seulement si l'on est soi-même lié ; double
     *  authentification exigée seulement si on vient soi-même de se connecter ainsi. */
    private function securite_mode(): array
    {
        $mode = (string)(corps()['mode'] ?? '');
        if (!array_key_exists($mode, MODES_CONNEXION)) {
            throw new ErreurSaisie(['mode' => 'Mode inconnu.']);
        }
        if ($mode !== '') {  // les comptes pas encore liés le seront à leur première connexion Microsoft (compte_a_lier)
            if (empty(comptes_lire()[$this->moi['identifiant']]['microsoft'])) {
                throw new ErreurSaisie(['mode' => 'Liez d\'abord votre compte Microsoft (bouton ci-dessus) : sinon vous ne pourriez plus vous connecter.']);
            }
            if (!connexion_microsoft_disponible()) {
                throw new ErreurSaisie(['mode' => MESSAGES_MICROSOFT['indisponible']]);
            }
        }
        if ($mode === 'microsoft_mfa' && $this->moi['mfa'] !== true) {
            throw new ErreurSaisie(['mode' => 'Connectez-vous d\'abord avec Microsoft et Microsoft Authenticator : on vérifie ainsi que la double authentification marche.']);
        }
        sous_verrou(function () use ($mode) {
            $p = lire_json(chemin_data('parametres.json'), []);
            $p['ModeConnexion'] = $mode;
            ecrire_json(chemin_data('parametres.json'), $p);
        });
        journal_securite('mode_connexion', $this->moi['identifiant'], ['mode' => $mode]);
        return $this->securite();
    }

    private function securite_delier(): array
    {
        if (mode_connexion() !== '') {
            throw new ErreurSaisie(['microsoft' => 'Repassez d\'abord en « Microsoft ou mot de passe » : sinon vous ne pourriez plus vous connecter.']);
        }
        comptes_delier_microsoft($this->moi['identifiant']);
        journal_securite('microsoft_delie', $this->moi['identifiant']);
        return $this->securite();
    }

    private function securite_fermer_sessions(): array
    {
        $this->garder_cette_session(comptes_fermer_sessions($this->moi['identifiant']));
        journal_securite('sessions_fermees', $this->moi['identifiant']);
        return ['ok' => true];
    }

    // ---------- Paramètres : intégrations (chacune facultative), cabinet ----------
    /** État de chaque intégration, pour la page Paramètres : rien n'est obligatoire, l'outil marche sans. */
    private function parametres_etat(): array
    {
        $j = jetons_pennylane();
        $masque = fn($s) => $s === '' ? '' : substr($s, 0, 4) . '…' . substr($s, -4);
        return ['pennylane' => ['principal' => $masque($j['principal']), 'lecture' => $masque($j['lecture']),
            'relie' => MODE_DEMO || $j['principal'] !== '', 'simule' => MODE_DEMO],
            'outlook' => ['adresse' => $this->boite()->adresse(), 'application' => outlook_client_id() !== '' && !MODE_DEMO],
            'microsoft' => connexion_microsoft_disponible(), 'cabinet' => cabinet_nom(), 'demo' => MODE_DEMO,
            'bilan' => bilan_parametres()];
    }

    private function parametres_cabinet(): array
    {
        $nom = trim((string)(corps()['cabinet'] ?? ''));
        sous_verrou(function () use ($nom) {
            $p = lire_json(chemin_data('parametres.json'), []);
            if ($nom === '') {
                unset($p['CabinetNom']);
            } else {
                $p['CabinetNom'] = mb_substr($nom, 0, 120);
            }
            ecrire_json(chemin_data('parametres.json'), $p);
        });
        return $this->parametres_etat();
    }

    private function pennylane_jetons(): array
    {
        $d = corps();
        enregistrer_jetons_pennylane(isset($d['principal']) ? (string)$d['principal'] : null, isset($d['lecture']) ? (string)$d['lecture'] : null);
        @unlink(chemin_data('pennylane-dossiers.json'));
        return ['ok' => true];
    }

    // ---------- Boîte Microsoft ----------
    private function boite(): Boite
    {
        return new Boite($this->moi['identifiant'], outlook_client_id());
    }

    private function erreur_outlook(ErreurOutlook $e, int $statut = 409): array
    {
        return [$statut, ['erreur' => $e->getMessage(), 'action' => $e->action]];
    }

    private function outlook_etat(): array
    {
        $b = $this->boite();
        $f = $b->flux();
        $cx = $f && ($f['expire'] ?? 0) > time() - 3600
            ? ['etat' => $f['statut'] ?? 'attente', 'message' => $f['message'] ?? null, 'action' => $f['action'] ?? null] : null;
        $p = lire_json(chemin_data('parametres.json'), []);
        return ['adresse' => $b->adresse(), 'client_id' => $b->client_id, 'connexion' => $cx, 'retour_site' => retour_declare(),
            'adresse_site' => origine(), 'client_id_reglage' => (string)($p['MessagerieClientId'] ?? ''), 'secret' => microsoft_secret() !== '',
            'demo' => MODE_DEMO];
    }

    private function outlook_verifier(): array
    {
        try {
            $this->boite()->verifier_application();
        } catch (ErreurOutlook $e) {
            return $this->erreur_outlook($e, 200);
        }
        return ['ok' => true];
    }

    private function outlook_connexion(): array
    {
        if (outlook_client_id() === '') {
            return [409, ['erreur' => 'Aucune application Microsoft déclarée : Outlook n\'est pas configuré (Paramètres › Réglages avancés).']];
        }
        $retour = adresse_retour_microsoft();
        $cx = $this->boite()->demarrer($retour);
        return ['etat' => 'attente', 'url' => $cx['url'], 'automatique' => $retour !== RETOUR_MICROSOFT];
    }

    /** Connexion terminée : retour Microsoft sur le site, ou adresse collée par la personne (page de retour Microsoft). */
    private function outlook_retour(): array
    {
        $b = $this->boite();
        try {
            $a = $b->terminer((string)(corps()['adresse'] ?? ''));
        } catch (ErreurOutlook $e) {
            return $this->erreur_outlook($e);
        }
        if (empty(comptes_lire()[$this->moi['identifiant']]['microsoft']) && !$b->personnel()  // compte Microsoft lié du même coup
            && comptes_lier_microsoft($this->moi['identifiant'], $b->identite()) === null) {
            journal_securite('microsoft_lie', $this->moi['identifiant'], ['adresse' => $a]);
        }
        return ['ok' => true, 'adresse' => $a];
    }

    private function outlook_deconnexion(): array
    {
        $this->boite()->deconnecter();
        return ['ok' => true];
    }

    private function outlook_reglages(): array
    {
        $d = corps();
        sous_verrou(function () use ($d) {
            $p = lire_json(chemin_data('parametres.json'), []);
            if (array_key_exists('client_id', $d)) {
                $v = trim((string)$d['client_id']);
                if ($v === '') {
                    unset($p['MessagerieClientId']);
                } else {
                    $p['MessagerieClientId'] = $v;
                }
            }
            if (array_key_exists('retour_site', $d)) {
                $p['MessagerieRetour'] = !empty($d['retour_site']) ? '1' : '';
            }
            if (array_key_exists('secret', $d)) {  // jamais en clair dans les fichiers ; vide = retiré
                $s = trim((string)$d['secret']);
                if ($s === '') {
                    unset($p['MessagerieSecret']);
                } else {
                    $p['MessagerieSecret'] = chiffrer($s, 'microsoft-secret');
                }
            }
            ecrire_json(chemin_data('parametres.json'), $p);
        });
        return $this->outlook_etat();
    }

    // --- Signature des mails (chacun la sienne) : Microsoft n'ajoute pas celle d'Outlook ---
    /** Signature enregistrée de la personne connectée ; sinon une signature simple (nom, cabinet, adresse mail). */
    private function signature(): array
    {
        $html = signature_enregistree($this->moi['identifiant']);
        return ['html' => $html ?? signature_html($this->moi['identifiant'], $this->moi['nom'], $this->boite()->adresse()), 'personnalisee' => $html !== null];
    }

    /** Signature relue dans le dernier mail envoyé (site en ligne, sans accès à l'Outlook du PC). Pas gardée : l'interface demande. */
    private function signature_outlook(): array
    {
        $b = $this->boite();
        try {
            $sig = $b->adresse() === '' ? null : $b->signature_outlook();
        } catch (ErreurOutlook) {
            $sig = null;
        }
        if ($sig === null) {
            return [409, ['erreur' => 'Aucune signature trouvée dans vos derniers mails envoyés : importez le fichier de votre signature Outlook.']];
        }
        return ['html' => $sig, 'source' => 'outlook'];
    }

    /** Signatures enregistrées dans Outlook, sur ce PC : seulement quand le navigateur est sur le PC où tourne l'application
     *  (sinon ce seraient celles d'un autre poste). La signature par défaut d'Outlook en premier. */
    private function signatures_locales(): array
    {
        $adresse = $this->boite()->adresse();
        return ['signatures' => en_local() && $adresse !== '' ? signatures_outlook_locales($adresse) : []];
    }

    /** Fichier de signature importé (.htm d'Outlook) : nettoyé pour l'aperçu, pas encore gardé. */
    private function signature_apercu(): array
    {
        $brut = mb_substr((string)(corps()['html'] ?? ''), 0, 3000000);
        if (preg_match('#<body[^>]*>(.*)</body>#is', $brut, $m)) {
            $brut = $m[1];
        }
        return ['html' => nettoyer_html($brut)];
    }

    private function signature_enregistrer(): array
    {
        $html = nettoyer_html(mb_substr((string)(corps()['html'] ?? ''), 0, 1500000));  // avec le logo intégré
        ecrire_fichier(chemin_data('signatures', $this->moi['identifiant'] . '.html'), $html);
        return ['html' => $html, 'personnalisee' => true];
    }

    private function signature_effacer(): array
    {
        @unlink(chemin_data('signatures', $this->moi['identifiant'] . '.html'));
        return $this->signature();
    }

    // ---------- Mail des comptes annuels ----------
    private function bilan_saisies_lire(): array
    {
        return lire_json(chemin_data('bilan', 'saisies.json'), []);
    }

    /** Pennylane utilisable : jeton du cabinet enregistré (ou Pennylane simulé de la démo). */
    private function pennylane_relie(): bool
    {
        return MODE_DEMO || jetons_pennylane()['principal'] !== '';
    }

    private function bilan(): array
    {
        $cabinet = [];
        $erreur = '';
        if ($this->pennylane_relie()) {
            try {
                $cabinet = dossiers_cabinet(!empty($_GET['frais']));
            } catch (ErreurPennylane $e) {
                $erreur = $e->getMessage();
            }
        }
        $envois = lire_json(chemin_data('bilan', 'envois.json'), []);
        $saisies = $this->bilan_saisies_lire();
        $dossiers = array_values(array_map(fn($d) => ['id' => $d['id'], 'nom' => $d['nom'], 'envoye' => $envois[$d['id']] ?? null,
            'modifie' => isset($saisies[$d['id']]), 'source' => $d['source'] ?: (ctype_digit($d['id']) ? 'api' : 'manuel')], bilan_dossiers($cabinet)));
        return ['dossiers' => $dossiers, 'erreur' => $erreur, 'parametres' => bilan_parametres(), 'statuts_is' => BILAN_STATUTS_IS,
            'pennylane' => $this->pennylane_relie(), 'boite' => $this->boite()->adresse(), 'outlook' => outlook_client_id() !== '' || MODE_DEMO,
            'demo' => MODE_DEMO, 'chiffres' => ['dossiers' => count($dossiers), 'envoyes' => count(array_intersect_key($envois, array_flip(array_column($dossiers, 'id'))))]];
    }

    private function bilan_id(): string
    {
        return preg_replace('/[^A-Za-z0-9-]/', '', (string)(corps()['id'] ?? ''));
    }

    private function bilan_dossier(string $id): array
    {
        $cabinet = $this->pennylane_relie() ? (array)(lire_json(chemin_data('pennylane-dossiers.json'), [])['dossiers'] ?? []) : [];
        $d = bilan_dossiers($cabinet)[$id] ?? null;  // dossier Pennylane pas encore lu : connu par la dernière liste du cabinet
        if (!$d) {
            arret(404, 'Dossier introuvable.');
        }
        return $d;
    }

    private function bilan_formule(array $d): ?string
    {
        return $d['formule'] !== '' ? $d['formule'] : null;
    }

    private function bilan_etat(string $id): array
    {
        $d = $this->bilan_dossier($id);
        $initiales = formulaire_initial($d, bilan_parametres(), $this->bilan_formule($d));
        $saisies = $this->bilan_saisies_lire()[$id] ?? [];
        $valeurs = array_merge($initiales, $saisies);
        return ['id' => $id, 'nom' => $d['nom'], 'valeurs' => $valeurs, 'initiales' => $initiales, 'modifie' => (bool)$saisies,
            'indications' => bilan_indications($d, $valeurs), 'source' => bilan_source($d),
            'envoye' => lire_json(chemin_data('bilan', 'envois.json'), [])[$id] ?? null];
    }

    /** Au choix d'un dossier Pennylane, il est relu par l'API (jamais de données périmées). Sans Pennylane, ou si Pennylane ne
     *  répond pas, le dossier s'ouvre avec sa fiche (fichier importé ou saisie) : le travail n'est jamais bloqué. */
    private function bilan_lire(): array
    {
        @set_time_limit(180);
        $id = $this->bilan_id();
        $d = $this->bilan_dossier($id);
        $avertissement = null;
        if (ctype_digit($id) && $this->pennylane_relie()) {
            try {
                $extrait = extraire_dossier(pennylane(), $id, $d['nom']);
                ecrire_json(chemin_data('cabinet', 'dossiers', "$id.json"), $extrait);
            } catch (ErreurPennylane $e) {
                $avertissement = 'Lecture dans Pennylane impossible : ' . $e->getMessage() . ' Le dossier s\'ouvre avec les informations déjà connues.';
            }
        }
        return $this->bilan_etat($id) + ['avertissement' => $avertissement];
    }

    private function bilan_indications_api(): array
    {
        return bilan_indications($this->bilan_dossier($this->bilan_id()), (array)(corps()['valeurs'] ?? []));
    }

    /** Modifications à la main. Les coordonnées du client (prénom, nom, e-mail, copie, civilité, tu / vous) vont dans sa fiche,
     *  gardées pour les prochains mails ; pour le reste, seules les valeurs différentes des règles du cabinet sont gardées. */
    private function bilan_saisies(): array
    {
        $id = $this->bilan_id();
        $v = (array)(corps()['valeurs'] ?? []);
        $dossier = $this->bilan_dossier($id);
        $avant = formulaire_initial($dossier, bilan_parametres(), $this->bilan_formule($dossier));
        if (!$dossier['exercices'] && ($fin = lire_date($v['date_cloture'] ?? ''))) {  // dossier sans exercice connu : la clôture saisie va
            bilan_fiche_exercice($id, $dossier['nom'], $fin);                            // dans sa fiche (missions « 2025 » proposées)
        }
        $coordonnees = array_filter(array_intersect_key($v, array_flip(BILAN_COORDONNEES)), fn($x, $k) => $x !== $avant[$k], ARRAY_FILTER_USE_BOTH);
        if ($coordonnees) {
            bilan_fiche_enregistrer($id, $dossier['nom'], $coordonnees);
        }
        $dossier = $this->bilan_dossier($id);
        $initiales = formulaire_initial($dossier, bilan_parametres(), $this->bilan_formule($dossier));
        $diff = [];
        foreach ($v as $k => $x) {  // une valeur laissée telle que proposée suit la fiche (missions recalculées après la clôture…)
            if (array_key_exists($k, $initiales) && $initiales[$k] !== $x && $avant[$k] !== $x && !in_array($k, BILAN_COORDONNEES, true)) {
                $diff[$k] = $x;
            }
        }
        $diff += array_diff_key($this->bilan_saisies_lire()[$id] ?? [], $v);  // modifications d'autres champs, non envoyées ici
        sous_verrou(function () use ($id, $diff) {
            $s = $this->bilan_saisies_lire();
            if ($diff) {
                $s[$id] = $diff;
            } else {
                unset($s[$id]);
            }
            ecrire_json(chemin_data('bilan', 'saisies.json'), $s);
        });
        $valeurs = array_merge($initiales, $diff);
        return ['modifie' => (bool)$diff, 'valeurs' => $valeurs, 'indications' => bilan_indications($dossier, $valeurs)];
    }

    private function bilan_reinitialiser(): array
    {
        $id = $this->bilan_id();
        sous_verrou(function () use ($id) {
            $s = $this->bilan_saisies_lire();
            unset($s[$id]);
            ecrire_json(chemin_data('bilan', 'saisies.json'), $s);
        });
        return $this->bilan_etat($id);
    }

    /** Destinataires en copie : ceux du client (sa fiche) et la copie du cabinet (Paramètres). */
    private function bilan_copie(array $v): array
    {
        return array_values(array_unique(array_merge(adresses((string)($v['cc'] ?? '')), adresses(bilan_parametres()['Copie']))));
    }

    private function bilan_apercu(): array
    {
        $v = (array)(corps()['valeurs'] ?? []);
        try {
            [$objet, $texte, $html, $joindre] = bilan_rediger($v);
        } catch (ErreurFormulaire $e) {
            return ['manques' => $e->manques];
        }
        return ['objet' => $objet, 'texte' => $texte, 'html' => $html, 'joindre' => $joindre, 'a' => trim((string)($v['email'] ?? '')),
            'cc' => implode(', ', $this->bilan_copie($v))];
    }

    /** Valeurs du mail à préparer, ou la réponse 422 qui dit quoi compléter. */
    private function bilan_mail(array $v): array
    {
        try {
            $m = bilan_rediger($v);
        } catch (ErreurFormulaire $e) {
            arret(422, 'À compléter : ' . implode(', ', $e->manques) . '.');
        }
        if (!adresses((string)($v['email'] ?? ''))) {
            arret(422, 'À compléter : e-mail du destinataire.');
        }
        return $m;
    }

    /** Brouillon dans la boîte Outlook de la personne (signature comprise) : à relire, compléter des pièces jointes, envoyer. */
    private function bilan_brouillon(): array
    {
        $v = (array)(corps()['valeurs'] ?? []);
        [$objet, , $html] = $this->bilan_mail($v);
        if (MODE_DEMO) {  // rien ne part : le brouillon est simulé
            return ['ok' => true, 'demo' => true, 'lien' => '', 'message' => 'Mode démo : brouillon simulé, rien n\'est envoyé (Outlook est désactivé ici).'];
        }
        try {
            $b = $this->boite()->brouillon(adresses((string)($v['email'] ?? '')), $objet, $html, $this->bilan_copie($v));
        } catch (ErreurOutlook $e) {
            return $this->erreur_outlook($e);
        }
        return ['ok' => true, 'lien' => $b['lien']];
    }

    /** Sans Outlook : le mail en fichier .eml (signature comprise), à ouvrir dans sa messagerie. */
    private function bilan_eml_api(): never
    {
        $v = (array)(corps()['valeurs'] ?? []);
        [$objet, $texte, $html] = $this->bilan_mail($v);
        $eml = bilan_eml($objet, $texte, avec_signature($html, $this->moi['identifiant']), adresses((string)$v['email']), $this->bilan_copie($v));
        $nom = 'Comptes annuels ' . preg_replace('/[^\p{L}\p{N} .\-]/u', '', (string)($v['societe'] ?? '')) . '.eml';
        header('Content-Type: message/rfc822');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9 .\-]/', '_', sans_accents($nom)) . '"; filename*=UTF-8\'\'' . rawurlencode($nom));
        header('Cache-Control: no-store');
        echo $eml;
        exit;
    }

    private function bilan_envoye(): array
    {
        $d = corps();
        $id = $this->bilan_id();
        sous_verrou(function () use ($d, $id) {
            $e = lire_json(chemin_data('bilan', 'envois.json'), []);
            if (!empty($d['etat'])) {
                $e[$id] = date('d/m/Y');
            } else {
                unset($e[$id]);
            }
            ecrire_json(chemin_data('bilan', 'envois.json'), $e);
        });
        return ['ok' => true, 'envoye' => lire_json(chemin_data('bilan', 'envois.json'), [])[$id] ?? null];
    }

    private function bilan_parametres_ecrire(): array
    {
        $d = corps();
        if (array_key_exists('Copie', $d) && trim((string)$d['Copie']) !== ''
            && count(adresses((string)$d['Copie'])) !== count(array_filter(array_map('trim', preg_split('/[;,]/', (string)$d['Copie']))))) {
            throw new ErreurSaisie(['Copie' => 'Adresse e-mail invalide.']);
        }
        sous_verrou(function () use ($d) {
            $p = lire_json(chemin_data('bilan', 'parametres.json'), []);
            foreach (BILAN_DEFAUTS as $k => $defaut) {
                if (array_key_exists($k, $d)) {
                    $v = trim((string)$d[$k]);
                    if (($v === '' && $k !== 'Copie') || $v === $defaut) {
                        unset($p[$k]);
                    } else {
                        $p[$k] = $v;
                    }
                }
            }
            ecrire_json(chemin_data('bilan', 'parametres.json'), $p);
        });
        return bilan_parametres();
    }

    /** Liste des clients (et chiffres de l'exercice) depuis un fichier CSV ou Excel, envoyé en base64. */
    private function bilan_import(): array
    {
        $contenu = base64_decode((string)(corps()['contenu'] ?? ''), true);
        if ($contenu === false || $contenu === '') {
            return [422, ['erreur' => 'Fichier vide ou illisible.']];
        }
        try {
            [$ajoutes, $maj, $erreurs] = bilan_importer($contenu);
        } catch (InvalidArgumentException $e) {
            return [422, ['erreur' => $e->getMessage()]];
        }
        return ['ajoutes' => $ajoutes, 'mis_a_jour' => $maj, 'erreurs' => $erreurs];
    }

    private function bilan_dossier_creer(): array
    {
        try {
            return ['id' => bilan_nouveau_dossier((string)(corps()['nom'] ?? ''))];
        } catch (InvalidArgumentException $e) {
            throw new ErreurSaisie(['nom' => $e->getMessage()]);
        }
    }

    private function bilan_dossier_retirer(): array
    {
        $id = $this->bilan_id();
        if (ctype_digit($id) && $this->pennylane_relie()) {
            return [409, ['erreur' => 'Dossier de Pennylane : il se retire dans Pennylane.']];
        }
        bilan_retirer_dossier($id);
        return ['ok' => true];
    }

    /** Fichier d'exemple à remplir (mêmes colonnes que exemples/dossiers.csv). */
    private function bilan_modele(): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="dossiers.csv"');
        readfile(RACINE . '/exemples/dossiers.csv');
        exit;
    }
}
