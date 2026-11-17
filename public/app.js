/* Mail des comptes annuels : l'interface (sans bibliothèque), design « Porcelaine ».
   Ce fichier : outils communs, coque (en-tête), connexion, Paramètres (intégrations, sécurité, signature, Outlook).
   bilan.js : l'assistant du mail ; demarrage.js lance le tout. Données et règles : côté serveur (src/). */
"use strict";

// ---------- Outils ----------
const ICONES = {
  retour: '<path d="M15 6l-6 6 6 6"/>',
  // Tracés Lucide (lucide.dev, licence ISC)
  bilan: '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M8 18v-2"/><path d="M12 18v-4"/><path d="M16 18v-6"/>',
  parametres: '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
  lune: '<path d="M19.5 14.6A7.6 7.6 0 0 1 9.4 4.5a7.6 7.6 0 1 0 10.1 10.1z"/>',
  soleil: '<circle cx="12" cy="12" r="3.8"/><path d="M12 2.8v2M12 19.2v2M2.8 12h2M19.2 12h2M5.5 5.5l1.4 1.4M17.1 17.1l1.4 1.4M5.5 18.5l1.4-1.4M17.1 6.9l1.4-1.4"/>',
  recherche: '<circle cx="10.8" cy="10.8" r="6.3"/><path d="M15.5 15.5l4.5 4.5"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  ouvrir: '<path d="M8 16 16 8M9.5 8H16v6.5"/>',
  mail: '<rect x="3.5" y="5.5" width="17" height="13" rx="2.5"/><path d="m4.5 7.5 7.5 5.5 7.5-5.5"/>',
  chevron: '<path d="m10 7 5 5-5 5"/>',
  trombone: '<path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/>',
  telecharger: '<path d="M12 15V3"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/>',
  importer: '<path d="M12 3v12"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/>',
  copier: '<rect x="8" y="8" width="13" height="13" rx="2.5"/><path d="M16 8V5.5A2.5 2.5 0 0 0 13.5 3h-8A2.5 2.5 0 0 0 3 5.5v8A2.5 2.5 0 0 0 5.5 16H8"/>',
  fermer: '<path d="M6 6l12 12M18 6 6 18"/>',
  coche: '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
  dossier: '<path d="M3.5 7.5a2 2 0 0 1 2-2h3.7l2 2h7.3a2 2 0 0 1 2 2v7.5a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2z"/>',
  bouclier: '<path d="M12 21.5s7.5-3.6 7.5-9.5V5.6L12 2.8 4.5 5.6V12c0 5.9 7.5 9.5 7.5 9.5z"/>',
  bouclierOk: '<path d="M12 21.5s7.5-3.6 7.5-9.5V5.6L12 2.8 4.5 5.6V12c0 5.9 7.5 9.5 7.5 9.5z"/><path d="m8.8 11.9 2.2 2.2 4.3-4.4"/>',
};
const icone = (nom, cls = "") => `<svg class="ic ${cls}" viewBox="0 0 24 24" aria-hidden="true">${ICONES[nom]}</svg>`;

function el(html) { const t = document.createElement("template"); t.innerHTML = html.trim(); return t.content.firstElementChild; }
const echap = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
const $ = (sel, racine = document) => racine.querySelector(sel);
const $$ = (sel, racine = document) => [...racine.querySelectorAll(sel)];
const attendre = (ms) => new Promise((r) => setTimeout(r, ms));
const MOUVEMENT = !matchMedia("(prefers-reduced-motion: reduce)").matches;

/** « 2026-12-15 » ou « 15/12/2026 » -> « 15/12 » ; avec annee : « 15/12/2026 ». */
function dateFr(texte, annee = false) {
  if (!texte) return "";
  const m = String(texte).match(/^(\d{4})-(\d{2})-(\d{2})/);
  const [j, mo, a] = m ? [m[3], m[2], m[1]] : String(texte).slice(0, 10).split("/");
  return annee && a ? `${j}/${mo}/${a}` : `${j}/${mo}`;
}

function initiales(nom) {
  const mots = (nom || "").replace(/-/g, " ").split(/\s+/).filter((m) => /[\p{L}\p{N}]/u.test(m[0] || ""));
  if (!mots.length) return "?";
  return (mots[0][0] + (mots[1] ? mots[1][0] : mots[0].slice(1, 2))).toUpperCase();
}
const TEINTES = [["#ECEBFF", "#4F4BD9"], ["#E2F2E9", "#1C7347"], ["#FCEFD9", "#9A5B00"], ["#E4EEFA", "#22599B"], ["#F8E6EE", "#A23462"], ["#EEF0F2", "#3A3D43"]];
const TEINTES_SOMBRE = [["#2A2850", "#B3B1FF"], ["#15302A", "#5CCB96"], ["#3A2A14", "#F2B154"], ["#16283F", "#8CB8EC"], ["#3A1D2B", "#F08DB6"], ["#2A2C31", "#C9CCD2"]];
function teinte(nom) {
  let h = 0; for (const c of (nom || "").toLowerCase()) h = (h * 31 + c.codePointAt(0)) >>> 0;
  return (S.theme === "sombre" ? TEINTES_SOMBRE : TEINTES)[h % TEINTES.length];
}
function avatar(nom, cls = "", balise = "span") {
  const [fond, encre] = teinte(nom);
  return `<${balise} class="avatar ${cls}" style="background:${fond};color:${encre}" title="${echap(nom)}">${echap(initiales(nom))}</${balise}>`;
}

/** Fichier choisi -> base64 (sans l'en-tête « data:… »). */
function enBase64(fichier) {
  return new Promise((ok, ko) => {
    const r = new FileReader();
    r.onload = () => ok(String(r.result).split(",")[1] || "");
    r.onerror = () => ko(new Error(`${fichier.name} illisible.`));
    r.readAsDataURL(fichier);
  });
}
async function copierTexte(texte, message = "Copié dans le presse-papiers.") {
  try { await navigator.clipboard.writeText(texte); toast(message); }
  catch {  // navigateur sans accès au presse-papiers : copie à l'ancienne
    const z = el("<textarea></textarea>"); z.value = texte; document.body.append(z); z.select();
    document.execCommand("copy"); z.remove(); toast(message);
  }
}
/** Après la frappe : f n'est appelée qu'une fois la saisie terminée (ms sans nouvelle touche). */
function apresFrappe(f, ms = 250) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => f(...a), ms); }; }

// ---------- Échanges avec le serveur ----------
let RELECTURE_AUTO = false;
/** Relectures automatiques (minuteurs) : le serveur ne les compte pas comme une activité (session fermée après 2 h sans rien faire). */
function relectureAuto(f) { RELECTURE_AUTO = true; try { return f(); } finally { RELECTURE_AUTO = false; } }
async function envoyerRequete(methode, url, corps) {
  let r;
  try {
    r = await fetch(url, { method: methode, credentials: "same-origin",
      headers: { "Content-Type": "application/json", "X-Outil": "1", ...(RELECTURE_AUTO ? { "X-Outil-Auto": "1" } : {}) },
      body: corps === undefined ? undefined : JSON.stringify(corps) });
  } catch { throw new Error("Connexion au serveur impossible : vérifiez que l'application est lancée."); }  // serveur arrêté, réseau coupé
  if (r.status === 401 && url !== "/api/connexion") { pageConnexion(url === "/api/moi" ? "" : "Session expirée : reconnectez-vous."); throw new Error("401"); }
  if (!r.ok) {
    const d = r.headers.get("content-type")?.includes("json") ? await r.json() : {};
    const e = new Error(d.erreur || (d.champs ? Object.values(d.champs).join(" ") : "Erreur du serveur."));
    e.champs = d.champs; e.statut = r.status; e.action = d.action; e.demo = d.demo; throw e;
  }
  return r;
}
async function api(methode, url, corps) {
  const r = await envoyerRequete(methode, url, corps);
  return r.headers.get("content-type")?.includes("json") ? r.json() : {};
}
/** Fichier préparé par le serveur (.eml…) : téléchargé sous le nom donné par le serveur. */
async function telecharger(url, corps) {
  const r = await envoyerRequete("POST", url, corps);
  const nom = decodeURIComponent((r.headers.get("content-disposition") || "").match(/filename\*=UTF-8''([^;]+)/)?.[1] || "mail.eml");
  const lien = el("<a></a>"); lien.href = URL.createObjectURL(await r.blob()); lien.download = nom;
  document.body.append(lien); lien.click(); lien.remove(); setTimeout(() => URL.revokeObjectURL(lien.href), 5000);
  return nom;
}

function toast(texte, options = {}) {
  const t = el(`<div class="toast ${options.erreur ? "erreur" : ""}"><span>${echap(texte)}</span></div>`);
  if (options.action) {
    const b = el(`<button>${echap(options.action)}</button>`); b.onclick = () => { options.auClic(); partir(); }; t.append(b);
  }
  $("#toasts").append(t);
  const partir = () => { t.classList.add("part"); setTimeout(() => t.remove(), 260); };
  setTimeout(partir, options.duree || 3600);
}
function signalerErreur(e) { if (e.message !== "401") toast(e.message, { erreur: true, duree: 6000 }); }

/** Nouveau mail pré-rempli dans la messagerie du PC (Outlook, Thunderbird, Mail…) : lien mailto, texte simple (la messagerie
 *  ajoute la signature du poste). Le mail part de là ; la case « Mail envoyé » se coche ensuite. */
function ouvrirAppMail({ a = "", cc = "", objet = "", texte = "" }) {
  const q = (s) => encodeURIComponent(s);  // espaces en %20 (jamais « + », que les applis mail laissent tels quels)
  const dest = (x) => (Array.isArray(x) ? x : String(x).split(/[;,]/)).map((s) => s.trim()).filter(Boolean).join(";");
  const params = [cc && dest(cc) ? `cc=${q(dest(cc))}` : "", `subject=${q(objet)}`, `body=${q(texte.replace(/\r?\n/g, "\r\n"))}`].filter(Boolean).join("&");
  const lien = `mailto:${dest(a).split(";").map(encodeURIComponent).join(";")}?${params}`;
  const cadre = el('<a style="display:none"></a>'); cadre.href = lien; document.body.append(cadre); cadre.click(); cadre.remove();
}

/** Lien Outlook (brouillon) : l'onglet est ouvert au clic, avant la réponse du serveur, sinon le navigateur le bloque. */
async function ouvrirDansOutlook(promesse) {
  const onglet = window.open("about:blank", "_blank");
  try {
    const r = await promesse;
    if (r.lien) { if (onglet) onglet.location.href = r.lien; else window.open(r.lien, "_blank", "noopener"); }
    else onglet?.close();
    return r;
  } catch (e) { onglet?.close(); throw e; }
}

// ---------- État ----------
const S = {
  moi: null, page: "bilan",  // on arrive directement sur l'outil
  ongletParametres: "general", outlook: null, signature: null,
  theme: (() => { try { return localStorage.getItem("theme") || "clair"; } catch { return "clair"; } })(),
};
document.documentElement.dataset.theme = S.theme;

// ---------- Connexion (compte Microsoft du cabinet, ou mot de passe) ----------
const LOGO_MICROSOFT = `<svg class="logo-ms" viewBox="0 0 21 21" aria-hidden="true"><rect x="1" y="1" width="9" height="9" fill="#f25022"/>
  <rect x="11" y="1" width="9" height="9" fill="#7fba00"/><rect x="1" y="11" width="9" height="9" fill="#00a4ef"/><rect x="11" y="11" width="9" height="9" fill="#ffb900"/></svg>`;
const MESSAGES_MICROSOFT = {
  refuse: "Connexion annulée sur la page Microsoft.",
  inconnu: "Ce compte Microsoft n'a pas de compte ici. Utilisez votre adresse du cabinet.",
  mfa: "Double authentification exigée : validez la connexion dans Microsoft Authenticator. Si Microsoft ne vous la demande pas, un administrateur doit l'activer pour le cabinet (Microsoft Entra).",
  expire: "Connexion trop ancienne, ou commencée dans un autre navigateur : recommencez.",
  indisponible: "La connexion Microsoft n'est pas activée ici (application Microsoft Entra à déclarer dans config.json, HTTPS).",
  personnel: "Utilisez votre compte Microsoft du cabinet, pas un compte personnel.",
  deja: "Ce compte Microsoft est déjà lié à un autre compte.",
  organisation: "Ce compte Microsoft n'appartient pas à l'organisation du cabinet.",
  consentement: "Le cabinet doit d'abord autoriser l'application Microsoft (un administrateur, une seule fois).",
  erreur: "Microsoft n'a pas pu confirmer la connexion : réessayez.",
};
let RETOUR_CONNEXION = (() => {  // /?microsoft=… : résultat de la page Microsoft (connexion, liaison du compte), puis adresse nettoyée
  const q = new URLSearchParams(location.search);
  const r = q.get("microsoft");
  if (!r) return null;
  history.replaceState(null, "", "/");
  return r;
})();

function pageConnexion(message = "") {
  if (S.moi) S.moiAvant = S.moi;  // une autre personne qui se connecte : page rechargée (rien de la session d'avant ne reste)
  S.moi = null;
  arreterSuivis();
  const deja = $("#racine .connexion");
  if (deja) { if (message) $(".msg-erreur", deja).textContent = message; return; }  // déjà affichée : la saisie en cours reste
  $("#couche-modales").innerHTML = "";
  $("#racine").innerHTML = "";
  if (!message && RETOUR_CONNEXION) { message = MESSAGES_MICROSOFT[RETOUR_CONNEXION] || ""; RETOUR_CONNEXION = null; }  // affiché une fois
  const f = el(`<div class="connexion"><form autocomplete="on">
      <div class="logo"><img src="logo.svg" alt=""></div>
      <div><h1>Comptes annuels</h1></div>
      <div class="bandeau-demo cache"><b>Mode démo</b> · données fictives, aucun service externe appelé.<br>
        Identifiant <code>demo</code> · mot de passe <code>demo</code></div>
      <a class="btn principal btn-microsoft cache" href="/api/microsoft/connexion">${LOGO_MICROSOFT}Se connecter avec Microsoft</a>
      <div class="separateur-ou cache"><span>ou</span></div>
      <div class="par-mdp cache">
        <div class="champ"><label for="id">Identifiant</label><input id="id" class="saisie" name="username" autocomplete="username"></div>
        <div class="champ"><label for="mdp">Mot de passe</label><input id="mdp" class="saisie" type="password" name="password" autocomplete="current-password"></div>
        <button class="btn principal" type="submit">Se connecter</button>
      </div>
      <div class="msg-erreur">${echap(message)}</div>
      <div class="pied-connexion">Connexion chiffrée · session fermée après 2 h sans activité</div>
    </form></div>`);
  $("#racine").append(f);
  const entrer = async (corps) => {
    try {
      const moi = await api("POST", "/api/connexion", corps);
      if (S.moiAvant && S.moiAvant.identifiant !== moi.identifiant) return location.replace("/");
      S.moi = moi; await demarrer();
    }
    catch (e) { $(".msg-erreur", f).textContent = e.message; }
  };
  $("form", f).onsubmit = (ev) => { ev.preventDefault(); entrer({ identifiant: $("#id", f).value, mot_de_passe: $("#mdp", f).value }); };
  fetch("/api/connexion/options", { credentials: "same-origin" }).then((r) => r.json()).then((o) => {
    $(".btn-microsoft", f).classList.toggle("cache", !o.microsoft);
    $(".separateur-ou", f).classList.toggle("cache", !(o.microsoft && o.mot_de_passe));
    $(".par-mdp", f).classList.toggle("cache", !o.mot_de_passe);
    if (o.microsoft) $(".btn-microsoft", f).focus(); else if (o.mot_de_passe) $("#id", f).focus();
    if (o.demo) {  // démo : identifiants affichés et pré-remplis ; première visite : connexion automatique
      $(".bandeau-demo", f).classList.remove("cache");
      if (!$("#id", f).value) { $("#id", f).value = "demo"; $("#mdp", f).value = "demo"; }
      let deja = false;
      try { deja = sessionStorage.getItem("demo-vue") === "1"; sessionStorage.setItem("demo-vue", "1"); } catch { /* navigateur privé */ }
      if (!deja && !message) entrer({ identifiant: "demo", mot_de_passe: "demo" });
    }
  }).catch(() => { $(".par-mdp", f).classList.remove("cache"); });  // options illisibles : mot de passe seul
}

// ---------- Coque : en-tête, pages ----------
// [code, nom, page]
const PAGES = [["bilan", "Mail des comptes annuels", () => pageBilan], ["parametres", "Paramètres", () => pageParametres]];
const page = (code) => PAGES.find((p) => p[0] === code) || PAGES[0];

/** Coque : en-tête avec le bouton Retour (depuis Paramètres), le titre et les chiffres clés ; l'outil prend tout l'écran. */
function construireCoque() {
  $("#racine").innerHTML = "";
  const c = el(`<div id="coque" class="sans-rail">
    <main id="droite">
      <header class="entete">
        <button class="rond retour cache" id="retour" title="Retour">${icone("retour")}</button>
        <span class="logo logo-entete"><img src="logo.svg" alt=""></span>
        <div style="min-width:0"><h1 id="titre"></h1><div class="sous" id="sous"></div></div>
        <div class="outils">
          <span class="pastille p-orange cache" id="pastille-demo" title="Données fictives, aucun service externe appelé">Mode démo</span>
          <button class="rond" id="params-module" title="Paramètres">${icone("parametres")}</button>
          <button class="rond bouton-theme" title="Changer de thème">${icone(S.theme === "sombre" ? "soleil" : "lune")}</button>
          ${avatar(S.moi.nom, "", "button").replace("<button", '<button id="moi"')}
        </div>
      </header>
      <section id="page"></section>
    </main>
  </div>`);
  $("#racine").append(c);
  $("#retour", c).onclick = () => (history.state?.interne ? history.back() : aller("bilan"));
  $(".bouton-theme", c).onclick = basculerTheme;
  $("#params-module", c).onclick = () => { S.ongletParametres = "general"; aller("parametres"); };
  $("#moi", c).onclick = async (ev) => {
    const ancre = ev.currentTarget;
    let boite = "";
    try { boite = (await etatOutlook()).adresse; } catch { /* menu sans la messagerie */ }
    menu(ancre, [
      [`Connecté : ${S.moi.nom}`, null],
      ["Paramètres", () => { S.ongletParametres = "general"; aller("parametres"); }],
      ["Ma signature des mails", () => { S.ongletParametres = "general"; aller("parametres"); }],
      "-",
      ...(boite ? [[`Outlook : ${boite}`, null], ["Déconnecter ma boîte Outlook", () => deconnecterOutlook()]]
        : [["Connecter ma boîte Outlook", () => connecterOutlook()]]),
      "-",
      ["Se déconnecter", async () => {
        try { await api("POST", "/api/deconnexion"); } catch (e) { if (e.message !== "401") return signalerErreur(e); }
        location.replace("/");  // page rechargée : rien de la personne précédente ne reste (signature, boîte…)
      }],
    ]);
  };
  // Retour du navigateur (ou de la souris) : page précédente ; écouteur posé une seule fois (la coque est refaite à chaque connexion)
  if (!construireCoque.retour) {
    construireCoque.retour = true;
    addEventListener("popstate", (ev) => { if (ev.state?.page && S.moi) aller(ev.state.page, true, false); });
  }
  history.replaceState({ page: S.page }, "");
}

/** Bouton Retour (depuis Paramètres) et logo (sur l'outil). */
function majRail() {
  $("#retour").classList.toggle("cache", S.page === "bilan");
  $(".logo-entete").classList.toggle("cache", S.page !== "bilan");
  $("#params-module").classList.toggle("cache", S.page === "parametres");
}

function basculerTheme() {
  S.theme = S.theme === "sombre" ? "clair" : "sombre";
  try { localStorage.setItem("theme", S.theme); } catch { /* navigateur privé : thème non retenu */ }
  document.documentElement.dataset.theme = S.theme;
  $(".bouton-theme").innerHTML = icone(S.theme === "sombre" ? "soleil" : "lune");
  const moi = $("#moi"), [fond, encre] = teinte(S.moi.nom);
  moi.style.background = fond; moi.style.color = encre;
}

/** Changement de page : l'ancienne s'efface, PUIS la nouvelle arrive en remontant (jamais les deux l'une sur l'autre). */
async function aller(code, avecTransition = true, historique = true) {
  fermerMenus();
  const fenetres = $$("#couche-modales .voile:not(.part) .fenetre");
  if (!fenetres.some((f) => f.bloquee)) fenetres.forEach((f) => f.fermer?.());
  const change = code !== S.page;
  if (change && historique) history.pushState({ page: code, interne: true }, "");  // le bouton Retour du navigateur revient ici
  S.page = code;
  majRail();
  const zone = $("#page");
  if (change && avecTransition && MOUVEMENT) {
    zone.classList.remove("entree"); zone.classList.add("sortie");
    await attendre(140);
  }
  rendre();
  if (MOUVEMENT) { zone.classList.remove("sortie", "entree"); void zone.offsetWidth; zone.classList.add("entree"); }
}

function rendre() {
  const [, nom, fabrique] = page(S.page);
  $("#titre").textContent = nom;
  entete("");
  const zone = $("#page");
  zone.innerHTML = "";
  zone.className = "";
  fabrique()(zone);
}
/** Sous le titre : les chiffres clés de la page (jamais de texte de remplissage). */
function entete(html) { $("#sous").innerHTML = html; }
const chiffre = (n, legende, couleur = "") => `<span class="chiffre ${couleur}"><b>${n}</b> ${echap(legende)}</span>`;

// ---------- Pilule glissante ----------
function segments(conteneur, valeur, auChoix) {
  const pilule = $(".pilule", conteneur);
  const placer = (b, anime) => {
    if (!anime) pilule.style.transition = "none";
    pilule.style.left = b.offsetLeft + "px"; pilule.style.width = b.offsetWidth + "px";
    if (!anime) { void pilule.offsetWidth; pilule.style.transition = ""; }
    $$("button", conteneur).forEach((x) => x.classList.toggle("actif", x === b));
  };
  const boutons = $$("button", conteneur);
  requestAnimationFrame(() => placer(boutons.find((b) => b.dataset.v === valeur) || boutons[0], false));
  boutons.forEach((b) => b.onclick = () => { placer(b, true); auChoix(b.dataset.v); });
  return { placer };
}

// ---------- Menus ----------
function fermerMenus() { $$(".menu-flottant").forEach((m) => m.remove()); }
/** items : [libellé, fonction] ; [libellé, null] = titre ; "-" = séparateur ; [libellé, fonction, { coche, inactif }]. */
function menu(ancre, items, position = null) {
  fermerMenus();
  const m = el(`<div class="menu-flottant" role="menu"></div>`);
  for (const it of items) {
    if (it === "-") { m.append(el("<hr>")); continue; }
    const [libelle, fn, o = {}] = it;
    if (!fn) { m.append(el(`<div class="titre-menu">${echap(libelle)}</div>`)); continue; }
    const b = el(`<button role="menuitem" ${o.inactif ? "disabled" : ""}>${"coche" in o ? `<span class="coche-menu">${o.coche ? icone("coche") : ""}</span>` : ""}${echap(libelle)}</button>`);
    b.onclick = () => { fermerMenus(); fn(); };
    m.append(b);
  }
  document.body.append(m);
  const r = position ? { left: position.x, right: position.x, top: position.y, bottom: position.y } : ancre.getBoundingClientRect(), mr = m.getBoundingClientRect();
  m.style.left = Math.max(10, Math.min(r.left, innerWidth - mr.width - 10)) + "px";
  m.style.top = Math.max(10, r.bottom + mr.height + 6 > innerHeight ? r.top - mr.height - 6 : r.bottom + 6) + "px";
  setTimeout(() => addEventListener("pointerdown", function dehors(e) {
    if (!m.contains(e.target)) { m.remove(); removeEventListener("pointerdown", dehors); }
  }), 0);
}

// ---------- Fenêtres ----------
function fenetre({ titre, sous = "", corps = "", pied = "", classe = "" }) {
  const v = el(`<div class="voile"><div class="fenetre ${classe}" role="dialog" aria-modal="true">
      <div class="f-tete"><div style="min-width:0"><h2>${titre}</h2>${sous ? `<div class="sous">${sous}</div>` : ""}</div>
        <button class="rond fermer" title="Fermer (Échap)">${icone("fermer")}</button></div>
      <div class="f-corps">${corps}</div>${pied ? `<div class="f-pied">${pied}</div>` : ""}</div></div>`);
  const f = $(".fenetre", v);
  const fermer = () => {
    if (!v.isConnected || f.bloquee) return;
    removeEventListener("keydown", echap_);
    v.classList.add("part"); setTimeout(() => v.remove(), MOUVEMENT ? 180 : 0);
    f.aLaFermeture?.();
  };
  const echap_ = (e) => { if (e.key === "Escape" && $("#couche-modales").lastElementChild === v) fermer(); };
  addEventListener("keydown", echap_);
  $(".fermer", f).onclick = fermer;
  v.addEventListener("pointerdown", (e) => { if (e.target === v) v.dataset.dehors = "1"; });
  v.addEventListener("pointerup", (e) => { if (e.target === v && v.dataset.dehors) fermer(); delete v.dataset.dehors; });
  $("#couche-modales").append(v);
  f.fermer = fermer;
  setTimeout(() => $("input:not([type=checkbox]):not([type=radio]):not([type=file]), select, textarea", f)?.focus(), 60);
  return f;
}
/** Question oui / non dans une fenêtre du site (jamais la boîte grise du navigateur). */
function confirmer({ titre, texte = "", oui = "Oui", non = "Annuler", danger = false }) {
  return new Promise((ok) => {
    const f = fenetre({ titre, classe: "petite", corps: texte ? `<div>${texte}</div>` : "",
      pied: `<button class="btn" data-a="non">${echap(non)}</button><button class="btn principal ${danger ? "danger-plein" : ""}" data-a="oui">${echap(oui)}</button>` });
    let reponse = false;
    f.aLaFermeture = () => ok(reponse);
    $('[data-a="non"]', f).onclick = () => f.fermer();
    $('[data-a="oui"]', f).onclick = () => { reponse = true; f.fermer(); };
    setTimeout(() => $('[data-a="oui"]', f).focus(), 80);
  });
}
/** Saisie d'un texte court (nom d'un nouveau dossier…) ; null si annulé. */
function demanderTexte({ titre, sous = "", libelle, valeur = "", indication = "", ok = "Enregistrer" }) {
  return new Promise((rep) => {
    const f = fenetre({ titre, sous, classe: "petite", corps: champ("texte", libelle, saisie("texte", valeur, `placeholder="${echap(indication)}"`)),
      pied: `<button class="btn" data-a="non">Annuler</button><button class="btn principal" data-a="oui">${echap(ok)}</button>` });
    let r = null;
    f.aLaFermeture = () => rep(r);
    $('[data-a="non"]', f).onclick = () => f.fermer();
    const valider = () => { r = $('[name="texte"]', f).value.trim(); f.fermer(); };
    $('[data-a="oui"]', f).onclick = valider;
    $('[name="texte"]', f).onkeydown = (e) => { if (e.key === "Enter") valider(); };
  });
}

function erreursChamps(f, champs = {}) {
  $$(".msg-erreur[data-pour]", f).forEach((m) => m.remove());
  $$(".saisie.erreur", f).forEach((x) => x.classList.remove("erreur"));
  for (const [cle, msg] of Object.entries(champs)) {
    const c = $(`[name="${cle}"]`, f);
    if (c) { c.classList.add("erreur"); c.closest(".champ")?.append(el(`<div class="msg-erreur" data-pour="${cle}">${echap(msg)}</div>`)); }
    else toast(msg, { erreur: true });
  }
}

const champ = (nom, libelle, controle, aide = "") =>
  `<div class="champ"><label for="c-${nom}">${libelle}</label>${controle}${aide ? `<small class="aide">${aide}</small>` : ""}</div>`;
const saisie = (nom, valeur = "", attrs = "") => `<input id="c-${nom}" name="${nom}" class="saisie" value="${echap(valeur)}" ${attrs}>`;
const liste = (nom, options, valeur = "") => `<select id="c-${nom}" name="${nom}" class="saisie">${Object.entries(options)
  .map(([k, l]) => `<option value="${echap(k)}" ${String(k) === String(valeur) ? "selected" : ""}>${echap(l)}</option>`).join("")}</select>`;
const caseACocher = (nom, libelle, coche, attrs = "") => `<label class="case"><input type="checkbox" name="${nom}" ${coche ? "checked" : ""} ${attrs}>${libelle}</label>`;
const revele = (html, ouvert) => `<div class="revele ${ouvert ? "ouvert" : ""}" ${ouvert ? "" : "inert"}><div>${html}</div></div>`;
function ouvrirRevele(bloc, ouvert) { bloc.classList.toggle("ouvert", ouvert); bloc.toggleAttribute("inert", !ouvert); }
const valeurs = (f) => Object.fromEntries($$("[name]", f).map((x) => [x.name, x.type === "checkbox" ? x.checked : x.value]));

// ---------- Aperçu d'un mail (signature de la personne comprise, comme il partira) ----------
/** Signature enregistrée { html, personnalisee } (sans signature enregistrée : la signature simple du serveur). */
async function signature() {
  if (!S.signatureEtat) S.signatureEtat = await api("GET", "/api/signature").catch(() => ({ html: "", personnalisee: false }));
  return S.signatureEtat;
}
/** Sous l'aperçu d'un mail : quelle signature partira, et le bouton pour reprendre celle d'Outlook (ou en changer). */
async function ligneSignature() {
  const s = await signature();
  return `<div class="ligne-signature ${s.personnalisee ? "" : "simple"}">${icone("mail")}<span>${s.personnalisee ? "Signature : la vôtre"
    : "Signature simple (nom, cabinet) : la vôtre, avec votre logo, se règle dans Paramètres"}</span>
    <button class="btn petit ${s.personnalisee ? "fantome" : "principal"}" data-a="sig-changer">${s.personnalisee ? "Changer de signature" : "Reprendre ma signature Outlook"}</button></div>`;
}

/** Bouton « Reprendre ma signature Outlook » où qu'il soit : signature gardée, aperçus mis à jour. */
document.addEventListener("click", async (ev) => {
  const b = ev.target.closest('[data-a="sig-changer"]');
  if (!b) return;
  ev.stopPropagation();
  if (!await fenetreSignatureOutlook()) return;
  toast("Signature reprise : elle sera dans tous vos mails.");
  for (const x of $$(".signature-apercu")) x.innerHTML = S.signature;
  for (const x of $$(".ligne-signature")) x.outerHTML = await ligneSignature();
}, true);
async function htmlApercu(html) {
  const sig = (await signature()).html || "";
  return `${html}${sig ? `<br><div class="signature-apercu" style="font-family:Calibri,Arial,sans-serif;font-size:11pt">${sig}</div>` : ""}`;
}
/** Bloc d'aperçu : À / Cc / Objet / corps / pièces à joindre. */
async function blocApercu(m) {
  return `<div class="mail-entete">
      <div><span>À</span>${m.a ? echap(m.a) : '<b class="rouge">pas d\'e-mail</b>'}</div>
      ${m.cc ? `<div><span>Cc</span>${echap(m.cc)}</div>` : ""}
      <div><span>Objet</span><b>${echap(m.objet)}</b></div></div>
    <div class="apercu-mail">${await htmlApercu(m.html)}</div>
    ${await ligneSignature()}
    ${m.pieces?.length ? `<div class="pieces">${icone("trombone")}${m.pieces.map((p) => `<span>${echap(p)}</span>`).join("")}</div>` : ""}`;
}

/** Erreur de la boîte Microsoft : message + ce qu'il faut faire (reconnecter, autoriser…). */
function fenetreErreurOutlook(e) {
  const f = fenetre({ titre: "Outlook", classe: "moyenne", corps: `<div class="alerte">${echap(e.message)}</div>${htmlAction(e.action)}
      ${e.action?.type === "reconnecter" ? '<div><button class="btn principal" data-a="cx">Connecter ma boîte Outlook</button></div>' : ""}` });
  $('[data-a="cx"]', f)?.addEventListener("click", () => { f.fermer(); connecterOutlook(); });
}
function erreurAction(e) { if (e.message === "401") return; if (e.action) fenetreErreurOutlook(e); else signalerErreur(e); }

// ---------- Paramètres ----------
const ONGLETS_PARAMETRES = [["general", "Général"], ["bilan", "Mail des comptes"]];
function pageParametres(zone) {
  const z = el(`<div class="page-scroll"><div class="parametres">
      <div class="segments" id="onglets-param"><span class="pilule"></span>${ONGLETS_PARAMETRES.map(([k, l]) => `<button data-v="${k}">${l}</button>`).join("")}</div>
      <div id="contenu-param" class="contenu-param"></div></div></div>`);
  zone.append(z);
  const afficher = (o) => {
    S.ongletParametres = o;
    const c = $("#contenu-param", z);
    c.innerHTML = "";
    ({ general: parametresGeneraux, bilan: parametresBilan })[o](c);
    if (MOUVEMENT) c.animate([{ opacity: 0, transform: "translateY(6px)" }, { opacity: 1, transform: "none" }], { duration: 220, easing: "cubic-bezier(.33,1,.68,1)" });
  };
  segments($("#onglets-param", z), S.ongletParametres, afficher);
  afficher(S.ongletParametres);
}

async function parametresGeneraux(c) {
  c.classList.add("parametres");
  c.innerHTML = `<div class="groupe" id="bloc-integrations"></div>
    <div class="groupe" id="bloc-cabinet"></div>
    <div class="groupe" id="bloc-outlook"></div>
    <div class="groupe" id="bloc-pennylane"></div>
    <div class="groupe" id="bloc-securite"></div>`;
  sectionIntegrations($("#bloc-integrations", c));
  sectionCabinet($("#bloc-cabinet", c));
  sectionOutlook($("#bloc-outlook", c));
  sectionPennylane($("#bloc-pennylane", c));
  sectionSecurite($("#bloc-securite", c));
}

/** Chaque intégration : reliée ou non, et ce que l'outil fait sans elle. Aucune n'est obligatoire. */
async function sectionIntegrations(bloc) {
  let p;
  try { p = await api("GET", "/api/parametres"); } catch (e) { bloc.innerHTML = `<div class="alerte">${echap(e.message)}</div>`; return; }
  const ligne = (ok, titre, oui, non) => `<div class="ligne-integration"><span class="pastille ${ok ? "p-vert" : "p-gris"}">${ok ? "Relié" : "Non configuré"}</span>
    <div><b>${titre}</b><br><span class="aide">${ok ? oui : non}</span></div></div>`;
  bloc.innerHTML = `<div class="groupe-titre">INTÉGRATIONS · TOUTES FACULTATIVES</div>
    ${ligne(p.pennylane.relie, "Pennylane", p.pennylane.simule ? "Simulé en démo : dossiers et chiffres fictifs." : "La liste des clients et les chiffres de l'exercice sont lus dans Pennylane au choix du dossier.",
      "Sans jeton : importez la liste des clients (CSV ou Excel) ou créez les dossiers à la main, depuis l'écran du mail.")}
    ${ligne(!!p.outlook.adresse, "Boîte Outlook", `Brouillons créés dans ${echap(p.outlook.adresse)}.`,
      p.outlook.application ? "Boîte non connectée : le mail s'ouvre dans votre messagerie ou se télécharge en .eml." : "Sans application Microsoft : le mail s'ouvre dans votre messagerie (Outlook, Thunderbird…) ou se télécharge en .eml.")}
    ${ligne(p.microsoft, "Connexion avec Microsoft", "Connexion avec le compte Microsoft du cabinet (double authentification possible).",
      "Connexion par identifiant et mot de passe. Pour Microsoft : application Microsoft Entra à déclarer (config.json, microsoft_client_id).")}`;
}

/** Nom du cabinet : signature simple des mails (avant que chacun ait repris la sienne). */
async function sectionCabinet(bloc) {
  let p;
  try { p = await api("GET", "/api/parametres"); } catch { return; }
  bloc.innerHTML = `<div class="groupe-titre">CABINET</div>
    ${champ("cabinet", "Nom du cabinet", saisie("cabinet", p.cabinet), "Dans la signature simple des mails, tant que chacun n'a pas repris la sienne.")}
    <div><button class="btn" data-a="cabinet">Enregistrer</button></div>`;
  $('[data-a="cabinet"]', bloc).onclick = async () => {
    try { await api("POST", "/api/parametres/cabinet", { cabinet: $('[name="cabinet"]', bloc).value }); S.signatureEtat = null; toast("Nom du cabinet enregistré."); }
    catch (e) { signalerErreur(e); }
  };
}

// ---------- Sécurité : compte Microsoft (Entra ID, Authenticator), mode de connexion, dernières connexions ----------
const LIBELLES_SECURITE = {
  connexion: "Connexion", connexion_refusee: "Connexion refusée", connexion_bloquee: "Trop d'essais : bloqué", deconnexion: "Déconnexion",
  mot_de_passe_change: "Mot de passe changé", microsoft_lie: "Compte Microsoft lié", microsoft_delie: "Compte Microsoft délié",
  liaison_refusee: "Liaison refusée", mode_connexion: "Mode de connexion changé", sessions_fermees: "Autres sessions fermées",
};
const dateHeure = (iso) => new Date(iso).toLocaleString("fr-FR", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" });

async function sectionSecurite(bloc) {
  const titre = `<div class="groupe-titre">SÉCURITÉ DE LA CONNEXION · ${echap(S.moi.nom.toUpperCase())}</div>`;
  bloc.innerHTML = `${titre}<div class="aide">Chargement…</div>`;
  let s;
  try { s = await api("GET", "/api/securite"); } catch (e) { bloc.innerHTML = `${titre}<div class="alerte">${echap(e.message)}</div>`; return; }
  const ses = s.session;
  const sansLien = s.comptes.filter((c) => !c.lie).map((c) => c.nom);
  const moyen = (l) => l.methode === "microsoft" ? `Microsoft${l.mfa === true ? " + Authenticator" : l.mfa === false ? " (sans double auth.)" : ""}`
    : l.methode === "mot_de_passe" ? "Mot de passe" : "";
  bloc.innerHTML = `${titre}
    ${s.disponible || s.microsoft ? `<div class="ligne-outlook ${s.microsoft ? "ok" : ""}">${LOGO_MICROSOFT}<div>
      ${s.microsoft ? `<b>Compte Microsoft lié : ${echap(s.microsoft.adresse)}</b><br><span>Connexion avec Microsoft ; votre boîte Outlook est connectée du même coup.</span>`
        : `<b>Compte Microsoft non lié</b><br><span>Lié tout seul à votre première connexion avec votre adresse du cabinet.</span>`}</div>
      ${s.microsoft ? `<button class="btn petit fantome danger" data-a="delier">Délier</button>`
        : s.disponible ? `<a class="btn principal petit" href="/api/microsoft/lier">Lier mon compte Microsoft</a>` : ""}</div>` : ""}
    <div class="session-actuelle">${icone(ses.mfa ? "bouclierOk" : "bouclier")}<span>Cette session : <b>${echap(moyen(ses) || "Mot de passe")}</b>.
      Fermée après ${Math.round(s.duree.inactivite / 3600)} h sans activité, et au plus après ${Math.round(s.duree.max / 3600)} h.</span></div>
    ${s.disponible ? `<div class="champ"><label>Mode de connexion du cabinet</label>
      <div class="choix-mode">${Object.entries(s.modes).map(([k, l]) => `<label class="case"><input type="radio" name="mode" value="${k}" ${s.mode === k ? "checked" : ""}>
        <span><b>${echap(l)}</b>${k === "microsoft_mfa" ? " · recommandé" : ""}</span></label>`).join("")}</div>
      <small class="aide">${sansLien.length ? `${echap(sansLien.join(", "))} : lié tout seul à sa première connexion avec son adresse du cabinet.`
        : "Tous les comptes sont liés."} En cas de souci avec Microsoft : data/parametres.json › « ModeConnexion » : "".</small>
      <div class="msg-erreur" data-err="mode"></div></div>` : ""}
    ${s.mot_de_passe ? `<div class="champ"><label>Mot de passe</label>
      <div class="grille2">${champ("ancien", "Mot de passe actuel", saisie("ancien", "", 'type="password" autocomplete="current-password"'))}
        ${champ("nouveau", "Nouveau mot de passe (12 caractères, majuscule, minuscule, chiffre, caractère spécial)", saisie("nouveau", "", 'type="password" autocomplete="new-password"'))}</div>
      <div><button class="btn petit" data-a="mdp">Changer mon mot de passe</button></div></div>` : ""}
    <div class="champ"><label>Dernières connexions à votre compte</label>
      ${s.journal.length ? `<table class="journal-securite"><thead><tr><th>Date</th><th>Événement</th><th>Moyen</th><th>Navigateur</th><th>Adresse IP</th></tr></thead><tbody>
        ${s.journal.map((l) => `<tr class="${l.evt.includes("refus") || l.evt.includes("bloq") ? "refus" : ""}"><td>${dateHeure(l.t)}</td>
          <td>${echap(LIBELLES_SECURITE[l.evt] || l.evt)}${l.motif ? ` <span class="muted">(${echap(l.motif)})</span>` : ""}</td>
          <td>${echap(moyen(l))}</td><td>${echap(l.nav || "")}</td><td>${echap(l.ip || "")}</td></tr>`).join("")}</tbody></table>`
        : `<div class="aide">Rien pour l'instant.</div>`}
      <small class="aide">Journal gardé 12 mois.</small>
      <div><button class="btn petit" data-a="fermer">Fermer mes sessions sur les autres navigateurs</button></div></div>`;
  $('[data-a="delier"]', bloc)?.addEventListener("click", async () => {
    if (!await confirmer({ titre: "Délier votre compte Microsoft ?", texte: "Il sera lié de nouveau à votre prochaine connexion avec Microsoft.", oui: "Délier", danger: true })) return;
    try { await api("POST", "/api/securite/delier"); toast("Compte Microsoft délié."); sectionSecurite(bloc); }
    catch (e) { if (e.champs?.microsoft) toast(e.champs.microsoft, { erreur: true, duree: 7000 }); else signalerErreur(e); }
  });
  $$('[name="mode"]', bloc).forEach((r) => r.addEventListener("change", async () => {
    try { await api("POST", "/api/securite/mode", { mode: r.value }); toast("Mode de connexion enregistré."); sectionSecurite(bloc); }
    catch (e) { $('[data-err="mode"]', bloc).textContent = e.champs?.mode || e.message; $$('[name="mode"]', bloc).forEach((x) => { x.checked = x.value === s.mode; }); }
  }));
  $('[data-a="mdp"]', bloc)?.addEventListener("click", async () => {
    const g = $('[data-a="mdp"]', bloc).closest(".champ");
    try {
      await api("POST", "/api/mot-de-passe", { ancien: $('[name="ancien"]', bloc).value, nouveau: $('[name="nouveau"]', bloc).value });
      $('[name="ancien"]', bloc).value = $('[name="nouveau"]', bloc).value = ""; erreursChamps(g, {}); toast("Mot de passe changé.");
    } catch (e) { if (e.champs) erreursChamps(g, e.champs); else signalerErreur(e); }
  });
  $('[data-a="fermer"]', bloc).onclick = async () => {
    try { await api("POST", "/api/securite/fermer-sessions"); toast("Vos autres sessions sont fermées."); sectionSecurite(bloc); } catch (e) { signalerErreur(e); }
  };
}

/** Jetons API Pennylane du cabinet : jamais livrés avec le site ; masqués à l'écran une fois enregistrés. Facultatifs. */
async function sectionPennylane(bloc, apres = null) {
  let p;
  try { p = await api("GET", "/api/parametres"); } catch (e) { bloc.innerHTML = `<div class="alerte">${echap(e.message)}</div>`; return; }
  bloc.innerHTML = `<div class="groupe-titre">PENNYLANE · JETONS API DU CABINET (FACULTATIF)</div>
    ${p.demo ? '<div class="aide">Mode démo : Pennylane est simulé avec des dossiers fictifs, les jetons ne se règlent pas ici.</div>' : ""}
    ${champ("principal", "Jeton API du cabinet", saisie("principal", "", `type="password" autocomplete="off" placeholder="${p.pennylane.principal ? `Enregistré : ${echap(p.pennylane.principal)}` : "À coller (Pennylane › Paramètres du cabinet › API)"}"`))}
    ${champ("lecture", "Jeton de lecture (secours : lecture des exercices)", saisie("lecture", "", `type="password" autocomplete="off" placeholder="${p.pennylane.lecture ? `Enregistré : ${echap(p.pennylane.lecture)}` : "Facultatif"}"`))}
    <div style="display:flex;gap:6px;flex-wrap:wrap"><button class="btn" data-a="jetons">Enregistrer les jetons</button>
      ${p.pennylane.principal ? '<button class="btn fantome danger" data-a="retirer">Retirer les jetons</button>' : ""}</div>`;
  const enregistrer = async (d, message) => {
    try { await api("POST", "/api/pennylane/jetons", d); toast(message); apres ? apres() : sectionPennylane(bloc); }
    catch (e) { signalerErreur(e); }
  };
  $('[data-a="jetons"]', bloc).onclick = () => {
    const d = {};
    for (const k of ["principal", "lecture"]) { const v = $(`[name="${k}"]`, bloc).value.trim(); if (v) d[k] = v; }
    if (!Object.keys(d).length) return toast("Collez au moins un jeton.", { erreur: true });
    enregistrer(d, "Jetons Pennylane enregistrés.");
  };
  $('[data-a="retirer"]', bloc)?.addEventListener("click", () => enregistrer({ principal: "", lecture: "" }, "Jetons Pennylane retirés."));
}

// ---------- Éditeur de texte des signatures : gras, italique, souligné, couleur, taille, listes, liens (comme Outlook) ----------
const TAILLES_PT = [8, 9, 10, 11, 12, 14, 16, 18, 20, 24, 28, 36];  // comme Outlook ; 11 par défaut (Calibri 11)
const COULEURS_TEXTE = [["#000000", "Noir"], ["#404040", "Gris foncé"], ["#7F7F7F", "Gris"], ["#C00000", "Rouge foncé"], ["#FF0000", "Rouge"],
  ["#ED7D31", "Orange"], ["#FFC000", "Or"], ["#00B050", "Vert"], ["#375623", "Vert foncé"], ["#00B0F0", "Bleu clair"], ["#0070C0", "Bleu"],
  ["#002060", "Bleu foncé"], ["#7030A0", "Violet"]];
function editeurTexte(html = "", indication = "") {
  const e = el(`<div class="editeur">
      <div class="ed-barre" role="toolbar" aria-label="Mise en forme">
        <select class="ed-taille" title="Taille du texte (points)">${TAILLES_PT.map((t) => `<option value="${t}" ${t === 11 ? "selected" : ""}>${t}</option>`).join("")}</select>
        <span class="ed-sep"></span>
        <button type="button" data-c="bold" title="Gras (Ctrl+B)"><b>G</b></button>
        <button type="button" data-c="italic" title="Italique (Ctrl+I)"><i>I</i></button>
        <button type="button" data-c="underline" title="Souligné (Ctrl+U)"><u>S</u></button>
        <span class="ed-couleurs"><button type="button" class="ed-couleur" data-a="couleur" title="Couleur du texte"><span>A</span><i></i></button><button type="button"
          class="ed-couleur-fleche" data-a="palette" title="Choisir la couleur" aria-haspopup="true">▾</button>
          <div class="ed-palette cache" role="menu">
            <div class="ed-palette-titre">Couleurs</div>
            <div class="ed-nuancier">${COULEURS_TEXTE.map(([c, nom]) => `<button type="button" data-couleur="${c}" title="${nom}" style="background:${c}"></button>`).join("")}</div>
            <label class="ed-autre">Autre couleur…<input type="color" value="#c00000"></label></div></span>
        <span class="ed-sep"></span>
        <button type="button" data-a="lien" title="Insérer un lien"><svg viewBox="0 0 24 24"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/></svg></button>
        <button type="button" data-c="removeFormat" title="Effacer la mise en forme"><span class="ed-tx">T<sub>x</sub></span></button>
      </div>
      <div class="ed-zone" contenteditable="true" role="textbox" aria-multiline="true" data-indication="${echap(indication)}">${html}</div></div>`);
  const zone = $(".ed-zone", e);
  let plage = null;  // sélection gardée quand on clique dans la barre (couleur, taille)
  const garder = () => { const s = getSelection(); if (s.rangeCount && zone.contains(s.anchorNode)) plage = s.getRangeAt(0).cloneRange(); };
  const remettre = () => { zone.focus(); if (plage) { const s = getSelection(); s.removeAllRanges(); s.addRange(plage); } };
  const faire = (c, v = null) => { remettre(); document.execCommand("styleWithCSS", false, false); document.execCommand(c, false, v); garder(); etat(); };
  const etat = () => {
    $$("[data-c]", e).forEach((b) => { try { b.classList.toggle("actif", document.queryCommandState(b.dataset.c)); } catch { /* commande sans état */ } });
    const n = getSelection().anchorNode, x = n && (n.nodeType === 1 ? n : n.parentElement);  // taille du texte là où est le curseur
    if (x && zone.contains(x)) { const pt = Math.round(parseFloat(getComputedStyle(x).fontSize) * 0.75); if (TAILLES_PT.includes(pt)) $(".ed-taille", e).value = String(pt); }
  };
  /** Taille précise en points : le navigateur ne connaît que 7 tailles, on pose la 7 puis on la remplace par la taille choisie. */
  const taille = (pt) => {
    faire("fontSize", "7");
    $$('font[size="7"]', zone).forEach((f) => {
      const s = document.createElement("span"); s.style.fontSize = `${pt}pt`;
      while (f.firstChild) s.appendChild(f.firstChild);
      f.replaceWith(s);
    });
    garder(); etat();
  };
  $$(".ed-barre button, .ed-barre label, .ed-barre select", e).forEach((b) => b.addEventListener("mousedown", (ev) => { garder(); if (b.tagName === "BUTTON") ev.preventDefault(); }));
  $$("[data-c]", e).forEach((b) => b.onclick = () => faire(b.dataset.c));
  $(".ed-taille", e).onchange = (ev) => taille(ev.target.value);
  let couleur = "#C00000";  // la dernière choisie : « A » la remet d'un clic
  const palette = $(".ed-palette", e);
  const fermerPalette = () => palette.classList.add("cache");
  const appliquerCouleur = (c) => { couleur = c; $(".ed-couleur i", e).style.background = c; fermerPalette(); faire("foreColor", c); };
  $('[data-a="couleur"]', e).onclick = () => faire("foreColor", couleur);
  $('[data-a="palette"]', e).onclick = () => palette.classList.toggle("cache");
  $$("[data-couleur]", e).forEach((b) => b.onclick = () => appliquerCouleur(b.dataset.couleur));
  $(".ed-autre input", e).addEventListener("input", (ev) => appliquerCouleur(ev.target.value));
  document.addEventListener("mousedown", (ev) => { if (!ev.target.closest(".ed-couleurs") || !e.contains(ev.target)) fermerPalette(); });
  $('[data-a="lien"]', e).onclick = async () => {
    const url = await demanderTexte({ titre: "Insérer un lien", libelle: "Adresse du lien", valeur: "https://", ok: "Insérer" });
    if (url && /^(https?:\/\/|mailto:)/i.test(url)) faire("createLink", url);
  };
  zone.addEventListener("keyup", () => { garder(); etat(); });
  zone.addEventListener("mouseup", () => { garder(); etat(); });
  return { el: e, zone, html: () => zone.innerHTML, texte: () => zone.innerText.trim(), focus: () => zone.focus() };
}

/** Fichiers de signature d'Outlook choisis dans le navigateur (dossier %APPDATA%\Microsoft\Signatures, ou des .htm) : chaque
 *  signature nettoyée par le serveur, avec ses images (dossier « …_fichiers ») remises dedans. */
async function signaturesDesFichiers(fichiers) {
  const tous = [...fichiers];
  const relatif = (f) => (f.webkitRelativePath || f.name).split("/").slice(f.webkitRelativePath ? 1 : 0).join("/").toLowerCase();
  const parChemin = new Map(tous.map((f) => [relatif(f), f]));
  const liste = [];
  // Signatures : les .htm du dossier choisi, ou de son sous-dossier « Signatures » ; jamais ceux d'un « …_fichiers » (pièces de la signature)
  const estSignature = (x) => /\.html?$/i.test(x.name) && (!relatif(x).includes("/") || /^signatures\/[^/]+$/.test(relatif(x)));
  for (const f of tous.filter(estSignature)) {
    const dossier = relatif(f).includes("/") ? relatif(f).replace(/[^/]+$/, "") : "";
    const octets = await f.arrayBuffer();
    let texte = new TextDecoder("utf-8").decode(octets);
    if (texte.includes("�") || /charset=windows-1252/i.test(texte)) texte = new TextDecoder("windows-1252").decode(octets);  // Outlook classique
    if (octets.byteLength > 1 && new Uint8Array(octets)[0] === 0xff && new Uint8Array(octets)[1] === 0xfe) texte = new TextDecoder("utf-16le").decode(octets);
    for (const src of new Set([...texte.matchAll(/src="([^"]+)"/gi)].map((m) => m[1]).filter((s) => !/^(data:|https?:|cid:)/i.test(s)))) {
      let chemin = src; try { chemin = decodeURIComponent(src); } catch { /* tel quel */ }
      const img = parChemin.get(dossier + chemin.replace(/\\/g, "/").replace(/&amp;/g, "&").toLowerCase());
      if (img && img.size < 700000) texte = texte.split(`src="${src}"`).join(`src="data:${img.type || "image/png"};base64,${await enBase64(img)}"`);
    }
    try {
      const html = (await api("POST", "/api/signature/apercu", { html: texte })).html;
      if (html.replace(/<[^>]+>/g, "").trim() || html.includes("<img")) liste.push({ nom: f.name.replace(/\.html?$/i, ""), html, defaut: false });
    } catch { /* fichier illisible : ignoré */ }
  }
  const moi = (S.outlook?.adresse || "").toLowerCase();  // Outlook nomme souvent la signature « … (adresse) »
  liste.forEach((s) => { s.defaut = !!moi && s.nom.toLowerCase().includes(moi); });
  return liste.sort((a, b) => b.defaut - a.defaut || a.nom.localeCompare(b.nom));
}

/** Sélecteur de dossier : la personne choisit son dossier de signatures Outlook (le navigateur demande la confirmation). */
function choisirDossierSignatures() {
  return new Promise((fin) => {
    const i = el('<input type="file" webkitdirectory multiple hidden>');
    i.onchange = async () => { const l = await signaturesDesFichiers(i.files); i.remove(); fin(l); };
    i.addEventListener("cancel", () => { i.remove(); fin([]); });
    document.body.append(i); i.click();
  });
}

const DOSSIER_SIGNATURES = "%APPDATA%\\Microsoft\\Signatures";

/** Fenêtre « Reprendre ma signature Outlook » : signatures d'Outlook sur ce PC, dossier des signatures (adresse à coller), fichier
 *  .htm, ou derniers mails envoyés. La signature choisie est gardée par défaut. Retourne le HTML gardé, ou null. */
function fenetreSignatureOutlook() {
  return new Promise((fin) => {
    const f = fenetre({ titre: "Reprendre ma signature Outlook", sous: "Elle sera mise en bas de vos mails", classe: "moyenne",
      corps: `<ol class="etapes-action">
          <li>« Choisir le dossier », puis dans la fenêtre Windows, collez dans la barre d'adresse : <code>${DOSSIER_SIGNATURES}</code> et Entrée.</li>
          <li>Cliquez « Sélectionner un dossier » (et « Importer » si le navigateur le demande), puis choisissez votre signature.</li></ol>
        <div class="ligne-boutons"><button class="btn principal" data-a="dossier">${icone("dossier")} Choisir le dossier</button>
          <button class="btn" data-a="copier">${icone("copier")} Copier l'adresse du dossier</button></div>
        <details class="avance"><summary>Autres moyens</summary>
          <div class="aide">Fichier de la signature (.htm) choisi à la main, signature d'Outlook sur ce PC, ou signature reprise de vos derniers mails envoyés (boîte Outlook connectée).</div>
          <div class="ligne-boutons"><label class="btn">Choisir le fichier .htm<input type="file" accept=".htm,.html" multiple class="cache" name="sig-fichier"></label>
            <button class="btn" data-a="locales">Outlook de ce PC</button>
            <button class="btn" data-a="envoyes">${icone("mail")} Depuis mes mails envoyés</button></div></details>
        <div class="aide etat-import"></div>` });
    let rendu = false;
    const finir = (html) => { if (rendu) return; rendu = true; f.fermer(); fin(html); };
    const garder = async (liste) => {
      if (!liste.length) return toast("Aucune signature trouvée : choisissez " + DOSSIER_SIGNATURES + ".", { erreur: true, duree: 8000 });
      const choisie = liste.length === 1 ? liste[0] : await choisirSignature(liste);
      if (!choisie) return;
      if (await proposerSignature(choisie.html)) finir(S.signature);
    };
    $('[data-a="copier"]', f).onclick = () => copierTexte(DOSSIER_SIGNATURES, "Adresse copiée : collez-la dans la fenêtre du navigateur.");
    $('[data-a="dossier"]', f).onclick = async () => garder(await choisirDossierSignatures());
    $('[name="sig-fichier"]', f).onchange = async (ev) => { const l = await signaturesDesFichiers(ev.target.files); ev.target.value = ""; garder(l); };
    $('[data-a="locales"]', f).onclick = async () => {
      try { garder((await api("GET", "/api/signature/outlook-locales")).signatures); } catch (e) { signalerErreur(e); }
    };
    $('[data-a="envoyes"]', f).onclick = async (ev) => {
      const b = ev.currentTarget; b.disabled = true;
      $(".etat-import", f).textContent = "Lecture de vos derniers mails envoyés…";
      try { const r = await api("POST", "/api/signature/outlook"); await garder([{ nom: "Signature de vos mails envoyés", html: r.html, defaut: true }]); }
      catch (e) { erreurAction(e); }
      $(".etat-import", f).textContent = ""; b.disabled = false;
    };
    const obs = new MutationObserver(() => { if (!f.isConnected) { obs.disconnect(); if (!rendu) { rendu = true; fin(null); } } });
    obs.observe($("#couche-modales"), { childList: true });
  });
}

function choisirSignature(liste) {
  return new Promise((fin) => {
    const f = fenetre({ titre: "Quelle signature ?", sous: "Vous en avez plusieurs : choisissez celle de vos mails.", classe: "moyenne",
      corps: `<div class="choix-signatures">${liste.map((s, i) => `<button class="choix-signature" data-i="${i}"><span class="cs-nom">${echap(s.nom)}${s.defaut
        ? ' <span class="pastille p-vert">par défaut dans Outlook</span>' : ""}</span><div class="cs-apercu">${s.html}</div></button>`).join("")}</div>` });
    let rendu = false;
    $$("[data-i]", f).forEach((b) => b.onclick = () => { rendu = true; f.fermer(); fin(liste[+b.dataset.i]); });
    const obs = new MutationObserver(() => { if (!f.isConnected) { obs.disconnect(); if (!rendu) fin(null); } });
    obs.observe($("#couche-modales"), { childList: true });
  });
}

/** « Garder cette signature et en faire celle par défaut ? » : oui → enregistrée. */
function proposerSignature(html) {
  return new Promise((fin) => {
    const f = fenetre({ titre: "Garder cette signature ?", sous: "Elle sera mise en bas de vos mails. Modifiable dans Paramètres.", classe: "moyenne",
      corps: `<div class="cs-apercu grand">${html}</div>`,
      pied: '<button class="btn fantome" data-a="non">Annuler</button><button class="btn principal" data-a="oui">Oui, en faire ma signature</button>' });
    let rendu = false;
    $('[data-a="non"]', f).onclick = () => { rendu = true; f.fermer(); fin(false); };
    $('[data-a="oui"]', f).onclick = async () => {
      rendu = true;
      let ok = false;
      try { S.signature = (await api("POST", "/api/signature", { html })).html; S.signatureEtat = null; toast("Signature enregistrée."); ok = true; }
      catch (e) { signalerErreur(e); }
      f.fermer(); fin(ok);
    };
    const obs = new MutationObserver(() => { if (!f.isConnected) { obs.disconnect(); if (!rendu) fin(false); } });
    obs.observe($("#couche-modales"), { childList: true });
  });
}

/** Boucles de suivi en cours (connexion de la boîte) : toutes arrêtées quand la session se ferme. */
const SUIVIS = new Set();
function arreterSuivis() { [...SUIVIS].forEach((arret) => arret()); }

// ---------- Messagerie Outlook (chacun connecte SA boîte Microsoft, facultatif) ----------
async function etatOutlook() {
  S.outlook = await api("GET", "/api/outlook");
  return S.outlook;
}

/** Ce qu'il faut faire pour régler un souci de mise en place (lien direct + étapes), affiché tel quel à l'écran. */
function htmlAction(action) {
  if (!action || !action.etapes) return "";
  return `<ol class="etapes-action">${action.etapes.map((e) => `<li>${echap(e)}</li>`).join("")}</ol>
    ${action.lien ? `<a class="btn principal petit" href="${echap(action.lien)}" target="_blank" rel="noopener">Ouvrir la page d'autorisation ↗</a>` : ""}`;
}

async function deconnecterOutlook(apres) {
  if (!await confirmer({ titre: "Déconnecter votre boîte Outlook ?", texte: "Vos mails restent dans Outlook ; vous pourrez la reconnecter quand vous voulez.", oui: "Déconnecter", danger: true })) return false;
  try { await api("POST", "/api/outlook/deconnexion"); } catch (e) { signalerErreur(e); return false; }
  S.outlook = null;
  toast("Boîte Outlook déconnectée.");
  apres?.();
  return true;
}

async function connecterOutlook(apres) {
  let r;
  try { r = await api("POST", "/api/outlook/connexion"); }
  catch (e) {
    if (e.message === "401") return null;
    let action = e.action;
    if (!action && !e.demo) try { action = (await api("POST", "/api/outlook/verifier")).action; } catch { /* sans les étapes */ }
    return fenetre({ titre: "Connexion Outlook impossible pour l'instant", classe: "moyenne",
      corps: `<div class="alerte">${echap(e.message)}</div>${htmlAction(action)}` });
  }
  const f = fenetre({ titre: "Connecter ma boîte Outlook", classe: "moyenne",
    corps: `<div class="code-outlook">
        <ol class="etapes-action"><li>« Se connecter chez Microsoft » : la page s'ouvre dans un nouvel onglet.</li>
          <li>Choisissez votre compte du cabinet, puis acceptez : l'application pourra déposer vos mails en brouillon.</li>
          ${r.automatique ? "<li>Vous revenez automatiquement ici.</li>" : `<li>La page de retour affiche « Ce site est inaccessible » : c'est normal.
            Copiez son adresse (elle commence par http://localhost) et collez-la ci-dessous.</li>`}</ol>
        <div style="display:flex;justify-content:center"><a class="btn principal" href="${echap(r.url)}" target="_blank" rel="noopener">Se connecter chez Microsoft ↗</a></div>
        <div class="montant-unite coller ${r.automatique ? "cache" : ""}"><input class="saisie" name="retour" placeholder="Collez ici l'adresse http://localhost/?code=…"><button class="btn" data-a="valider">Valider</button></div>
        <div class="attente"><span class="point-attente"></span> En attente de votre connexion sur la page Microsoft…</div></div>` });
  const valider = async () => {
    const adresse = $('[name="retour"]', f).value.trim();
    if (!adresse) return;
    try { await api("POST", "/api/outlook/connexion/retour", { adresse }); }
    catch (e) { signalerErreur(e); }
  };
  $('[data-a="valider"]', f).onclick = valider;
  $('[name="retour"]', f).addEventListener("paste", () => setTimeout(valider, 50));  // collé : validé aussitôt
  const suivre = setInterval(async () => {
    if (!f.isConnected) return arret();
    let e;
    try { e = await relectureAuto(etatOutlook); } catch (err) { if (err.message === "401") arret(); return; }
    const cx = e.connexion || {};
    if (cx.etat === "connecte") {
      arret();
      $(".f-corps", f).innerHTML = `<div class="succes-outlook">${icone("mail")}<b>Boîte connectée</b><span>${echap(e.adresse)}</span></div>`;
      setTimeout(() => { f.fermer(); apres?.(); }, 1400);
    } else if (cx.etat === "erreur") {
      arret();
      $(".attente", f).outerHTML = `<div class="alerte">${echap(cx.message)}</div>${htmlAction(cx.action)}
        <div><button class="btn" data-a="encore">Recommencer</button></div>`;
      $('[data-a="encore"]', f).onclick = () => { f.fermer(); connecterOutlook(apres); };
    }
  }, 2500);
  const arret = () => { clearInterval(suivre); SUIVIS.delete(arret); };
  SUIVIS.add(arret);
  return f;
}

/** Paramètres › Messagerie : signature des mails, état de la boîte Outlook (facultative), réglages de l'application Microsoft. */
async function sectionOutlook(bloc) {
  bloc.innerHTML = `<div class="groupe-titre">SIGNATURE ET MESSAGERIE · ${echap(S.moi.nom.toUpperCase())}</div><div class="aide">Vérification…</div>`;
  let e;
  try { e = await etatOutlook(); } catch (err) { $(".aide", bloc).outerHTML = `<div class="alerte">${echap(err.message)}</div>`; return; }
  const corps = e.demo ? `<div class="ligne-outlook">${icone("mail")}<div><b>Outlook désactivé en démo</b><br><span>Le brouillon Outlook est simulé ; le mail s'ouvre aussi dans votre messagerie ou en .eml.</span></div></div>`
    : e.adresse ? `<div class="ligne-outlook ok">${icone("mail")}<div><b>Connectée : ${echap(e.adresse)}</b><br><span>Les brouillons sont créés dans cette boîte.</span></div>
        <button class="btn petit fantome danger" data-a="deconnecter">Déconnecter</button></div>`
    : `<div class="ligne-outlook">${icone("mail")}<div><b>Boîte Outlook non connectée (facultatif)</b><br><span>Sans elle, le mail s'ouvre dans votre messagerie ou se télécharge en .eml.</span></div>
        ${e.client_id ? '<button class="btn principal petit" data-a="connecter">Connecter ma boîte</button>' : ""}</div>`;
  bloc.innerHTML = `<div class="groupe-titre">SIGNATURE ET MESSAGERIE · ${echap(S.moi.nom.toUpperCase())}</div>${corps}
    <div class="champ"><label>Ma signature, en bas de mes mails</label>
      <div class="sig-editeur"></div>
      <div style="display:flex;gap:6px;flex-wrap:wrap"><button class="btn petit" data-a="signature">Enregistrer ma signature</button>
        <button class="btn petit fantome" data-a="sig-dossier">${icone("dossier")} Reprendre ma signature Outlook</button>
        <label class="btn petit fantome">Importer un fichier (.htm)<input type="file" accept=".htm,.html" multiple data-a="sig-fichier" hidden></label>
        <button class="btn petit fantome" data-a="sig-effacer">Signature simple</button></div></div>
    <div class="verif"></div>
    ${e.demo ? "" : `<details class="avance"><summary>Réglages avancés (application Microsoft Entra du cabinet)</summary>
      <div class="champ"><label>Identifiant de l'application Microsoft (ID d'application)</label>
        <div class="montant-unite"><input class="saisie" name="client_id" value="${echap(e.client_id_reglage)}" placeholder="${echap(e.client_id || "Aucune : Outlook et la connexion Microsoft sont désactivés")}"><button class="btn" data-a="verifier">Vérifier</button></div>
        <small class="aide">Vide : celui de config.json (microsoft_client_id). Autorisations Graph déléguées : User.Read, Mail.ReadWrite.</small></div>
      <div class="champ"><label>Secret de l'application (adresse du site déclarée sous « Web » dans Microsoft Entra)</label>
        <div class="montant-unite"><input class="saisie" type="password" autocomplete="off" name="secret" placeholder="${e.secret ? "Enregistré" : "Valeur du secret client"}"><button class="btn" data-a="secret">Enregistrer</button></div>
        <small class="aide">Microsoft Entra › votre application › Certificats et secrets : collez la « Valeur ». Vide + Enregistrer : retiré.</small></div>
      <label class="case"><input type="checkbox" name="retour_site" ${e.retour_site ? "checked" : ""}>Retour automatique sur le site après la connexion Microsoft</label>
      <small class="aide">À cocher une fois l'adresse <b>${echap(e.adresse_site)}</b> ajoutée dans Microsoft Entra (Authentification › URI de redirection).
        En local (localhost), le retour est toujours automatique.</small></details>`}`;
  S.signature = null; S.signatureEtat = null;
  let actuelle = { html: "" };
  try { actuelle = await api("GET", "/api/signature"); } catch { /* sans signature */ }
  S.signature = actuelle.personnalisee ? actuelle.html : null;
  const sig = editeurTexte(actuelle.html, "Votre signature…");
  $(".sig-editeur", bloc).append(sig.el);
  $('[data-a="signature"]', bloc).onclick = async () => {
    try { S.signature = (await api("POST", "/api/signature", { html: sig.html() })).html; S.signatureEtat = null; toast("Signature enregistrée."); } catch (err) { signalerErreur(err); }
  };
  $('[data-a="sig-effacer"]', bloc).onclick = async () => {
    try { const r = await api("POST", "/api/signature/effacer"); sig.zone.innerHTML = r.html; S.signature = null; S.signatureEtat = null; toast("Signature simple (nom, cabinet)."); }
    catch (err) { signalerErreur(err); }
  };
  const depuisFichiers = async (liste) => {
    if (!liste.length) return toast("Aucune signature trouvée dans ces fichiers.", { erreur: true });
    const choisie = liste.length === 1 ? liste[0] : await choisirSignature(liste);
    if (choisie) { sig.zone.innerHTML = choisie.html; if (await proposerSignature(choisie.html)) sig.zone.innerHTML = S.signature; }
  };
  $('[data-a="sig-dossier"]', bloc).onclick = async () => { const h = await fenetreSignatureOutlook(); if (h) sig.zone.innerHTML = h; };
  $('[data-a="sig-fichier"]', bloc).onchange = async (ev) => { await depuisFichiers(await signaturesDesFichiers(ev.target.files)); ev.target.value = ""; };
  if (e.demo) return;
  const verif = $(".verif", bloc);
  const verifier = async () => {
    verif.innerHTML = '<div class="aide">Vérification de la configuration Microsoft…</div>';
    try {
      const v = await api("POST", "/api/outlook/verifier");
      verif.innerHTML = v.ok ? "" : `<div class="alerte"><b>${echap(v.erreur)}</b></div>${htmlAction(v.action)}`;
    } catch (err) { verif.innerHTML = `<div class="alerte">Vérification impossible : ${echap(err.message)}</div>`; }
  };
  $('[data-a="connecter"]', bloc)?.addEventListener("click", () => connecterOutlook(() => sectionOutlook(bloc)));
  $('[data-a="deconnecter"]', bloc)?.addEventListener("click", () => deconnecterOutlook(() => sectionOutlook(bloc)));
  $('[name="retour_site"]', bloc).onchange = (ev) => api("POST", "/api/outlook/reglages", { retour_site: ev.target.checked })
    .then(() => toast(ev.target.checked ? "Retour automatique activé." : "Retour automatique désactivé.")).catch(signalerErreur);
  $('[data-a="secret"]', bloc).onclick = async () => {
    const champ = $('[name="secret"]', bloc);
    try {
      await api("POST", "/api/outlook/reglages", { secret: champ.value });
      toast(champ.value.trim() ? "Secret enregistré (chiffré)." : "Secret retiré.");
      sectionOutlook(bloc);
    } catch (err) { signalerErreur(err); }
  };
  $('[data-a="verifier"]', bloc).onclick = async () => {
    await api("POST", "/api/outlook/reglages", { client_id: $('[name="client_id"]', bloc).value }).catch(signalerErreur);
    sectionOutlook(bloc);
  };
  if (!e.adresse && e.client_id) verifier();
}
