"""Connexion et sécurité, par HTTP, contre le vrai serveur PHP.

Données dans un dossier temporaire (réglage MCA_DATA, jamais data/). Microsoft est remplacé par un faux serveur local
(jetons d'identité RS256 signés ici) : aucun appel au vrai Microsoft. « En ligne » est simulé par l'en-tête Host (et
X-Forwarded-Proto: https) : le serveur décide « sur ce PC » d'après le serveur intégré de PHP, l'hôte demandé et l'adresse IP.
"""
import json
import os
import re
import time
from urllib.parse import parse_qs, unquote, urlparse

from cryptography.hazmat.primitives.asymmetric import rsa

from commun import (CLAIMS, CLIENT_ID, DOSSIER, H, MS, TENANT_CABINET, code_pour, comptes, connecte, journal, page_microsoft, regler,
                    retour_microsoft)

CLAIRE = {"adresse": "cmoreau@cabinet.example.fr", "nom": "Claire Moreau"}
PAUL = {"adresse": "pbernard@cabinet.example.fr", "nom": "Paul Bernard"}


def test_mot_de_passe_en_local_microsoft_en_ligne(app):
    c = app["local"]()
    o = c.get("/api/connexion/options").json()
    assert o == {"microsoft": True, "mot_de_passe": True, "demo": False}
    assert c.post("/api/connexion", headers=H, json={"identifiant": "claire"}).status_code == 401  # sans mot de passe : refusé
    assert c.post("/api/connexion", json={"identifiant": "claire", "mot_de_passe": app["mdp"]["claire"]}).status_code == 403  # sans en-tête
    r = c.post("/api/connexion", headers=H, json={"identifiant": "claire", "mot_de_passe": app["mdp"]["claire"]})
    assert r.status_code == 200 and r.json()["methode"] == "mot_de_passe"
    assert c.get("/api/moi").json() == {"identifiant": "claire", "nom": "Claire Moreau", "methode": "mot_de_passe", "mfa": None}

    e = app["en_ligne"]()  # en ligne, sans réglage : Microsoft avec double authentification
    assert e.get("/api/connexion/options").json() == {"microsoft": True, "mot_de_passe": False, "demo": False}
    r = e.post("/api/connexion", headers=H, json={"identifiant": "claire", "mot_de_passe": app["mdp"]["claire"]})
    assert r.status_code == 403 and r.json()["microsoft"] is True and e.get("/api/moi").status_code == 401
    lan = app["local"]()  # autre PC du réseau (http) : mot de passe (Microsoft exige HTTPS)
    lan.headers["Host"] = "192.168.1.20:8090"
    assert lan.get("/api/connexion/options").json()["mot_de_passe"] is True
    regler(ModeConnexion="")  # même en ligne, « Microsoft ou mot de passe » réglé : le mot de passe marche
    assert app["en_ligne"]().post("/api/connexion", headers=H, json={"identifiant": "paul", "mot_de_passe": app["mdp"]["paul"]}).status_code == 200


def test_sans_application_microsoft_mot_de_passe_partout(app, serveur):
    """Cabinet sans application Microsoft Entra : connexion par mot de passe, même en ligne ; Outlook se dit « non configuré »."""
    regler(MessagerieClientId="")
    import subprocess
    from commun import PHP, RACINE
    env = dict(serveur["env"], MCA_MICROSOFT_CLIENT_ID="")
    code = ("require 'src/noyau.php'; require 'src/textes.php'; require 'src/comptes.php'; require 'src/outlook.php'; require 'src/securite.php';"
            "$_SERVER['HTTP_HOST']='outils.example.fr'; $_SERVER['HTTPS']='on'; echo json_encode([connexion_microsoft_disponible(), mode_connexion()]);")
    r = subprocess.run([PHP, "-r", code], cwd=RACINE, env=env, capture_output=True, text=True, check=True)
    assert json.loads(r.stdout) == [False, ""]


def test_connexion_microsoft_avec_et_sans_double_authentification(app):
    c = app["en_ligne"]()
    MS.update(**CLAIRE, mfa=False)
    url = page_microsoft(c)
    q = parse_qs(urlparse(url).query)
    assert q["code_challenge_method"] == ["S256"] and len(q["nonce"][0]) == 32 and "prompt" not in q and "claims" not in q
    assert q["redirect_uri"] == ["https://outils.example.fr"] and q["client_id"] == [CLIENT_ID]
    assert retour_microsoft(c, url) == "/?microsoft=mfa" and c.get("/api/moi").status_code == 401  # mode par défaut en ligne
    assert MS["redirect_uri"] == "https://outils.example.fr"

    MS["mfa"] = True
    r = c.get("/api/microsoft/connexion", allow_redirects=False)
    assert "__Host-mail_comptes_flux=" in r.headers["Set-Cookie"] and "secure" in r.headers["Set-Cookie"].lower()
    assert retour_microsoft(c, r.headers["Location"]) == "/"
    assert c.get("/api/moi").json() == {"identifiant": "claire", "nom": "Claire Moreau", "methode": "microsoft", "mfa": True}
    assert any(k.name == "__Host-mail_comptes" for k in c.cookies)
    assert c.get("/api/outlook").json()["adresse"] == "cmoreau@cabinet.example.fr"  # boîte Outlook connectée du même coup
    assert comptes()["claire"]["microsoft"]["adresse"] == "cmoreau@cabinet.example.fr"  # lié tout seul
    s = c.get("/api/securite").json()
    assert s["mode"] == "microsoft_mfa" and s["session"] == {"methode": "microsoft", "mfa": True}

    MS.update(**PAUL)
    p = app["en_ligne"]()
    assert retour_microsoft(p, page_microsoft(p)) == "/" and p.get("/api/moi").json()["identifiant"] == "paul"
    MS["nom"] = "Autre Nom"  # ensuite, reconnu par son identifiant Microsoft, quel que soit le nom
    p = app["en_ligne"]()
    assert retour_microsoft(p, page_microsoft(p)) == "/"


def test_compte_non_lie_ou_etranger_refuse(app):
    MS["mfa"] = True
    for adresse, nom in (("accueil@cabinet.example.fr", "Accueil"),  # cabinet, mais aucun compte à ce nom
                         ("paul.bernard@autre.example.com", "Paul Bernard"),  # pas le domaine du cabinet
                         ("quelquun@outlook.com", "Claire Moreau")):  # compte personnel
        MS.update(adresse=adresse, nom=nom)
        c = app["en_ligne"]()
        assert retour_microsoft(c, page_microsoft(c)) == "/?microsoft=inconnu", adresse
        assert c.get("/api/moi").status_code == 401
    MS.update(**CLAIRE)
    m = app["en_ligne"]()
    assert retour_microsoft(m, page_microsoft(m)) == "/"
    MS.update(**PAUL, tid="99999999-0000-0000-0000-000000000000")
    c = app["en_ligne"]()  # bonne adresse, mais une autre organisation Microsoft que celle du cabinet
    assert retour_microsoft(c, page_microsoft(c)) == "/?microsoft=inconnu"
    assert "microsoft" not in comptes()["paul"]
    p = connecte(app, "paul")  # Paul ne peut pas lier le compte Microsoft de Claire
    MS.update(adresse=CLAIRE["adresse"], tid=TENANT_CABINET)
    assert retour_microsoft(p, page_microsoft(p, "/api/microsoft/lier")) == "/?microsoft=deja"


def test_jetons_microsoft_falsifies_refuses(app):
    MS.update(**CLAIRE, mfa=True)
    essais = {
        "cle_pirate": rsa.generate_private_key(public_exponent=65537, key_size=2048),  # signé par quelqu'un d'autre que Microsoft
        "aud": "autre-application",  # jeton émis pour une autre application
    }
    for k, v in essais.items():
        MS[k] = v
        c = app["en_ligne"]()
        assert retour_microsoft(c, page_microsoft(c)) == "/?microsoft=erreur", k
        assert c.get("/api/moi").status_code == 401, k
        MS.pop(k)
    c = app["en_ligne"]()  # nonce d'une autre connexion (jeton rejoué)
    url = page_microsoft(c)
    MS["nonce"] = "0" * 32
    r = c.get("/", params={"code": "c1", "state": parse_qs(urlparse(url).query)["state"][0]})
    assert "microsoft=erreur" in r.text and c.get("/api/moi").status_code == 401
    pirate, victime = app["en_ligne"](), app["en_ligne"]()  # retour ouvert dans un autre navigateur (cookie de liaison absent)
    url = page_microsoft(pirate)
    assert retour_microsoft(victime, url) == "/?microsoft=expire" and victime.get("/api/moi").status_code == 401
    c = app["en_ligne"]()  # usage unique : le même retour ne sert pas deux fois
    url = page_microsoft(c)
    lien = dict(c.cookies)
    assert retour_microsoft(c, url) == "/"
    c2 = app["en_ligne"]()
    c2.cookies.update(lien)
    r = c2.get("/", params={"code": "c1", "state": parse_qs(urlparse(url).query)["state"][0]})
    assert "microsoft=" not in r.text and c2.get("/api/moi").status_code == 401  # plus attendu : simple page du site
    assert not comptes()["paul"].get("microsoft")


def test_double_authentification_exigee_par_microsoft_relance_une_fois(app):
    MS.update(**CLAIRE, mfa=True, exige_mfa=1)
    c = app["en_ligne"]()
    u = page_microsoft(c)
    r = c.get("/", params={"code": code_pour(u), "state": parse_qs(urlparse(u).query)["state"][0]}, allow_redirects=False)
    assert r.status_code == 302  # AADSTS50076 : retour sur la page Microsoft avec son défi
    q = parse_qs(urlparse(r.headers["Location"]).query)
    assert q["claims"] == [CLAIMS] and "amr_values" not in q and "prompt" not in q
    assert retour_microsoft(c, r.headers["Location"]) == "/" and c.get("/api/moi").json()["mfa"] is True

    MS["exige_mfa"] = 2  # même après l'avoir exigé : message clair, pas de boucle
    c = app["en_ligne"]()
    u = page_microsoft(c)
    relance = c.get("/", params={"code": code_pour(u), "state": parse_qs(urlparse(u).query)["state"][0]}, allow_redirects=False).headers["Location"]
    assert retour_microsoft(c, relance) == "/?microsoft=mfa" and c.get("/api/moi").status_code == 401


def test_mode_de_connexion_et_garde_fous(app):
    loc = connecte(app)
    assert loc.get("/api/securite").json()["mode"] == ""  # sur ce PC : mot de passe par défaut
    assert loc.post("/api/securite/mode", json={"mode": "microsoft"}, headers=H).status_code == 422  # pas lié : se bloquerait
    assert loc.post("/api/securite/mode", json={"mode": "microsoft"}).status_code == 403  # POST sans en-tête X-Outil
    MS.update(**CLAIRE, mfa=False)
    assert retour_microsoft(loc, page_microsoft(loc, "/api/microsoft/lier")) == "/?microsoft=lie"
    r = loc.post("/api/securite/mode", json={"mode": "microsoft_mfa"}, headers=H)
    assert r.status_code == 422 and "Authenticator" in r.json()["champs"]["mode"]  # pas soi-même connecté avec la double auth.
    assert loc.post("/api/securite/mode", json={"mode": "microsoft"}, headers=H).json()["mode"] == "microsoft"
    r = app["local"]().post("/api/connexion", headers=H, json={"identifiant": "paul", "mot_de_passe": app["mdp"]["paul"]})
    assert r.status_code == 403  # plus de mot de passe
    assert loc.post("/api/securite/delier", headers=H).status_code == 422  # délier en mode Microsoft : se bloquerait
    MS["mfa"] = True
    m = app["local"]()
    assert retour_microsoft(m, page_microsoft(m)) == "/"
    assert m.post("/api/securite/mode", json={"mode": "microsoft_mfa"}, headers=H).json()["mode"] == "microsoft_mfa"
    assert m.post("/api/securite/mode", json={"mode": ""}, headers=H).json()["mode"] == ""
    assert app["local"]().get("/api/connexion/options").json()["mot_de_passe"] is True


def test_mot_de_passe_change_regle_cnil(app):
    c = connecte(app)
    r = c.post("/api/mot-de-passe", headers=H, json={"ancien": app["mdp"]["claire"], "nouveau": "court"})
    assert r.status_code == 422 and "12 caractères" in r.json()["champs"]["nouveau"]
    r = c.post("/api/mot-de-passe", headers=H, json={"ancien": app["mdp"]["claire"], "nouveau": "Moreau-Claire-2026!"})
    assert r.status_code == 422 and "nom" in r.json()["champs"]["nouveau"]
    autre = connecte(app)
    assert c.post("/api/mot-de-passe", headers=H, json={"ancien": app["mdp"]["claire"], "nouveau": "Bilan-Annuel-2026!x"}).json() == {"ok": True}
    assert c.get("/api/moi").status_code == 200 and autre.get("/api/moi").status_code == 401  # les autres sessions sont fermées
    assert "Bilan-Annuel" not in open(os.path.join(DOSSIER, "comptes.json"), encoding="utf-8").read()  # haché, jamais en clair


def test_sessions_durcies_et_en_tetes(app):
    MS.update(**CLAIRE, mfa=True)
    c = app["en_ligne"]()
    assert retour_microsoft(c, page_microsoft(c)) == "/"
    autre = app["en_ligne"]()
    assert retour_microsoft(autre, page_microsoft(autre)) == "/"
    assert c.post("/api/securite/fermer-sessions", headers=H).json() == {"ok": True}
    assert c.get("/api/moi").status_code == 200 and autre.get("/api/moi").status_code == 401
    vole = app["en_ligne"]()  # cookie de session volé, utilisé par un autre navigateur (la session est alors fermée)
    vole.cookies.update(c.cookies)
    assert vole.get("/api/moi", headers={"User-Agent": "Pirate/1.0"}).status_code == 401

    c = app["en_ligne"]()
    assert retour_microsoft(c, page_microsoft(c)) == "/"
    sid = next(k.value for k in c.cookies if k.name == "__Host-mail_comptes")  # 2 h sans activité : session fermée
    chemin = os.path.join(DOSSIER, "sessions", "sess_" + unquote(sid))
    contenu = open(chemin, encoding="utf-8").read()
    assert re.search(r"\bvu\|i:\d+;", contenu)
    open(chemin, "w", encoding="utf-8").write(re.sub(r"\bvu\|i:\d+;", f"vu|i:{int(time.time()) - 2 * 3600 - 5};", contenu))
    assert c.get("/api/moi").status_code == 401

    page = app["en_ligne"]().get("/")
    for k in ("Permissions-Policy", "Cross-Origin-Opener-Policy", "Cross-Origin-Resource-Policy", "Strict-Transport-Security"):
        assert k in page.headers, k
    assert "base-uri 'none'" in page.headers["Content-Security-Policy"] and "frame-ancestors 'none'" in page.headers["Content-Security-Policy"]
    assert page.headers["X-Frame-Options"] == "DENY" and "X-Powered-By" not in page.headers


def test_essais_bloques_et_journal_de_securite(app):
    regler(ModeConnexion="")  # en ligne, mode « Microsoft ou mot de passe » : mot de passe de secours
    c = app["en_ligne"]()
    for _ in range(5):
        assert c.post("/api/connexion", headers=H, json={"identifiant": "paul", "mot_de_passe": "x"}).status_code == 401
    assert c.post("/api/connexion", headers=H, json={"identifiant": "paul", "mot_de_passe": app["mdp"]["paul"]}).status_code == 429
    json.dump({"compte:claire": [int(time.time())] * 10}, open(os.path.join(DOSSIER, "essais.json"), "w"))
    assert app["en_ligne"]().post("/api/connexion", headers=H, json={"identifiant": "claire", "mot_de_passe": app["mdp"]["claire"]}).status_code == 429
    os.remove(os.path.join(DOSSIER, "essais.json"))

    regler(ModeConnexion="microsoft_mfa")
    MS.update(adresse="intrus@cabinet.example.fr", nom="Intrus", mfa=True)
    i = app["en_ligne"]()
    retour_microsoft(i, page_microsoft(i))
    MS.update(**CLAIRE, mfa=False)
    m = app["en_ligne"]()
    assert retour_microsoft(m, page_microsoft(m)) == "/?microsoft=mfa"
    MS["mfa"] = True
    assert retour_microsoft(m, page_microsoft(m)) == "/"
    with open(os.path.join(DOSSIER, "journal-securite.jsonl"), "a", encoding="utf-8") as f:  # ligne de plus de 12 mois
        f.write(json.dumps({"t": "2024-01-01T10:00:00+01:00", "evt": "connexion", "compte": "claire", "ip": "192.0.2.4"}) + "\n")
    evts = [(l["evt"], l.get("compte"), l.get("motif")) for l in journal()]
    assert ("connexion_refusee", "paul", None) in evts and ("connexion_bloquee", "paul", None) in evts
    assert ("connexion_refusee", None, "compte non lié") in evts
    assert ("connexion_refusee", "claire", "sans double authentification") in evts
    assert ("microsoft_lie", "claire", "première connexion") in evts
    assert app["mdp"]["paul"] not in open(os.path.join(DOSSIER, "journal-securite.jsonl"), encoding="utf-8").read()
    j = m.get("/api/securite").json()["journal"]
    assert j[0]["evt"] == "connexion" and j[0]["methode"] == "microsoft" and j[0]["mfa"] is True and j[0]["ip"] == "127.0.0.1"
    assert not any(x["ip"] == "192.0.2.4" for x in j)  # plus de 12 mois : plus montré
    assert m.post("/api/deconnexion", headers=H).json() == {"ok": True} and journal()[-1]["evt"] == "deconnexion"


def test_connecter_ma_boite_verifie_le_jeton_et_lie_le_compte(app):
    """« Connecter ma boîte » (adresse de retour collée) : jeton d'identité vérifié, compte Microsoft du cabinet lié du même coup."""
    c = connecte(app, "paul")
    MS.update(adresse=PAUL["adresse"], aud="autre-application")
    r = c.post("/api/outlook/connexion", headers=H).json()
    etat = parse_qs(urlparse(r["url"]).query)["state"][0]
    assert r["automatique"] is True and parse_qs(urlparse(r["url"]).query)["prompt"] == ["select_account"]
    assert c.post("/api/outlook/connexion/retour", json={"adresse": f"http://localhost/?code={code_pour(r['url'])}&state={etat}"}, headers=H).status_code == 409
    MS.pop("aud")
    r = c.post("/api/outlook/connexion", headers=H).json()
    etat = parse_qs(urlparse(r["url"]).query)["state"][0]
    assert c.post("/api/outlook/connexion/retour", json={"adresse": f"http://localhost/?code={code_pour(r['url'])}&state={etat}"},
                  headers=H).json() == {"ok": True, "adresse": PAUL["adresse"]}
    assert comptes()["paul"]["microsoft"]["adresse"] == PAUL["adresse"]
    assert '"t"' not in open(os.path.join(DOSSIER, "outlook", "paul.json"), encoding="utf-8").read()  # jetons chiffrés


def test_secret_application_web_en_ligne(app):
    """Adresse du site en plateforme « Web » : le serveur échange le code avec le secret de l'application, gardé chiffré."""
    loc = connecte(app)
    assert loc.post("/api/outlook/reglages", json={"secret": " Abc8Q~secret-exemple_42 "}, headers=H).json()["secret"] is True
    assert "Abc8Q" not in open(os.path.join(DOSSIER, "parametres.json"), encoding="utf-8").read()  # jamais en clair
    MS.update(**CLAIRE, mfa=True, exige_secret="Abc8Q~secret-exemple_42")
    c = app["en_ligne"]()
    assert retour_microsoft(c, page_microsoft(c)) == "/" and c.get("/api/moi").json()["identifiant"] == "claire"
    assert loc.post("/api/outlook/reglages", json={"secret": ""}, headers=H).json()["secret"] is False  # retiré : Microsoft refuse
    c = app["en_ligne"]()
    assert retour_microsoft(c, page_microsoft(c)) == "/?microsoft=erreur" and c.get("/api/moi").status_code == 401
