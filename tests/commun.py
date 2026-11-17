"""Commun aux tests : vrai serveur PHP (php -S), données temporaires, faux Microsoft (connexion + boîte Graph) et faux Pennylane.

Aucun appel au vrai Microsoft ni au vrai Pennylane : les adresses sont remplacées par celle d'un serveur local (réglages
MCA_AUTORITE, MCA_GRAPH, MCA_PENNYLANE). PHP : variable PHP, sinon « php » du PATH.
"""
import base64
import json
import os
import re
import shutil
import socket
import subprocess
import tempfile
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

import pytest
import requests
from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.asymmetric import padding, rsa

RACINE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PHP = os.environ.get("PHP") or shutil.which("php") or "php"
DOSSIER = tempfile.mkdtemp(prefix="mail-comptes-")
SIGNATURES = DOSSIER + "-signatures"  # faux %APPDATA%\\Microsoft\\Signatures du PC
H = {"X-Outil": "1"}
EN_LIGNE = {"Host": "outils.example.fr", "X-Forwarded-Proto": "https"}
COMPTES = "claire:Claire Moreau,paul:Paul Bernard"


def port_libre():
    s = socket.socket()
    s.bind(("127.0.0.1", 0))
    p = s.getsockname()[1]
    s.close()
    return p


# ---------- Faux Microsoft (connexion OpenID Connect + clés de signature + boîte Graph) ----------
MS = {}
GRAPH = []  # appels à la boîte (faux Graph) : (méthode, chemin, corps JSON)
CLE_MS = rsa.generate_private_key(public_exponent=65537, key_size=2048)  # clé de signature du faux Microsoft
CLIENT_ID = "00000000-aaaa-bbbb-cccc-000000000001"  # application Microsoft fictive
TENANT_CABINET = "11111111-2222-3333-4444-555555555555"
TENANT_PERSONNEL = "9188040d-6c67-4c5b-b112-36a304b66dad"
CLAIMS = '{"access_token":{"capolids":{"essential":true,"values":["p1"]}}}'


def b64u(o):
    return base64.urlsafe_b64encode(o).decode().rstrip("=")


def jwks():
    n = CLE_MS.public_key().public_numbers()
    entier = lambda v: b64u(v.to_bytes((v.bit_length() + 7) // 8, "big"))
    return {"keys": [{"kty": "RSA", "kid": "cle1", "use": "sig", "n": entier(n.n), "e": entier(n.e)}]}


def signer(infos, kid="cle1", cle=None):
    debut = b64u(json.dumps({"alg": "RS256", "kid": kid, "typ": "JWT"}).encode()) + "." + b64u(json.dumps(infos).encode())
    return debut + "." + b64u((cle or CLE_MS).sign(debut.encode(), padding.PKCS1v15(), hashes.SHA256()))


def id_token(adresse):
    tid = TENANT_PERSONNEL if adresse.endswith("@outlook.com") else MS.get("tid", TENANT_CABINET)
    infos = {"aud": MS.get("aud", CLIENT_ID), "iss": f"https://login.microsoftonline.com/{tid}/v2.0", "tid": tid,
             "oid": MS.get("oid", "oid-" + adresse), "preferred_username": adresse, "name": MS.get("nom", adresse.split("@")[0]),
             "iat": int(time.time()), "nbf": int(time.time()), "exp": int(time.time()) + 3600, "nonce": MS.get("nonce", "")}
    if MS.get("mfa"):
        infos["amr"] = ["pwd", "mfa"]
    return signer(infos, cle=MS.get("cle_pirate"))


def code_pour(url):
    # Le vrai Microsoft lie le nonce de la page d'autorisation au code : le faux le reprend de l'adresse.
    MS["nonce"] = parse_qs(urlparse(url).query)["nonce"][0]
    return "c1"


# ---------- Faux Pennylane (API externe : firm/v1 et v2, pagination par curseur) ----------
PL = {"appels": []}  # état du faux Pennylane (remis à zéro par la fixture app)
PL_SOCIETES = {
    501: {"id": 501, "name": "ALPHA EXEMPLE", "siren": "000005017", "legal_form": "SAS", "fiscal_category": "bic_is", "vat_frequency": "monthly"},
    502: {"id": 502, "name": "BETA FICTIVE", "siren": "000005025", "legal_form": "Société civile immobilière", "fiscal_category": "ir",
          "vat_frequency": "yearly"},
}


def pl_reponse(chemin, q, dossier):
    """Réponses du faux Pennylane, même forme que l'API : None = 404."""
    if chemin == "/firm/v1/companies":
        return {"items": list(PL_SOCIETES.values()), "total_pages": 1}
    m = re.match(r"^/firm/v1/companies/(\d+)$", chemin)
    if m:
        return PL_SOCIETES.get(int(m.group(1)))
    if chemin == "/v2/fiscal_years":
        if PL.get("exige_lecture") and PL.get("jeton") != "lecture":  # jeton principal sans « fiscal_years:readonly »
            return 403
        return {"items": [{"start": "2024-01-01", "finish": "2024-12-31", "status": "closed"},
                          {"start": "2025-01-01", "finish": "2025-12-31", "status": "closed"},
                          {"start": "2099-01-01", "finish": "2099-12-31", "status": "open"}], "has_more": False}
    if chemin == "/v2/bank_accounts":  # deux pages : le curseur est suivi
        if q.get("cursor") != ["p2"]:
            return {"items": [{"name": "Compte courant", "balance": "1500.00", "ledger_account": {"id": 77}}], "has_more": True, "next_cursor": "p2"}
        return {"items": [{"name": "Banque Test - Livret - Account - 0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0", "balance": "0"}], "has_more": False}
    if chemin == "/v2/ledger_accounts/77":
        return {"label": "Banque Test - Compte pro"}
    if chemin == "/v2/trial_balance":
        PL["periode"] = (q.get("period_start", [""])[0], q.get("period_end", [""])[0])
        if dossier == "502":
            return {"items": [{"number": "706000", "label": "Loyers", "debits": "0", "credits": "12000"}], "has_more": False}
        return {"items": [{"number": "706000", "label": "Ventes", "debits": "0", "credits": "100000.00"},
                          {"number": "607000", "label": "Achats", "debits": "60000.00", "credits": "0"},
                          {"number": "613200", "label": "Indemnité d'occupation", "debits": "3600.00", "credits": "0"},
                          {"number": "695000", "label": "IS", "debits": "4000.00", "credits": "0"}], "has_more": False}
    return None


class FauxServices(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def repondre(self, code, corps):
        data = json.dumps(corps).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def pennylane(self):
        u = urlparse(self.path)
        jeton = (self.headers.get("Authorization") or "").removeprefix("Bearer ")
        PL["appels"].append((u.path, jeton, self.headers.get("X-Company-Id")))
        if jeton not in ("pl-principal", "pl-lecture"):
            return self.repondre(401, {"error": "Unauthorized"})
        PL["jeton"] = "lecture" if jeton == "pl-lecture" else "principal"
        r = pl_reponse(u.path.removeprefix("/pl"), parse_qs(u.query), self.headers.get("X-Company-Id"))
        if r == 403:
            return self.repondre(403, {"error": "This action requires scope fiscal_years:readonly"})
        return self.repondre(200, r) if r is not None else self.repondre(404, {"error": "Not found"})

    def do_POST(self):
        n = int(self.headers.get("Content-Length") or 0)
        brut = self.rfile.read(n).decode() if n else ""
        chemin = urlparse(self.path).path
        if chemin.startswith("/me/"):
            return self.graph("POST", chemin, json.loads(brut) if brut else None)
        q = parse_qs(brut)
        if chemin.endswith("/token"):
            if MS.get("exige_secret") and q.get("client_secret") != [MS["exige_secret"]]:  # adresse déclarée en « Web » : secret exigé
                return self.repondre(401, {"error": "invalid_client", "error_description": "AADSTS7000218: client_secret required"})
            if MS.get("exige_mfa") and q.get("grant_type") == ["authorization_code"]:  # le cabinet exige Authenticator, pas validé
                MS["exige_mfa"] -= 1
                return self.repondre(400, {"error": "invalid_grant", "error_description": "AADSTS50076: you must use multi-factor authentication",
                                           "claims": CLAIMS})
            if q.get("grant_type") == ["authorization_code"] and q.get("code") == ["c1"] and q.get("code_verifier"):
                MS["redirect_uri"] = q.get("redirect_uri", [""])[0]
                return self.repondre(200, {"access_token": "t", "refresh_token": "r", "expires_in": 3600,
                                           "id_token": id_token(MS.get("adresse", "moi@example.fr"))})
            return self.repondre(400, {"error": "invalid_grant", "error_description": "AADSTS70008: expired"})
        if chemin.endswith("/devicecode"):
            return self.repondre(200, {"device_code": "x"})
        self.repondre(404, {"error": {"message": "inconnu"}})

    def do_GET(self):
        chemin = urlparse(self.path).path
        if chemin.startswith("/pl/"):
            return self.pennylane()
        if chemin == "/discovery/v2.0/keys":  # clés publiques de signature
            return self.repondre(200, jwks())
        if chemin.startswith("/me/"):
            return self.graph("GET", chemin, None)
        self.repondre(404, {"error": {"message": "inconnu"}})

    def do_DELETE(self):
        return self.graph("DELETE", urlparse(self.path).path, None)

    def graph(self, methode, chemin, corps):
        """Faux Graph : la boîte de la personne. Tout ce qui y est écrit est noté dans GRAPH (vérifié par les tests)."""
        if self.headers.get("Authorization") != "Bearer t":
            return self.repondre(401, {"error": {"message": "jeton"}})
        GRAPH.append((methode, chemin, corps))
        if methode == "POST" and chemin == "/me/messages":
            k = sum(1 for m, c, _ in GRAPH if m == "POST" and c == "/me/messages")
            return self.repondre(201, {"id": f"n{k}", "webLink": f"https://outlook.office.com/brouillon/n{k}"})
        if methode == "POST" and chemin.endswith("/attachments"):
            return self.repondre(201, {})
        if methode == "DELETE":
            return self.repondre(204, {})
        if chemin == "/me/mailFolders/sentitems/messages":
            return self.repondre(200, {"value": [
                {"id": "e1", "body": {"content": "<p>Sans signature</p>"}},
                {"id": "e2", "body": {"content": '<div>Bonjour,</div><div id="Signature"><table><tr><td><img src="cid:logo1" width="80"></td>'
                                                 '<td><b style="color:#3f3bd9">Claire MOREAU</b><br>CABINET EXEMPLE</td></tr></table></div>'
                                                 '<div id="divRplyFwdMsg">De : client</div><p>texte cité</p>'}}]})
        if chemin == "/me/messages/e2/attachments":
            return self.repondre(200, {"value": [{"contentId": "logo1", "contentType": "image/png", "contentBytes": "iVBORw0KGgo=", "isInline": True}]})
        self.repondre(404, {"error": {"message": "inconnu"}})


def lancer_php(env, port):
    p = subprocess.Popen([PHP, "-S", f"127.0.0.1:{port}", "-t", "public", "public/routeur.php"], cwd=RACINE, env=env,
                         stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    url = f"http://127.0.0.1:{port}"
    for _ in range(80):
        try:
            requests.get(url + "/api/connexion/options", timeout=1)
            break
        except requests.ConnectionError:
            time.sleep(0.1)
    return p, url


@pytest.fixture(scope="session")
def serveur():
    faux = ThreadingHTTPServer(("127.0.0.1", port_libre()), FauxServices)
    threading.Thread(target=faux.serve_forever, daemon=True).start()
    os.makedirs(SIGNATURES, exist_ok=True)
    adresse = f"http://127.0.0.1:{faux.server_port}"
    env = {k: v for k, v in os.environ.items() if not k.startswith("MCA_") and k != "DEMO"}
    env.update(MCA_DATA=DOSSIER, MCA_SIGNATURES=SIGNATURES, MCA_SANS_REGISTRE="1", MCA_AUTORITE=adresse, MCA_GRAPH=adresse,
               MCA_PENNYLANE=adresse + "/pl", MCA_MICROSOFT_CLIENT_ID=CLIENT_ID, MCA_DOMAINE="cabinet.example.fr", MCA_COMPTES=COMPTES,
               MCA_CABINET_NOM="Cabinet Exemple")
    p, url = lancer_php(env, port_libre())
    yield {"url": url, "env": env}
    p.terminate()
    faux.shutdown()
    shutil.rmtree(DOSSIER, ignore_errors=True)
    shutil.rmtree(SIGNATURES, ignore_errors=True)


class Client(requests.Session):
    def __init__(self, base, en_ligne=False):
        super().__init__()
        self.base = base
        if en_ligne:
            self.headers.update(EN_LIGNE)

    def request(self, methode, url, *a, **k):
        r = super().request(methode, self.base + url, *a, **k)
        for cookie in self.cookies:  # le faux site « en ligne » est servi en http : ses cookies Secure (__Host-) sont renvoyés quand même
            cookie.secure = False
        return r


@pytest.fixture()
def app(serveur):
    for n in os.listdir(DOSSIER):
        p = os.path.join(DOSSIER, n)
        shutil.rmtree(p) if os.path.isdir(p) else os.remove(p)
    for n in os.listdir(SIGNATURES):
        p = os.path.join(SIGNATURES, n)
        shutil.rmtree(p) if os.path.isdir(p) else os.remove(p)
    mdp = json.loads(subprocess.run([PHP, "outils/initialiser.php", "--json"], cwd=RACINE, env=serveur["env"], capture_output=True,
                                    text=True, check=True).stdout)
    MS.clear()
    GRAPH.clear()
    PL.clear()
    PL["appels"] = []
    return {"mdp": mdp, "local": lambda: Client(serveur["url"]), "en_ligne": lambda: Client(serveur["url"], True)}


def connecte(app, qui="claire", en_ligne=False):
    """Connexion par mot de passe (provisoire, créé par outils/initialiser.php)."""
    c = app["en_ligne" if en_ligne else "local"]()
    r = c.post("/api/connexion", headers=H, json={"identifiant": qui, "mot_de_passe": app["mdp"][qui]})
    assert r.status_code == 200, r.text
    return c


def page_microsoft(c, adresse="/api/microsoft/connexion"):
    """Ouvre la page Microsoft depuis le site : l'adresse d'autorisation (vers le faux Microsoft)."""
    r = c.get(adresse, allow_redirects=False)
    assert r.status_code == 302, r.text
    return r.headers["Location"]


def retour_microsoft(c, url, **remplace):
    q = parse_qs(urlparse(url).query)
    r = c.get("/", params={"code": code_pour(url), "state": q["state"][0], "session_state": "x", **remplace})
    vers = re.search(r'url=([^"]+)"', r.text)
    assert r.status_code == 200 and vers, r.text[:200]
    return vers.group(1).replace("&amp;", "&")


def comptes():
    return json.load(open(os.path.join(DOSSIER, "comptes.json"), encoding="utf-8"))


def journal():
    chemin = os.path.join(DOSSIER, "journal-securite.jsonl")
    return [json.loads(l) for l in open(chemin, encoding="utf-8")] if os.path.exists(chemin) else []


def regler(**p):
    chemin = os.path.join(DOSSIER, "parametres.json")
    d = json.load(open(chemin, encoding="utf-8")) if os.path.exists(chemin) else {}
    d.update(p)
    json.dump(d, open(chemin, "w", encoding="utf-8"))


def connecter_boite(c, adresse="cmoreau@cabinet.example.fr"):
    """« Connecter ma boîte » (adresse de retour collée) : la boîte du faux Microsoft est connectée."""
    MS["adresse"] = adresse
    r = c.post("/api/outlook/connexion", headers=H).json()
    etat = parse_qs(urlparse(r["url"]).query)["state"][0]
    r = c.post("/api/outlook/connexion/retour", json={"adresse": f"http://localhost/?code={code_pour(r['url'])}&state={etat}"}, headers=H)
    assert r.status_code == 200, r.text
