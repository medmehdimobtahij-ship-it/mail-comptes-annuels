"""Messagerie : nettoyage du HTML, signature de chacun (Outlook du PC, dernier mail envoyé, fichier importé, enregistrée)."""
import os

from commun import DOSSIER, H, SIGNATURES, connecte, connecter_boite, regler

LOGO = "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=="


def test_nettoyage_du_html(app):
    c = connecte(app)
    sale = ('<p onclick="x()" style="color:#c00000;position:fixed;background:url(javascript:a)">Bonjour <script>alert(1)</script>'
            '<b>gras</b> <a href="javascript:alert(1)">piège</a> <a href="https://www.example.com">site</a></p>'
            '<iframe src="https://x"></iframe><o:p>Word</o:p><img src="https://pistage/x.gif" onerror="y()">'
            f'<img src="data:image/png;base64,{LOGO}" width="80"><img src="file:///C:/x.png"><span style="font-size:14pt">grand</span>')
    h = c.post("/api/signature/apercu", json={"html": f"<html><body>{sale}</body></html>"}, headers=H).json()["html"]
    for interdit in ("script", "onclick", "onerror", "javascript", "iframe", "position", "url(", "file:", "<o:p"):
        assert interdit not in h, interdit
    for garde in ("<b>gras</b>", 'href="https://www.example.com"', "color:#c00000", f"data:image/png;base64,{LOGO}", 'width="80"',
                  "font-size:14pt", "Word", "margin:0"):
        assert garde in h, garde


def test_signature_d_outlook_choisie_et_enregistree(app):
    for nom, contenu in (("Cabinet (cmoreau@cabinet.example.fr).htm", '<html><head><meta charset="windows-1252"></head><body><p><b>Claire</b> · Collaboratrice</p></body></html>'),
                         ("Perso.htm", '<html><body><p>Claire perso</p><img src="Perso_fichiers/image001.png"></body></html>')):
        open(os.path.join(SIGNATURES, nom), "wb").write(contenu.encode("cp1252"))
    os.makedirs(os.path.join(SIGNATURES, "Perso_fichiers"), exist_ok=True)
    open(os.path.join(SIGNATURES, "Perso_fichiers", "image001.png"), "wb").write(b"\x89PNG\r\n")
    c = connecte(app)
    connecter_boite(c)
    s = c.get("/api/signature").json()
    assert s["personnalisee"] is False and "Claire Moreau" in s["html"] and "Cabinet Exemple" in s["html"] and "cmoreau@cabinet.example.fr" in s["html"]
    liste = c.get("/api/signature/outlook-locales").json()["signatures"]
    assert [x["nom"] for x in liste] == ["Cabinet (cmoreau@cabinet.example.fr)", "Perso"] and liste[0]["defaut"] is True
    assert "data:image/png;base64," in liste[1]["html"]  # image du dossier « _fichiers » remise dans la signature
    regler(ModeConnexion="")  # en ligne, le site ne lit jamais le PC
    e = connecte(app, en_ligne=True)
    assert e.get("/api/signature/outlook-locales").json() == {"signatures": []}
    r = c.post("/api/signature/outlook", headers=H).json()  # sans accès au PC : relue dans le dernier mail envoyé, logo compris
    assert "Claire MOREAU" in r["html"] and "data:image/png;base64,iVBORw0KGgo=" in r["html"] and "texte cité" not in r["html"]
    choisie = liste[0]["html"]
    assert c.post("/api/signature", json={"html": choisie + "<script>x</script>"}, headers=H).json() == {"html": choisie, "personnalisee": True}
    assert open(os.path.join(DOSSIER, "signatures", "claire.html"), encoding="utf-8").read() == choisie
    assert c.get("/api/signature").json() == {"html": choisie, "personnalisee": True}
    assert connecte(app, "paul").get("/api/signature").json()["personnalisee"] is False  # chacun la sienne
    assert c.post("/api/signature/effacer", headers=H).json()["personnalisee"] is False
