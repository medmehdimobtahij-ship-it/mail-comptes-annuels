"""Le mail des comptes annuels de bout en bout, par HTTP, contre le vrai serveur PHP.

Deux chemins, tous deux réels : sans aucune intégration (clients importés d'un fichier ou saisis, mail ouvert dans la
messagerie ou téléchargé en .eml), et avec Pennylane (jeton du cabinet) et Outlook (brouillon), contre les faux serveurs.
"""
import base64
import io
import json
import os
import zipfile

from commun import DOSSIER, GRAPH, H, PL, RACINE, connecte, connecter_boite

LOGO = "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=="


def importer(c, octets):
    return c.post("/api/bilan/import", headers=H, json={"nom": "dossiers.csv", "contenu": base64.b64encode(octets).decode()})


def par_nom(c, nom):
    return next(x for x in c.get("/api/bilan").json()["dossiers"] if x["nom"] == nom)


def lire(c, id_):
    r = c.post("/api/bilan/lire", headers=H, json={"id": id_})
    assert r.status_code == 200, r.text
    return r.json()


def fiches():
    return json.load(open(os.path.join(DOSSIER, "cabinet", "liste.json"), encoding="utf-8"))


# ---------- Sans Pennylane ni Outlook ----------
def test_sans_integration_import_du_fichier_d_exemple(app):
    c = connecte(app)
    d = c.get("/api/bilan").json()
    assert d["pennylane"] is False and d["erreur"] == "" and d["dossiers"] == [] and d["boite"] == ""
    p = c.get("/api/parametres").json()
    assert p["pennylane"]["relie"] is False and p["outlook"]["adresse"] == "" and p["cabinet"] == "Cabinet Exemple"
    r = importer(c, open(os.path.join(RACINE, "exemples", "dossiers.csv"), "rb").read())
    assert r.json() == {"ajoutes": 4, "mis_a_jour": 0, "erreurs": []}, r.text
    d = c.get("/api/bilan").json()
    assert [x["nom"] for x in d["dossiers"]] == ["ATELIER DU CHÊNE FICTIF", "CAFÉ DE LA DÉMO", "PLOMBERIE EXEMPLE", "SCI DES TILLEULS FICTIVE"]
    assert {x["source"] for x in d["dossiers"]} == {"import"}

    e = lire(c, par_nom(c, "ATELIER DU CHÊNE FICTIF")["id"])
    v = e["valeurs"]
    assert e["avertissement"] is None and e["source"] == "le fichier importé" and not PL["appels"]  # aucun appel à Pennylane
    assert (v["prenom"], v["genre"], v["tu"], v["email"], v["date_cloture"]) == ("Alice", "F", False, "alice.martin@example.fr", "2025-12-31")
    assert (v["statut_is"], v["montant_is"], v["resultat_net"], v["alimenter_compte"], v["banque"]) == ("a_payer", "3 980", "24 310,55", True, "Banque Exemple")
    assert e["indications"]["tresorerie"]["texte"].startswith("Trésorerie actuelle")
    plomberie = lire(c, par_nom(c, "PLOMBERIE EXEMPLE")["id"])["valeurs"]
    assert plomberie["statut_is"] == "creance" and plomberie["date_cloture"] == "2025-06-30" and plomberie["cc"] == "compta@example.com"
    assert "2024-2025" in plomberie["missions_facture"]  # exercice décalé
    sci = lire(c, par_nom(c, "SCI DES TILLEULS FICTIVE")["id"])["valeurs"]
    assert sci["is_soumis"] is False and sci["docs_juridiques"] is False
    cafe = lire(c, par_nom(c, "CAFÉ DE LA DÉMO")["id"])["valeurs"]
    assert cafe["indemnite"] is True and cafe["montant_indemnite"] == "4 790" and cafe["tva_annuelle"] is True

    # Même fichier corrigé : mis à jour (jamais en double) ; une ligne illisible est dite, les autres passent
    csv = ("societe;email;impot_societes;cloture\nATELIER DU CHÊNE FICTIF;alice@example.fr;4 100;31/12/2025\n"
           "LIGNE FAUSSE;pas-un-mail;12x;32/13/2025\n;;;\n").encode("utf-8")
    r = importer(c, csv).json()
    assert r["ajoutes"] == 0 and r["mis_a_jour"] == 1 and len(r["erreurs"]) == 1 and "Ligne 3 (LIGNE FAUSSE)" in r["erreurs"][0]
    assert "email" in r["erreurs"][0] and "impot_societes" in r["erreurs"][0] and "cloture" in r["erreurs"][0]
    v = lire(c, par_nom(c, "ATELIER DU CHÊNE FICTIF")["id"])["valeurs"]
    assert v["email"] == "alice@example.fr" and v["montant_is"] == "4 100" and v["prenom"] == "Alice"  # cellule vide : rien remplacé
    assert importer(c, b"nom;ville\nX;Y\n").status_code == 422  # sans colonne « societe »


def test_import_excel(app):
    c = connecte(app)
    feuille = ('<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
               '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c></row>'
               '<row r="2"><c r="A2" t="s"><v>3</v></c><c r="B2"><v>46022</v></c><c r="C2" t="inlineStr"><is><t>12 500,40</t></is></c></row>'
               '</sheetData></worksheet>')
    partages = ('<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Société</t></si><si><t>Date de clôture</t></si>'
                '<si><t>Résultat net</t></si><si><r><t>EXCEL </t></r><r><t>FICTIF</t></r></si></sst>')
    f = io.BytesIO()
    with zipfile.ZipFile(f, "w") as z:
        z.writestr("xl/worksheets/sheet1.xml", feuille)
        z.writestr("xl/sharedStrings.xml", partages)
    assert importer(c, f.getvalue()).json() == {"ajoutes": 1, "mis_a_jour": 0, "erreurs": []}
    v = lire(c, par_nom(c, "EXCEL FICTIF")["id"])["valeurs"]
    assert v["date_cloture"] == "2025-12-31" and v["resultat_net"] == "12 500,40"


def test_dossier_saisi_a_la_main_bloque_puis_complete(app):
    c = connecte(app)
    assert c.post("/api/bilan/dossier", headers=H, json={"nom": " "}).status_code == 422
    id_ = c.post("/api/bilan/dossier", headers=H, json={"nom": "NOUVELLE SOCIÉTÉ"}).json()["id"]
    assert c.post("/api/bilan/dossier", headers=H, json={"nom": "nouvelle société"}).json()["id"] == id_  # jamais en double
    e = lire(c, id_)
    v = e["valeurs"]
    assert v["societe"] == "NOUVELLE SOCIÉTÉ" and v["date_cloture"] == "" and e["indications"]["resultat"].startswith("Résultat : pas encore connu")
    assert c.post("/api/bilan/apercu", headers=H, json={"valeurs": v}).json()["manques"] == ["prénom du client", "date de clôture", "missions facturées"]
    assert c.post("/api/bilan/eml", headers=H, json={"valeurs": v}).status_code == 422  # rien ne part tant qu'il manque
    v.update(prenom="Marie", email="marie@example.com", date_cloture="2025-12-31", is_soumis=True, montant_is="1 000", alimenter_compte=False)
    c.post("/api/bilan/saisies", headers=H, json={"id": id_, "valeurs": v})
    v = lire(c, id_)["valeurs"]  # la clôture saisie entre dans la fiche : missions de l'exercice proposées
    assert v["date_cloture"] == "2025-12-31" and v["missions_facture"].startswith("la tenue comptable 2025,")
    m = c.post("/api/bilan/apercu", headers=H, json={"valeurs": v}).json()
    assert m["objet"] == "Comptes annuels NOUVELLE SOCIÉTÉ, exercice clos au 31 décembre 2025" and "Bonjour Marie," in m["texte"]
    assert c.post("/api/bilan/dossier/retirer", headers=H, json={"id": id_}).json() == {"ok": True}
    assert c.get("/api/bilan").json()["dossiers"] == []


def test_coordonnees_dans_la_fiche_et_modifications_par_dossier(app):
    c = connecte(app)
    importer(c, open(os.path.join(RACINE, "exemples", "dossiers.csv"), "rb").read())
    id_ = par_nom(c, "ATELIER DU CHÊNE FICTIF")["id"]
    v = lire(c, id_)["valeurs"]
    v.update(email="direction@example.fr", cc="associe@example.com", tu=True, genre="H", nom_contact="Martin-Durand", montant_is="4 200")
    r = c.post("/api/bilan/saisies", headers=H, json={"id": id_, "valeurs": v}).json()
    assert r["modifie"] is True  # le montant de l'IS est une modification de ce mail
    f = next(x for x in fiches() if x["pennylane_id"] == id_)
    assert f["contact"]["email"] == "direction@example.fr" and f["contact"]["civilite"] == "M" and f["contact"]["nom"] == "Martin-Durand"
    assert f["cc"] == "associe@example.com" and f["formule"] == "Tu"  # les coordonnées vont dans la fiche du client
    assert json.load(open(os.path.join(DOSSIER, "bilan", "saisies.json"), encoding="utf-8")) == {id_: {"montant_is": "4 200"}}
    e = c.post("/api/bilan/reinitialiser", headers=H, json={"id": id_}).json()
    assert e["modifie"] is False and e["valeurs"]["montant_is"] == "3 980" and e["valeurs"]["email"] == "direction@example.fr"  # la fiche reste
    r = c.post("/api/bilan/parametres", headers=H, json={"Copie": "secretariat@example.fr, pas-une-adresse"})
    assert r.status_code == 422
    assert c.post("/api/bilan/parametres", headers=H, json={"Copie": "secretariat@example.fr", "Missions": "la mission {exercice}"}).json()["Missions"] == "la mission {exercice}"
    e = lire(c, id_)
    assert e["valeurs"]["missions_facture"] == "la mission 2025"  # règle du cabinet appliquée aux nouveaux mails
    m = c.post("/api/bilan/apercu", headers=H, json={"valeurs": e["valeurs"]}).json()
    assert m["cc"] == "associe@example.com, secretariat@example.fr" and m["a"] == "direction@example.fr"


def test_sans_outlook_eml_et_mail_envoye_coche_a_la_main(app):
    c = connecte(app)
    importer(c, open(os.path.join(RACINE, "exemples", "dossiers.csv"), "rb").read())
    c.post("/api/parametres/cabinet", headers=H, json={"cabinet": "Cabinet Martin & Associés"})
    id_ = par_nom(c, "CAFÉ DE LA DÉMO")["id"]
    v = lire(c, id_)["valeurs"]
    r = c.post("/api/bilan/brouillon", headers=H, json={"valeurs": v})
    assert r.status_code == 409 and r.json()["action"]["type"] == "reconnecter"  # Outlook non connecté : dit, jamais bloquant
    r = c.post("/api/bilan/eml", headers=H, json={"valeurs": v})
    assert r.status_code == 200 and r.headers["Content-Type"].startswith("message/rfc822") and ".eml" in r.headers["Content-Disposition"]
    eml = r.text
    assert "X-Unsent: 1" in eml and "To: david.petit@example.com" in eml
    html = base64.b64decode(eml.split("text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n")[1].split("--")[0]).decode()
    assert "Case 4BE" in html and "Claire Moreau" in html and "Cabinet Martin &amp; Associés" in html  # signature simple du cabinet
    assert c.post("/api/bilan/envoye", headers=H, json={"id": id_, "etat": True}).json()["envoye"]
    d = c.get("/api/bilan").json()
    assert d["chiffres"]["envoyes"] == 1 and par_nom(c, "CAFÉ DE LA DÉMO")["envoye"]
    assert c.post("/api/bilan/envoye", headers=H, json={"id": id_, "etat": False}).json()["envoye"] is None
    assert c.get("/api/bilan/modele.csv").text.startswith("societe;siren;")


# ---------- Avec Pennylane et Outlook (faux serveurs) ----------
def test_avec_pennylane_dossiers_et_chiffres_lus_par_l_api(app):
    c = connecte(app)
    assert c.post("/api/pennylane/jetons", headers=H, json={"principal": "pl-principal", "lecture": "pl-lecture"}).json() == {"ok": True}
    assert "pl-principal" not in open(os.path.join(DOSSIER, "pennylane.json"), encoding="utf-8").read()  # chiffré
    assert c.get("/api/parametres").json()["pennylane"] == {"principal": "pl-p…ipal", "lecture": "pl-l…ture", "relie": True, "simule": False}
    # Fiche importée pour le dossier Pennylane 501 : le contact (que l'API ne donne pas) vient du fichier
    importer(c, "societe;id_pennylane;prenom;email;civilite\nALPHA EXEMPLE;501;Jean;jean@example.com;M\n".encode())
    d = c.get("/api/bilan").json()
    assert d["pennylane"] is True and [(x["id"], x["nom"]) for x in d["dossiers"]] == [("501", "ALPHA EXEMPLE"), ("502", "BETA FICTIVE")]
    PL["appels"].clear()
    e = lire(c, "501")
    v = e["valeurs"]
    assert e["source"] == "Pennylane" and e["avertissement"] is None
    assert ("/pl/v2/trial_balance", "pl-principal", "501") in PL["appels"] and PL["periode"] == ("2025-01-01", "2025-12-31")
    assert (v["prenom"], v["email"], v["genre"]) == ("Jean", "jean@example.com", "H")
    assert (v["resultat_net"], v["montant_is"], v["statut_is"]) == ("32 400", "4 000", "a_payer")  # 100 000 - 60 000 - 3 600 - 4 000
    assert v["indemnite"] is True and v["montant_indemnite"] == "3 600" and v["alimenter_compte"] is True and v["banque"] == "Banque Test"
    assert [x["nom"] for x in e["indications"]["comptes"]] == ["Banque Test (Compte pro)", "Banque Test (Livret)"]  # identifiant technique retiré
    assert json.load(open(os.path.join(DOSSIER, "cabinet", "dossiers", "501.json"), encoding="utf-8"))["source"] == "api"
    sci = lire(c, "502")["valeurs"]
    assert sci["is_soumis"] is False and sci["docs_juridiques"] is False and sci["tva_annuelle"] is True and sci["prenom"] == ""


def test_pennylane_jeton_de_lecture_et_panne(app):
    c = connecte(app)
    c.post("/api/pennylane/jetons", headers=H, json={"principal": "pl-principal", "lecture": "pl-lecture"})
    PL["exige_lecture"] = True  # le jeton principal n'a pas le droit de lire les exercices : le jeton de lecture prend le relais
    c.get("/api/bilan")
    assert lire(c, "501")["valeurs"]["date_cloture"] == "2025-12-31"
    assert ("/pl/v2/fiscal_years", "pl-lecture", "501") in PL["appels"]
    c.post("/api/pennylane/jetons", headers=H, json={"principal": "jeton-refuse"})
    e = lire(c, "501")  # Pennylane refuse : le dossier s'ouvre quand même, avec ce qui est déjà connu
    assert "Lecture dans Pennylane impossible" in e["avertissement"] and e["valeurs"]["resultat_net"] == "32 400"


def test_avec_outlook_brouillon_signe_avec_logo(app):
    c = connecte(app)
    connecter_boite(c)
    c.post("/api/signature", headers=H, json={"html": f'<p>Claire · Cabinet Exemple</p><img src="data:image/png;base64,{LOGO}">'})
    c.post("/api/bilan/parametres", headers=H, json={"Copie": "secretariat@example.fr"})
    importer(c, open(os.path.join(RACINE, "exemples", "dossiers.csv"), "rb").read())
    v = lire(c, par_nom(c, "PLOMBERIE EXEMPLE")["id"])["valeurs"]
    assert c.get("/api/bilan").json()["boite"] == "cmoreau@cabinet.example.fr"
    GRAPH.clear()
    r = c.post("/api/bilan/brouillon", headers=H, json={"valeurs": v}).json()
    assert r == {"ok": True, "lien": "https://outlook.office.com/brouillon/n1"}
    [(_, _, msg)] = [x for x in GRAPH if x[0] == "POST" and x[1] == "/me/messages"]
    assert msg["toRecipients"] == [{"emailAddress": {"address": "bruno@example.com"}}]
    assert msg["ccRecipients"] == [{"emailAddress": {"address": "compta@example.com"}}, {"emailAddress": {"address": "secretariat@example.fr"}}]
    assert msg["subject"] == "Comptes annuels PLOMBERIE EXEMPLE, exercice clos au 30 juin 2025"
    assert "Claire · Cabinet Exemple" in msg["body"]["content"] and "data:image" not in msg["body"]["content"]
    pj = [x[2] for x in GRAPH if x[1].endswith("/attachments")]
    assert len(pj) == 1 and pj[0]["isInline"] is True and pj[0]["contentBytes"] == LOGO
    assert not [x for x in GRAPH if x[1].endswith("/send")]  # brouillon : rien n'est envoyé
