"""Règles du mail des comptes annuels, appelées directement en PHP (tests/regles.php) : sans serveur ni réseau.

C'est là qu'est le métier : ce que la balance dit (résultat, IS du compte 695, loyers 6132, indemnité d'occupation), ce que le
mail doit contenir selon le régime fiscal, la TVA et la forme juridique, et ce qui bloque la rédaction tant qu'il manque.
"""
import base64
import json
import subprocess

from commun import PHP, RACINE

NBSP = " "


def php(f, *args):
    r = subprocess.run([PHP, "tests/regles.php"], cwd=RACINE, input=json.dumps({"f": f, "args": list(args)}), capture_output=True,
                       text=True, encoding="utf-8", check=True)
    return json.loads(r.stdout)


def dossier(**plus):
    """Dossier type : SAS à l'IS, exercice civil 2025 clos, contact connu."""
    d = {"pennylane_id": "1", "nom": "SOCIÉTÉ TEST", "siren": "000000018", "forme_juridique": "SAS", "categorie_fiscale": "bic_is",
         "frequence_tva": "monthly", "source": "api", "extrait": "15/01/2026",
         "exercices": [{"debut": "2025-01-01", "fin": "2025-12-31"}, {"debut": "2099-01-01", "fin": "2099-12-31"}],
         "contact": {"prenom": "Léa", "nom": "Test", "email": "lea@example.com"},
         "comptes": [{"banque": "Banque A", "libelle": "Courant", "solde": "1000.00"}, {"banque": "Banque B", "libelle": "", "solde": "0"}],
         "resultat": {"debut": "2025-01-01", "fin": "2025-12-31", "resultat_net": "35000.00", "impot_societes": "5000.00",
                      "loyers_6132": "0.00", "indemnite_occupation": "0.00"}}
    d.update(plus)
    return d


def test_montants_dates_typographie():
    assert php("lire_montant", "1.234,56") == 123456 and php("lire_montant", "1,234.56") == 123456
    assert php("lire_montant", "- 7 940,13") == -794013 and php("lire_montant", "891 €") == 89100 and php("lire_montant", "abc") is None
    assert php("euros", 2273500) == f"22{NBSP}735{NBSP}€" and php("euros", -794013) == f"-{NBSP}7{NBSP}940,13{NBSP}€"
    assert php("montant_saisie", -794013) == "-7 940,13" and php("montant_entier", 478962) == "4 790"
    assert php("date_longue", "2026-07-01") == "1er juillet 2026" and php("date_longue", "2025-12-31") == "31 décembre 2025"
    assert php("typographie", "Objet : test; 12 €") == f"Objet{NBSP}: test{NBSP}; 12{NBSP}€"


def test_balance_resultat_impot_loyers():
    balance = [{"number": "706000", "credits": "100000", "debits": "0"}, {"number": "607000", "debits": "60000.10", "credits": "0"},
               {"number": "695000", "debits": "4000", "credits": "0"}, {"number": "613200", "label": "Loyer", "debits": "1200", "credits": "0"},
               {"number": "613200", "label": "Indemnité d'occupation", "debits": "3600", "credits": "0"}, {"number": "512000", "debits": "999", "credits": "0"}]
    assert php("resultat_et_impot", balance) == [100000_00 - 60000_10 - 4000_00 - 4800_00, 4000_00]  # le 512 n'entre pas dans le résultat
    assert php("loyers_et_indemnite", balance) == [4800_00, 3600_00]  # l'indemnité n'est reconnue que par son libellé
    ex = [{"start": "2024-07-01", "finish": "2025-06-30"}, {"start": "2025-07-01", "finish": "2026-06-30"}, {"start": "2026-07-01", "finish": "2027-06-30"}]
    assert php("exercice_termine", ex, "2026-11-17")["finish"] == "2026-06-30"
    assert php("libelle_exercice", "2025-07-01", "2026-06-30") == "2025-2026" and php("libelle_exercice", "2025-01-01", "2025-12-31") == "2025"


def test_formulaire_is_a_payer_compte_a_alimenter():
    v = php("formulaire", dossier())
    assert v["is_soumis"] is True and v["statut_is"] == "a_payer" and v["montant_is"] == "5 000"
    assert v["alimenter_compte"] is True and v["banque"] == "Banque A"  # 1 000 € ne couvrent pas 5 000 € d'IS
    assert v["afficher_resultat"] is True and v["resultat_net"] == "35 000" and v["date_cloture"] == "2025-12-31"
    assert v["missions_facture"] == "la tenue comptable 2025, l'établissement des comptes annuels 2025 et le dépôt des comptes au greffe"
    assert v["docs_juridiques"] is True and v["tva_annuelle"] is False and v["tu"] is True and v["annee_declaration"] == "2026"
    riche = php("formulaire", dossier(comptes=[{"banque": "Banque A", "solde": "9000"}]))
    assert riche["alimenter_compte"] is False  # trésorerie suffisante : rien à demander


def test_formulaire_creance_neant_ir_et_sans_greffe():
    r = dossier()
    r["resultat"] = dict(r["resultat"], impot_societes="-1200.00", resultat_net="-3800.00")
    v = php("formulaire", r)
    assert v["statut_is"] == "creance" and v["montant_is"] == "1 200" and v["alimenter_compte"] is False and v["resultat_net"] == "-3 800"
    r["resultat"]["impot_societes"] = "0.00"
    assert php("formulaire", r)["statut_is"] == "neant"
    sci = php("formulaire", dossier(categorie_fiscale="ir", forme_juridique="Société civile immobilière", frequence_tva="yearly"), {}, "Vous")
    assert sci["is_soumis"] is False and sci["docs_juridiques"] is False and sci["tva_annuelle"] is True and sci["tu"] is False
    assert sci["missions_facture"] == "la tenue comptable 2025 et l'établissement des comptes annuels 2025"
    assert php("depot_au_greffe_brut", dossier(forme_juridique="Entrepreneur individuel")) is False
    assert php("depot_au_greffe_brut", dossier(forme_juridique="")) is True  # forme inconnue : cas le plus courant
    # Catégorie fiscale inconnue (fiche sans régime) : un IS dans la balance suffit
    assert php("formulaire", dossier(categorie_fiscale=""))["is_soumis"] is True


def test_formulaire_indemnite_occupation_et_loyers():
    r = dossier()
    r["resultat"] = dict(r["resultat"], loyers_6132="4789.62", indemnite_occupation="4789.62")
    v = php("formulaire", r)
    assert v["indemnite"] is True and v["montant_indemnite"] == "4 790"  # arrondi à l'euro pour la déclaration de revenus
    r["resultat"]["indemnite_occupation"] = "0.00"
    v = php("formulaire", r)
    assert v["indemnite"] is False and v["montant_indemnite"] == "4 790"  # loyer simple : proposé, case décochée
    assert "coche la case" in php("indications", r, v)["indemnite"]


def test_civilite_du_contact_et_des_associes():
    associes = [{"prenom": "Léa", "nom": "TEST", "genre": "madam"}]
    assert php("formulaire", dossier(associes=associes))["genre"] == "F"
    assert php("formulaire", dossier(contact={"prenom": "Léa", "nom": "Test", "email": "x@example.com", "civilite": "M"}, associes=associes))["genre"] == "H"
    assert php("formulaire", dossier())["genre"] == ""


def test_indications_tresorerie_et_source():
    d = dossier()
    ind = php("indications", d, php("formulaire", d))
    assert ind["is"] == "D'après Pennylane : société à l'IS." and "Insuffisant pour l'IS" in ind["solde"]["texte"] and ind["solde"]["etat"] == "rouge"
    assert ind["tresorerie"]["lignes"] == [f"Banque A (Courant) : 1{NBSP}000{NBSP}€", "1 autre(s) compte(s) à 0 €"]
    importe = dossier(source="import")
    assert php("indications", importe, php("formulaire", importe))["tva"] == "D'après le fichier importé : TVA mensuelle."


def valeurs(**plus):
    v = {"prenom": "Léa", "appel": "Bonjour", "tu": True, "email": "lea@example.com", "societe": "SOCIÉTÉ TEST", "date_cloture": "2025-12-31",
         "is_soumis": True, "statut_is": "a_payer", "montant_is": "5 000", "tva_annuelle": False, "facture": True,
         "missions_facture": "la tenue comptable 2025", "reglement_facture": "prélevée", "afficher_resultat": True, "resultat_net": "35 000",
         "alimenter_compte": True, "banque": "Banque A", "iban": "", "indemnite": False, "docs_juridiques": True}
    v.update(plus)
    return v


def test_mail_complet_tutoiement():
    m = php("rediger", valeurs())
    assert m["objet"] == "Comptes annuels SOCIÉTÉ TEST, exercice clos au 31 décembre 2025"
    t = m["texte"]
    assert t.startswith("Bonjour Léa,") and t.rstrip().endswith("Bien à toi,")
    assert f"(solde de 5{NBSP}000{NBSP}€ à payer)" in t and f"s'élève à 35{NBSP}000{NBSP}€." in t
    assert "J'attire ton attention sur le fait qu'il convient d'alimenter le compte bancaire Banque A" in t
    assert "la liasse fiscale, ainsi que la déclaration d'IS, ont été transmises" in t and "Je t'envoie prochainement les documents juridiques" in t
    assert m["joindre"] == ["plaquette (avec la liasse)", "déclaration d'IS", "facture d'honoraires"]
    assert "<li" in m["html"] and "<script" not in m["html"]


def test_mail_vouvoiement_perte_tva_annuelle_et_indemnite():
    m = php("rediger", valeurs(tu=False, appel="Cher", genre="F", resultat_net="-3 800", statut_is="creance", montant_is="1 200",
                               tva_annuelle=True, indemnite=True, montant_indemnite="4 790", annee_declaration="2026", docs_juridiques=False))
    t = m["texte"]
    assert t.startswith("Chère Léa,") and "Bien à vous," in t and "Je vous prie" in t
    assert "perte nette comptable de 3" in t and "Il n'y a donc rien à affecter." in t
    assert "(créance de 1" in t and "alimenter" not in t  # créance : rien à payer
    assert "que la liasse fiscale, la déclaration d'IS ainsi que la déclaration annuelle de TVA ont été transmises" in t
    assert "Case 4BE" in t and "déclaration de revenus 2026" in t and "documents juridiques" not in t
    assert m["joindre"][-2:] == ["déclaration annuelle de TVA", "facture d'honoraires"]


def test_mail_bloque_tant_qu_il_manque():
    assert php("rediger", valeurs(prenom="", date_cloture="", montant_is="", appel="Cher", genre=""))["manques"] == [
        "prénom du client", "date de clôture", "genre du client (pour « Cher / Chère »)", "montant de l'IS"]
    assert php("rediger", valeurs(banque=""))["manques"] == ["banque du compte à alimenter"]
    assert php("rediger", valeurs(indemnite=True, montant_indemnite="", annee_declaration=""))["manques"] == [
        "montant de l'indemnité d'occupation", "année de la déclaration de revenus"]
    assert "manques" not in php("rediger", valeurs(statut_is="neant", montant_is=""))  # IS néant : pas de montant à donner


def test_fichier_eml_sans_outlook():
    logo = "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=="
    eml = php("eml", "Comptes annuels É", "Bonjour", f'<p>Bonjour</p><img src="data:image/png;base64,{logo}">', ["lea@example.com", "pas-une-adresse"],
              ["copie@example.com"])
    entetes = eml.split("\r\n\r\n")[0]
    assert "X-Unsent: 1" in entetes and "To: lea@example.com" in entetes and "Cc: copie@example.com" in entetes
    assert "Subject: =?UTF-8?B?" in entetes and "pas-une-adresse" not in eml
    assert "Content-ID: <image0." in eml and "data:image" not in base64.b64decode(eml.split("text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n")[1].split("--")[0]).decode()


def test_lecture_des_fichiers_importes():
    csv = "societe;cloture;email\r\nSOCI\xc9T\xc9 \xc9;31/12/2025;a@example.fr\r\n\"NOM ; AVEC\";2025-06-30;\r\n".encode("cp1252")
    assert php("importer_lignes", base64.b64encode(csv).decode()) == [["societe", "cloture", "email"], ["SOCIÉTÉ É", "31/12/2025", "a@example.fr"],
                                                                      ["NOM ; AVEC", "2025-06-30", ""]]
    assert php("date_import", "31/12/2025") == "2025-12-31" and php("date_import", "46022") == "2025-12-31" and php("date_import", "31/02/2025") is None
    assert [php("bilan_colonne", t) for t in ("Société", "Date de clôture", "E-mail", "IS", "Inconnue")] == ["societe", "cloture", "email", "impot_societes", ""]
