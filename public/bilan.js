/* Mail d'envoi des comptes annuels, en assistant :
   1. Dossier (lu dans Pennylane au clic sur Suivant, ou fiche importée / saisie) ; 2. Client (coordonnées gardées dans sa fiche) ;
   3. Contenu du mail ; 4. Aperçu ; 5. Envoi (brouillon Outlook, messagerie du PC ou fichier .eml, puis case « Mail envoyé »).
   Les cases sont réglées d'après les chiffres du dossier ; les modifications à la main sont gardées par dossier. */
"use strict";

const ETAPES_BILAN = ["Dossier", "Client", "Contenu", "Aperçu", "Envoi"];
const BI = { d: null, etape: 0, courant: null, etat: null, recherche: "", mail: null, occupe: false };
const SOURCES = { api: ["Pennylane", "p-violet"], import: ["importé", "p-gris"], manuel: ["saisi", "p-gris"] };

function pageBilan(zone) {
  zone.classList.add("page-outil");
  zone.innerHTML = `<div class="assistant">
      <div class="etapes-assistant">${ETAPES_BILAN.map((n, i) => `<span data-e="${i}"><b>${i + 1}</b>${n}</span>`).join('<i class="trait"></i>')}</div>
      <div class="carte-etape"><div class="tete-etape"><h2></h2><div class="sous"></div></div>
        <div class="alerte rouge cache" id="alerte-bilan"></div><div class="contenu-etape"></div></div>
      <div class="bas-assistant"><span class="info"></span><button class="btn" data-a="precedent">Précédent</button>
        <button class="btn principal" data-a="suivant">Suivant</button></div></div>`;
  $('[data-a="precedent"]', zone).onclick = () => etapeBilan(Math.max(0, BI.etape - 1));
  $('[data-a="suivant"]', zone).onclick = () => suivantBilan();
  BI.etape = BI.courant && BI.etat ? Math.min(BI.etape, 3) : 0;
  chargerBilan().then(() => etapeBilan(BI.etape)).catch((e) => alerteBilan([e.message]));
}

async function chargerBilan(frais = false) {
  BI.d = await api("GET", "/api/bilan" + (frais ? "?frais=1" : ""));
  $("#pastille-demo")?.classList.toggle("cache", !BI.d.demo);
  enteteBilan();
}
function enteteBilan() {
  if (S.page === "bilan") entete([chiffre(BI.d.chiffres.dossiers, "dossiers"), chiffre(BI.d.chiffres.envoyes, "mails envoyés", "vert")].join(""));
}

const nomCourant = () => BI.etat?.nom || BI.d?.dossiers.find((x) => x.id === BI.courant)?.nom || "";

function etapeBilan(e) {
  BI.etape = e;
  if (S.page !== "bilan") return;  // réponse arrivée après un changement de page
  const z = $(".assistant");
  if (!z) return;
  alerteBilan(null);
  const nom = nomCourant();
  const [titre, sous] = [["Quel dossier ?", ""], ["À qui écrire ?", nom], ["Que contient le mail ?", nom], ["Aperçu du mail", nom], ["Envoyer le mail", nom]][e];
  $(".tete-etape h2", z).textContent = titre;
  $(".tete-etape .sous", z).textContent = sous;
  $$(".etapes-assistant span", z).forEach((s, i) => { s.classList.toggle("courante", i === e); s.classList.toggle("faite", i < e); });
  const ancien = $(".contenu-etape", z), c = ancien.cloneNode(false);  // neuf à chaque étape : aucun écouteur ne s'accumule
  ancien.replaceWith(c);
  [etapeDossiers, etapeClient, etapeContenu, etapeApercuBilan, etapeEnvoiBilan][e](c);
  if (MOUVEMENT) c.animate([{ opacity: 0, transform: "translateY(8px)" }, { opacity: 1, transform: "none" }], { duration: 240, easing: "cubic-bezier(.33,1,.68,1)" });
  majBoutonsBilan();
}

function majBoutonsBilan() {
  if (S.page !== "bilan") return;
  const z = $(".assistant");
  if (!z) return;
  const e = BI.etape, suivant = $('[data-a="suivant"]', z);
  $('[data-a="precedent"]', z).classList.toggle("cache", e === 0);
  suivant.innerHTML = e === 4 ? "Nouveau mail" : "Suivant";
  suivant.disabled = BI.occupe || (e === 0 && !BI.courant);
}

function alerteBilan(lignes) {
  const a = $("#alerte-bilan");
  if (!a) return;
  a.classList.toggle("cache", !lignes);
  if (lignes) a.innerHTML = `<b>À corriger avant de continuer :</b><ul>${lignes.map((l) => `<li>${echap(l)}</li>`).join("")}</ul>`;
}
const infoBilan = (t) => { const i = S.page === "bilan" && $(".assistant .info"); if (i) i.innerHTML = t; };

async function suivantBilan() {
  const e = BI.etape;
  if (e === 0) return lireDossierBilan();
  if (e === 1) {
    const v = BI.etat.valeurs, manques = [];
    if (!String(v.prenom).trim()) manques.push("prénom du client");
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(String(v.email).trim())) manques.push("e-mail du destinataire");
    if (!String(v.societe).trim()) manques.push("nom de la société");
    if (!/^\d{4}-\d{2}-\d{2}$/.test(v.date_cloture || "")) manques.push("date de clôture (JJ/MM/AAAA)");
    if (v.appel === "Cher" && !["H", "F"].includes(v.genre)) manques.push("genre (pour « Cher / Chère »)");
    return manques.length ? alerteBilan(manques) : etapeBilan(2);
  }
  if (e === 2) {
    const m = await api("POST", "/api/bilan/apercu", { valeurs: BI.etat.valeurs }).catch((err) => ({ manques: [err.message] }));
    if (m.manques) return alerteBilan(m.manques);
    BI.mail = m;
    return etapeBilan(3);
  }
  if (e === 3) return etapeBilan(4);
  BI.courant = null; BI.etat = null; BI.recherche = ""; BI.mail = null;
  await chargerBilan().catch(() => {});
  etapeBilan(0);
}

// --- 1. Dossier : de Pennylane, d'un fichier importé, ou saisi à la main ---
function etapeDossiers(c) {
  const d = BI.d;
  c.innerHTML = `${d.erreur ? `<div class="alerte">Liste du cabinet non relue dans Pennylane : ${echap(d.erreur)}</div>` : ""}
    ${!d.pennylane ? `<div class="aide">Pennylane n'est pas relié : importez la liste de vos clients (CSV ou Excel, avec les chiffres de l'exercice si vous les avez)
      ou ajoutez un dossier à la main. Le jeton Pennylane se colle dans Paramètres.</div>` : ""}
    <div class="barre-dossiers"><label class="recherche">${icone("recherche")}<input class="saisie" placeholder="Rechercher un dossier…" value="${echap(BI.recherche)}"></label>
      <label class="btn" title="Fichier CSV ou Excel : une ligne par client (voir le modèle)">${icone("importer")} Importer<input type="file" accept=".csv,.txt,.xlsx" class="cache" name="import"></label>
      <button class="btn" data-a="nouveau">${icone("plus")} Nouveau dossier</button>
      <a class="btn fantome" href="/api/bilan/modele.csv" title="Fichier d'exemple avec toutes les colonnes reconnues">${icone("telecharger")} Modèle</a></div>
    <div class="liste-etape" tabindex="0"></div>`;
  const zone = $(".liste-etape", c), champ = $(".recherche input", c);
  const remplir = () => {
    const q = BI.recherche.trim().toLowerCase();
    const ls = d.dossiers.filter((x) => !q || x.nom.toLowerCase().includes(q));
    zone.innerHTML = ls.length ? ls.map((x) => { const [src, cl] = SOURCES[x.source] || ["", ""];
      return `<div class="item-etape ${x.id === BI.courant ? "choisi" : ""} ${x.envoye ? "fait" : ""}" data-id="${echap(x.id)}" role="button" tabindex="-1">
        <span>${echap(x.nom)}</span>${src && x.source !== "api" ? `<span class="pastille ${cl}">${src}</span>` : ""}
        ${x.modifie ? '<span class="pastille p-violet">modifié</span>' : ""}${x.envoye ? `<span class="pastille p-vert">✓ mail envoyé le ${echap(x.envoye)}</span>` : ""}
        ${x.source !== "api" ? `<button class="rond petit retirer" data-retirer="${echap(x.id)}" title="Retirer ce dossier">${icone("fermer")}</button>` : ""}</div>`; }).join("")
      : `<div class="vide-tableau"><b>Aucun dossier</b>${d.dossiers.length ? "" : "<span>Importez vos clients ou ajoutez un dossier.</span>"}</div>`;
  };
  remplir();
  champ.oninput = () => { BI.recherche = champ.value; remplir(); };
  champ.onkeydown = (ev) => { if (ev.key === "Enter" && BI.courant) suivantBilan(); };
  zone.onclick = async (ev) => {
    const r = ev.target.closest("[data-retirer]");
    if (r) {
      const x = d.dossiers.find((y) => y.id === r.dataset.retirer);
      if (!await confirmer({ titre: `Retirer ${echap(x?.nom || "ce dossier")} ?`, texte: "Sa fiche, ses modifications et son suivi sont effacés.", oui: "Retirer", danger: true })) return;
      try { await api("POST", "/api/bilan/dossier/retirer", { id: r.dataset.retirer }); if (BI.courant === r.dataset.retirer) { BI.courant = null; BI.etat = null; } await chargerBilan(); etapeBilan(0); }
      catch (e) { signalerErreur(e); }
      return;
    }
    const b = ev.target.closest(".item-etape"); if (!b) return;
    if (BI.courant !== b.dataset.id) { BI.courant = b.dataset.id; BI.etat = null; }
    $$(".item-etape", zone).forEach((x) => x.classList.toggle("choisi", x === b));
    majBoutonsBilan();
  };
  zone.ondblclick = (ev) => { if (ev.target.closest(".item-etape") && !ev.target.closest("[data-retirer]")) suivantBilan(); };
  $('[name="import"]', c).onchange = async (ev) => {
    const f = ev.target.files[0]; ev.target.value = "";
    if (f) importerBilan(f);
  };
  $('[data-a="nouveau"]', c).onclick = async () => {
    const nom = await demanderTexte({ titre: "Nouveau dossier", sous: "Le contact et les chiffres se complètent ensuite dans le formulaire.", libelle: "Nom de la société", ok: "Créer" });
    if (!nom) return;
    try {
      const r = await api("POST", "/api/bilan/dossier", { nom });
      await chargerBilan(); BI.courant = r.id; BI.etat = null; lireDossierBilan();
    } catch (e) { signalerErreur(e); }
  };
  setTimeout(() => { champ.focus(); $(".item-etape.choisi", zone)?.scrollIntoView({ block: "center" }); }, 60);
}

/** Liste des clients depuis un fichier : ce qui est importé, et chaque ligne refusée avec sa raison. */
async function importerBilan(fichier) {
  try {
    const r = await api("POST", "/api/bilan/import", { nom: fichier.name, contenu: await enBase64(fichier) });
    await chargerBilan(); etapeBilan(0);
    const bilan = `${r.ajoutes} dossier(s) ajouté(s), ${r.mis_a_jour} mis à jour.`;
    if (!r.erreurs.length) return toast(bilan);
    fenetre({ titre: "Import terminé", sous: bilan, classe: "moyenne",
      corps: `<div>Lignes non importées (corrigez-les puis importez à nouveau le fichier) :</div><ul class="liste-raisons">${r.erreurs.map((x) => `<li>${echap(x)}</li>`).join("")}</ul>` });
  } catch (e) { signalerErreur(e); }
}

/** Ouvre le dossier : relu dans Pennylane s'il en vient (jamais de données périmées), sinon sa fiche ; puis étape Client. */
async function lireDossierBilan() {
  BI.occupe = true; majBoutonsBilan(); infoBilan('<span class="point-attente"></span> Lecture du dossier…');
  try {
    BI.etat = await api("POST", "/api/bilan/lire", { id: BI.courant });
    if (BI.etat.avertissement) toast(BI.etat.avertissement, { erreur: true, duree: 8000 });
    etapeBilan(1);
  } catch (e) { if (e.message !== "401") alerteBilan([e.message]); }
  BI.occupe = false; infoBilan(""); majBoutonsBilan();
}

// --- Saisies : coordonnées dans la fiche du client ; le reste, gardé pour le dossier (seules les différences) ---
const enregistrerSaisiesBilan = apresFrappe(async () => {
  const id = BI.courant, envoye = { ...BI.etat.valeurs };
  try {
    const r = await api("POST", "/api/bilan/saisies", { id, valeurs: envoye });
    if (BI.courant !== id) return;
    // Valeurs recalculées par le serveur (missions après une clôture saisie…) ; jamais ce qui a été tapé depuis l'envoi
    for (const [k, x] of Object.entries(r.valeurs)) if (x !== envoye[k] && BI.etat.valeurs[k] === envoye[k]) BI.etat.valeurs[k] = x;
    BI.etat.modifie = r.modifie; BI.etat.indications = r.indications;
    const dossier = BI.d.dossiers.find((x) => x.id === id); if (dossier) dossier.modifie = r.modifie;
    majIndicationsBilan();
  } catch (e) { signalerErreur(e); }
}, 300);

function lireChamp(x) {
  if (x.type === "checkbox") return x.checked;
  if (x.name === "tu") return x.value === "1";
  if (x.name === "date_cloture") { const m = x.value.trim().match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/); return m ? `${m[3]}-${m[2].padStart(2, "0")}-${m[1].padStart(2, "0")}` : x.value.trim(); }
  return x.value;
}
function brancherFormulaireBilan(c) {
  c.addEventListener("input", (ev) => {
    const x = ev.target.closest("[name]");
    if (!x) return;
    BI.etat.valeurs[x.name] = lireChamp(x);
    visibiliteBilan(c);
    enregistrerSaisiesBilan();
  });
}

// --- 2. Client ---
function etapeClient(c) {
  const v = BI.etat.valeurs, ind = BI.etat.indications;
  c.innerHTML = `<div class="aide">${echap(ind.infos.join(" · "))}${BI.etat.modifie ? " · modifié à la main" : ""}</div>
    <div class="groupe"><div class="groupe-titre">CLIENT · COORDONNÉES GARDÉES DANS SA FICHE</div>
      <div class="grille2">${champ("prenom", "Prénom", saisie("prenom", v.prenom))}${champ("nom_contact", "Nom", saisie("nom_contact", v.nom_contact))}</div>
      <div class="grille-3-2">${champ("appel", "Formule d'appel", liste("appel", { Bonjour: "Bonjour …", Cher: "Cher / Chère …" }, v.appel))}
        ${champ("genre", "Civilité", liste("genre", { "": "–", H: "Monsieur", F: "Madame" }, v.genre))}</div>
      ${champ("tu", "Tutoiement", liste("tu", { 1: "Tu · Bien à toi", 0: "Vous · Bien à vous" }, v.tu ? "1" : "0"))}
      <div class="grille2">${champ("email", "E-mail du destinataire", saisie("email", v.email, 'type="email" placeholder="adresse@client.fr"'))}
        ${champ("cc", "En copie (Cc)", saisie("cc", v.cc, 'placeholder="Facultatif"'), BI.d.parametres.Copie ? `Et la copie du cabinet : ${echap(BI.d.parametres.Copie)}` : "")}</div></div>
    <div class="groupe"><div class="groupe-titre">EXERCICE</div>
      <div class="grille-3-2">${champ("societe", "Société", saisie("societe", v.societe))}${champ("date_cloture", "Clôture", saisie("date_cloture", dateFr(v.date_cloture, true), 'placeholder="JJ/MM/AAAA"'))}</div></div>`;
  brancherFormulaireBilan(c);
}

// --- 3. Contenu ---
function etapeContenu(c) {
  const v = BI.etat.valeurs;
  const montant = (k, place) => saisie(k, v[k], `inputmode="decimal" placeholder="${place}"`);
  c.innerHTML = `<div class="tete-contenu"><div class="tresorerie"></div>
      <button class="btn petit" data-a="reinit" title="Oublie les modifications pour ce dossier et reprend les valeurs proposées d'après ses chiffres">Valeurs d'origine</button></div>
    <div class="groupe"><div class="groupe-titre">IMPÔT SUR LES SOCIÉTÉS</div>
      ${caseACocher("is_soumis", "Société à l'IS : joindre la déclaration d'IS", v.is_soumis)}<div class="aide" data-raison="is"></div>
      ${revele(`<div class="grille2">${champ("statut_is", "Situation", liste("statut_is", BI.d.statuts_is, v.statut_is))}${champ("montant_is", "Montant (€)", montant("montant_is", "ex : 891"))}</div>
        <div class="solde aide"></div>
        ${caseACocher("alimenter_compte", "Demander d'alimenter le compte bancaire (IS à payer)", v.alimenter_compte)}
        ${revele(`<button class="choix-compte btn" data-a="compte"></button>
          <div class="grille-2-3">${champ("banque", "Banque", saisie("banque", v.banque))}${champ("iban", "IBAN", saisie("iban", v.iban))}</div>`, false)}`, v.is_soumis)}</div>
    <div class="groupe"><div class="groupe-titre">TVA</div>
      ${caseACocher("tva_annuelle", "TVA annuelle : joindre la déclaration annuelle de TVA", v.tva_annuelle)}<div class="aide" data-raison="tva"></div></div>
    <div class="groupe"><div class="groupe-titre">RÉSULTAT</div>
      ${caseACocher("afficher_resultat", "Indiquer le résultat net comptable dans le mail", v.afficher_resultat)}<div class="aide" data-raison="resultat"></div>
      ${revele(champ("resultat_net", "Résultat net (€, « - » si perte)", montant("resultat_net", "ex : - 7 940,13")), v.afficher_resultat)}</div>
    <div class="groupe"><div class="groupe-titre">FACTURE D'HONORAIRES</div>
      ${caseACocher("facture", "Joindre la facture d'honoraires", v.facture)}
      ${revele(`${champ("missions_facture", "Missions facturées", saisie("missions_facture", v.missions_facture))}${champ("reglement_facture", "Règlement", saisie("reglement_facture", v.reglement_facture))}`, v.facture)}</div>
    <div class="groupe"><div class="groupe-titre">INDEMNITÉ D'OCCUPATION</div>
      ${caseACocher("indemnite", "Indiquer le montant à déclarer (case 4BE)", v.indemnite)}<div class="aide" data-raison="indemnite"></div>
      ${revele(`<div class="grille2">${champ("montant_indemnite", "Montant (€)", montant("montant_indemnite", "ex : 4 790"))}${champ("annee_declaration", "Déclaration de revenus", saisie("annee_declaration", v.annee_declaration))}</div>`, v.indemnite)}</div>
    <div class="groupe"><div class="groupe-titre">JURIDIQUE</div>
      ${caseACocher("docs_juridiques", "Annoncer l'envoi prochain des documents juridiques (dépôt des comptes)", v.docs_juridiques)}<div class="aide" data-raison="juridique"></div></div>`;
  brancherFormulaireBilan(c);
  $('[data-a="reinit"]', c).onclick = async () => {
    try {
      BI.etat = await api("POST", "/api/bilan/reinitialiser", { id: BI.courant });
      const dossier = BI.d.dossiers.find((x) => x.id === BI.courant); if (dossier) dossier.modifie = false;
      etapeBilan(2); toast("Valeurs d'origine reprises.");
    } catch (e) { signalerErreur(e); }
  };
  $('[data-a="compte"]', c).onclick = (ev) => {
    const comptes = BI.etat.indications.comptes;
    menu(ev.currentTarget, comptes.map((x) => [`${x.nom}${x.iban ? ` · ${x.iban}` : ""}${x.solde_texte ? ` · solde ${x.solde_texte}` : ""}`, () => {
      for (const [k, val] of [["banque", x.banque], ["iban", x.iban]]) { $(`[name="${k}"]`, c).value = val; BI.etat.valeurs[k] = val; }
      enregistrerSaisiesBilan();
    }]));
  };
  visibiliteBilan(c);
  majIndicationsBilan();
}

function visibiliteBilan(c) {
  const v = BI.etat.valeurs, blocs = $$(".revele", c);
  if (BI.etape !== 2 || blocs.length < 5) return;
  const aPayer = v.statut_is === "a_payer";
  ouvrirRevele(blocs[0], !!v.is_soumis);
  $('[name="montant_is"]', c).disabled = v.statut_is === "neant";
  $('[name="alimenter_compte"]', c).disabled = !aPayer;
  ouvrirRevele(blocs[1], aPayer && !!v.alimenter_compte);
  ouvrirRevele(blocs[2], !!v.afficher_resultat);
  ouvrirRevele(blocs[3], !!v.facture);
  ouvrirRevele(blocs[4], !!v.indemnite);
}

/** Sous chaque case : ce que disent les chiffres du dossier ; trésorerie (indicatif) et solde du compte face à l'IS à payer. */
function majIndicationsBilan() {
  const c = S.page === "bilan" && $(".contenu-etape");
  if (!c || BI.etape !== 2) return;
  const ind = BI.etat.indications;
  for (const k of ["is", "tva", "indemnite", "juridique", "resultat"]) { const r = $(`[data-raison="${k}"]`, c); if (r) r.textContent = ind[k] || ""; }
  $(".tresorerie", c).innerHTML = `<b>${echap(ind.tresorerie.texte)}</b>${ind.tresorerie.lignes.map((l) => `<div class="aide">· ${echap(l)}</div>`).join("")}`;
  const s = $(".solde", c); s.textContent = ind.solde.texte; s.className = `solde aide ${ind.solde.etat === "rouge" ? "rouge" : ind.solde.etat === "vert" ? "vert" : ""}`;
  const n = ind.comptes.length, b = $('[data-a="compte"]', c);
  b.disabled = !n; b.textContent = n ? `Choisir un compte bancaire (${n})` : "Aucun compte bancaire connu : saisir la banque";
  $('[data-a="reinit"]', c).disabled = !BI.etat.modifie;
}

// --- 4. Aperçu ---
async function etapeApercuBilan(c) {
  const m = BI.mail;
  c.innerHTML = `${await blocApercu(m)}
    <div class="aide">À joindre : ${echap(m.joindre.join(", "))}.</div>
    <div><button class="btn petit" data-a="copier">${icone("copier")} Copier le texte</button></div>`;
  $('[data-a="copier"]', c).onclick = () => copierTexte(`Objet : ${m.objet}\r\n\r\n${m.texte}`, "Mail copié dans le presse-papiers.");
}

// --- 5. Envoi : Outlook (brouillon), messagerie du PC ou fichier .eml ; puis case « Mail envoyé » ---
function etapeEnvoiBilan(c) {
  const envoye = BI.d.dossiers.find((x) => x.id === BI.courant)?.envoye;
  const m = BI.mail, v = BI.etat.valeurs, boite = BI.d.boite || BI.d.demo;
  c.innerHTML = `<div class="pdf-pret">${icone("mail")}<div><b>Mail prêt pour ${echap(v.email)}</b><br>
      <span>À joindre : ${echap(m.joindre.join(", "))}. Relire, joindre les pièces, envoyer, puis cocher « Mail envoyé ».</span></div></div>
    <div class="choix-envoi">
      <button class="btn ${boite ? "principal" : ""}" data-a="brouillon" ${boite ? "" : "disabled"}>${icone("mail")} Brouillon dans Outlook</button>
      <button class="btn ${boite ? "" : "principal"}" data-a="appli">${icone("ouvrir")} Ouvrir dans ma messagerie</button>
      <button class="btn" data-a="eml">${icone("telecharger")} Télécharger le mail (.eml)</button>
      <button class="btn fantome" data-a="copier">${icone("copier")} Copier le texte</button></div>
    <div class="aide">${boite ? "Brouillon Outlook : mise en forme et signature comprises." : "Outlook n'est pas connecté (facultatif, Paramètres)."}
      « Ouvrir dans ma messagerie » : texte simple, votre messagerie ajoute sa signature. Le .eml s'ouvre dans Outlook ou Thunderbird comme un nouveau mail, mise en forme comprise.</div>
    ${caseACocher("mail_envoye", "Mail envoyé", !!envoye)}`;
  $('[data-a="brouillon"]', c).onclick = async () => {
    try {
      const r = BI.d.demo ? await api("POST", "/api/bilan/brouillon", { valeurs: v }) : await ouvrirDansOutlook(api("POST", "/api/bilan/brouillon", { valeurs: v }));
      toast(r.message || "Brouillon créé dans Outlook : relisez-le, joignez les pièces et envoyez.", { duree: 6000 });
    } catch (e) { erreurAction(e); }
  };
  $('[data-a="appli"]', c).onclick = () => ouvrirAppMail({ a: v.email, cc: m.cc, objet: m.objet, texte: m.texte });
  $('[data-a="eml"]', c).onclick = async () => {
    try { toast(`${await telecharger("/api/bilan/eml", { valeurs: v })} téléchargé : ouvrez-le, joignez les pièces et envoyez.`, { duree: 6000 }); }
    catch (e) { signalerErreur(e); }
  };
  $('[data-a="copier"]', c).onclick = () => copierTexte(`Objet : ${m.objet}\r\n\r\n${m.texte}`, "Mail copié dans le presse-papiers.");
  $('[name="mail_envoye"]', c).onchange = async (ev) => {
    try {
      const r = await api("POST", "/api/bilan/envoye", { id: BI.courant, etat: ev.target.checked });
      const dossier = BI.d.dossiers.find((x) => x.id === BI.courant); if (dossier) dossier.envoye = r.envoye;
      BI.d.chiffres.envoyes = BI.d.dossiers.filter((x) => x.envoye).length;
      enteteBilan();
    } catch (e) { signalerErreur(e); }
  };
}

// ---------- Paramètres › Mail des comptes : les règles du cabinet pour pré-remplir le mail ----------
async function parametresBilan(c) {
  c.innerHTML = '<div class="chargement">Lecture…</div>';
  let p;
  try { p = (await api("GET", "/api/parametres")).bilan; } catch (e) { c.innerHTML = `<div class="alerte">${echap(e.message)}</div>`; return; }
  c.classList.add("parametres");
  c.innerHTML = `<div class="groupe"><div class="groupe-titre">MAIL DES COMPTES ANNUELS · RÈGLES DU CABINET</div>
      ${champ("Missions", "Missions facturées (proposées par défaut)", saisie("Missions", p.Missions), "{exercice} : 2025 ou 2025-2026 selon l'exercice du dossier. « … et le dépôt des comptes au greffe » est retiré tout seul pour les dossiers sans dépôt (société civile, entrepreneur individuel…).")}
      ${champ("Reglement", "Règlement de la facture (proposé par défaut)", saisie("Reglement", p.Reglement))}
      ${champ("Copie", "Copie du cabinet (Cc) sur tous les mails", saisie("Copie", p.Copie, 'placeholder="Vide : personne en copie"'), "Plusieurs adresses : séparer par une virgule. La copie propre à un client se règle dans sa fiche.")}</div>
    <div><button class="btn principal" data-a="enreg">Enregistrer</button></div>`;
  $('[data-a="enreg"]', c).onclick = async () => {
    try {
      const r = await api("POST", "/api/bilan/parametres", valeurs(c));
      erreursChamps(c, {});
      for (const [k, x] of Object.entries(r)) { const z = $(`[name="${k}"]`, c); if (z) z.value = x; }
      if (BI.d) BI.d.parametres = r;
      toast("Règles du mail enregistrées.");
    } catch (e) { if (e.champs) erreursChamps(c, e.champs); else signalerErreur(e); }
  };
}
