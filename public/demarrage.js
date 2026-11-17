/* Démarrage : session, coque, page, retour de la page Microsoft (connexion, boîte Outlook). Chargé en dernier. */
"use strict";

const RETOUR_MICROSOFT = (() => {  // retour de « Connecter ma boîte » sur le site : gardé, puis l'adresse est nettoyée
  const q = new URLSearchParams(location.search);
  if (!q.get("state") || !(q.get("code") || q.get("error"))) return null;
  const adresse = location.href;
  history.replaceState(null, "", "/");
  return adresse;
})();

async function terminerRetourMicrosoft() {
  try {
    const r = await api("POST", "/api/outlook/connexion/retour", { adresse: RETOUR_MICROSOFT });
    toast(`Boîte connectée : ${r.adresse}`);
  } catch (e) { if (e.message !== "401") toast(e.message, { erreur: true, duree: 9000 }); }
  S.ongletParametres = "general";
  aller("parametres");
}

async function demarrer() {
  if (!PAGES.some((p) => p[0] === S.page)) S.page = "bilan";
  construireCoque();
  majRail();
  rendre();
  if (RETOUR_MICROSOFT && !demarrer.retourFait) { demarrer.retourFait = true; terminerRetourMicrosoft(); }
  else if (RETOUR_CONNEXION) {  // liaison du compte Microsoft (Paramètres › Sécurité)
    const r = RETOUR_CONNEXION;
    RETOUR_CONNEXION = null;
    if (r === "lie") toast("Compte Microsoft lié.");
    else toast(MESSAGES_MICROSOFT[r] || MESSAGES_MICROSOFT.erreur, { erreur: true, duree: 9000 });
    S.ongletParametres = "general";
    aller("parametres");
  }
}

(async () => {
  try { S.moi = await api("GET", "/api/moi"); } catch (e) { if (e.message !== "401") pageConnexion(); return; }
  await demarrer();
})();
