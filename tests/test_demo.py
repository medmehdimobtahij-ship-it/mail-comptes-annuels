"""Mode démo (DEMO=1) : il démarre sans compte externe, sert des données fictives, et rien ne sort sur le réseau.

Les adresses de Microsoft et Pennylane pointent vers une sentinelle locale qui note toute requête reçue : elle doit rester muette.
"""
import os
import re
import shutil
import tempfile
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import pytest

from commun import Client, H, lancer_php, port_libre

APPELS = []


class Sentinelle(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def _noter(self):
        APPELS.append((self.command, self.path))
        self.send_response(500)
        self.end_headers()

    do_GET = do_POST = do_PATCH = do_DELETE = _noter


@pytest.fixture(scope="module")
def demo():
    s = ThreadingHTTPServer(("127.0.0.1", port_libre()), Sentinelle)
    threading.Thread(target=s.serve_forever, daemon=True).start()
    donnees = tempfile.mkdtemp(prefix="data-demo-")
    adresse = f"http://127.0.0.1:{s.server_port}"
    env = {k: v for k, v in os.environ.items() if not k.startswith("MCA_")}
    env.update(DEMO="1", MCA_DATA=donnees, MCA_AUTORITE=adresse, MCA_GRAPH=adresse, MCA_PENNYLANE=adresse, MCA_MICROSOFT_CLIENT_ID="cid-test")
    p, url = lancer_php(env, port_libre())
    yield {"url": url, "donnees": donnees}
    p.terminate()
    s.shutdown()
    shutil.rmtree(donnees, ignore_errors=True)


def test_demo_demarre_avec_des_donnees_fictives_et_rien_ne_sort(demo):
    c = Client(demo["url"])
    assert c.get("/").status_code == 200  # pas de redirection vers localhost : l'adresse affichée par demo.bat marche
    assert c.get("/api/connexion/options").json() == {"microsoft": False, "mot_de_passe": True, "demo": True}
    assert c.post("/api/connexion", headers=H, json={"identifiant": "demo", "mot_de_passe": "faux"}).status_code == 401
    assert c.post("/api/connexion", headers=H, json={"identifiant": "demo", "mot_de_passe": "demo"}).status_code == 200
    d = c.get("/api/bilan").json()
    assert d["demo"] is True and d["pennylane"] is True and d["erreur"] == "" and len(d["dossiers"]) == 9
    noms = [x["nom"] for x in d["dossiers"]]
    assert "ATELIER DUPAIN" in noms and "TRANSPORTS FILANTS" in noms
    for x in d["dossiers"]:
        e = c.post("/api/bilan/lire", headers=H, json={"id": x["id"]}).json()
        assert e["avertissement"] is None, x
        siren = next((i[6:] for i in e["indications"]["infos"] if i.startswith("SIREN ")), "")
        assert siren.startswith("000") and len(siren) == 9, siren  # SIREN fictifs, jamais attribués
        mail = e["valeurs"]["email"]
        assert mail == "" or re.search(r"@example\.(com|fr)$", mail), mail
        if mail:
            m = c.post("/api/bilan/apercu", headers=H, json={"valeurs": e["valeurs"]}).json()
            assert m["objet"].startswith("Comptes annuels ") and "manques" not in m
            b = c.post("/api/bilan/brouillon", headers=H, json={"valeurs": e["valeurs"]}).json()
            assert b["demo"] is True and "Mode démo" in b["message"]  # envoi simulé
            assert c.post("/api/bilan/eml", headers=H, json={"valeurs": e["valeurs"]}).status_code == 200
    for chemin in ("/api/outlook/connexion", "/api/outlook/verifier", "/api/pennylane/jetons", "/api/signature/outlook", "/api/securite/mode"):
        r = c.post(chemin, headers=H, json={})
        assert r.status_code == 409 and r.json()["erreur"].startswith("Mode démo :") and "désactivée ici" in r.json()["erreur"], chemin
    assert "indisponible" in c.get("/api/microsoft/connexion", allow_redirects=False).text
    assert c.get("/api/microsoft/lier", allow_redirects=False).status_code == 409
    assert APPELS == []  # aucune requête vers Microsoft ni Pennylane
    assert os.path.exists(os.path.join(demo["donnees"], "cabinet", "liste.json"))
