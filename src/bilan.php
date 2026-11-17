<?php
/**
 * Bilan : mail d'envoi des comptes annuels. Dossiers du cabinet (liste + infos lues dans Pennylane par l'API au moment où
 * on choisit le dossier), formulaire pré-rempli selon les règles du cabinet, modifications gardées par dossier, mail rédigé
 * sans rien inventer (une donnée obligatoire absente bloque la rédaction et dit quoi compléter).
 *
 * data/cabinet/liste.json : fiches du cabinet, facultatives (contact du client, associés, catégorie fiscale, « Tu » ou
 * « Vous ») : l'API Pennylane ne donne pas le contact du client ; sans fiche, il se saisit dans le formulaire.
 * data/cabinet/dossiers/<id>.json (infos lues par l'API) ; data/bilan/saisies.json (formulaire modifié à la main, par
 * dossier) ; data/bilan/envois.json (case « Mail envoyé »).
 */
declare(strict_types=1);

const BILAN_DEFAUTS = [
    'Copie' => '',  // Cc des mails de bilan ; vide = personne
    'Missions' => "la tenue comptable {exercice}, l'établissement des comptes annuels {exercice} et le dépôt des comptes au greffe",
    'Reglement' => 'prochainement prélevée sur le compte bancaire de la société',
];
const BILAN_STATUTS_IS = ['a_payer' => 'Solde à payer', 'neant' => 'Néant', 'creance' => 'Créance à rembourser'];
const BILAN_GENRES = ['mister' => 'H', 'madam' => 'F', 'm' => 'H', 'mr' => 'H', 'monsieur' => 'H', 'male' => 'H',
    'mme' => 'F', 'madame' => 'F', 'mrs' => 'F', 'ms' => 'F', 'miss' => 'F', 'female' => 'F'];

final class ErreurFormulaire extends Exception
{
    public function __construct(public array $manques) { parent::__construct(implode(' ; ', $manques)); }
}

function bilan_parametres(): array
{
    $p = lire_json(chemin_data('bilan', 'parametres.json'), []);
    $out = [];
    foreach (BILAN_DEFAUTS as $k => $v) {
        $out[$k] = (string)($p[$k] ?? $v);
    }
    return $out;
}

function txt($v): string
{
    return $v === null ? '' : trim((string)$v);
}

function bilan_normaliser(array $b): array
{
    $contact = is_array($b['contact'] ?? null) ? $b['contact'] : [];
    $r = is_array($b['resultat'] ?? null) ? $b['resultat'] : null;
    return [
        'extrait' => '', 'collaborateur' => txt($b['collaborateur'] ?? null), 'id' => txt($b['pennylane_id'] ?? ($b['id'] ?? null)),
        'nom' => txt($b['nom'] ?? null), 'siren' => txt($b['siren'] ?? null), 'forme_juridique' => txt($b['forme_juridique'] ?? null),
        'categorie_fiscale' => mb_strtolower(txt($b['categorie_fiscale'] ?? null)), 'regime_fiscal' => txt($b['regime_fiscal'] ?? null),
        'frequence_tva' => mb_strtolower(txt($b['frequence_tva'] ?? null)),
        'exercices' => array_values(array_map(fn($e) => ['debut' => txt($e['debut'] ?? null), 'fin' => txt($e['fin'] ?? null), 'statut' => txt($e['statut'] ?? null)],
            array_filter((array)($b['exercices'] ?? []), 'is_array'))),
        'contact' => ['prenom' => txt($contact['prenom'] ?? null), 'nom' => txt($contact['nom'] ?? null), 'email' => txt($contact['email'] ?? null)],
        'cc' => txt($b['cc'] ?? null),
        'formule' => in_array(txt($b['formule'] ?? null), ['Tu', 'Vous'], true) ? txt($b['formule']) : '',
        'associes' => array_values(array_map(fn($a) => ['prenom' => txt($a['prenom'] ?? null), 'nom' => txt($a['nom'] ?? null), 'genre' => mb_strtolower(txt($a['genre'] ?? null))],
            array_filter((array)($b['associes'] ?? []), 'is_array'))),
        'comptes' => array_values(array_map(fn($c) => ['banque' => txt($c['banque'] ?? null), 'libelle' => txt($c['libelle'] ?? null),
            'iban' => str_replace(' ', '', txt($c['iban'] ?? null)), 'solde' => txt($c['solde'] ?? null)],
            array_filter((array)($b['comptes'] ?? []), fn($c) => is_array($c) && (txt($c['iban'] ?? null) !== '' || txt($c['banque'] ?? null) !== '')))),
        'resultat' => $r === null ? [] : array_combine(['debut', 'fin', 'resultat_net', 'impot_societes', 'loyers_6132', 'indemnite_occupation'],
            array_map(fn($k) => txt($r[$k] ?? null), ['debut', 'fin', 'resultat_net', 'impot_societes', 'loyers_6132', 'indemnite_occupation'])),
        'civilite_contact' => in_array(txt($contact['civilite'] ?? null), ['M', 'Mme'], true) ? txt($contact['civilite']) : '',
        'source' => txt($b['source'] ?? null),  // api (lu dans Pennylane), import (fichier CSV / Excel), manuel ; vide : fiche du cabinet
    ];
}

/** D'où viennent les chiffres du dossier, pour les phrases d'aide (« D'après Pennylane : … »). */
function bilan_source(array $d): string
{
    return ['api' => 'Pennylane', 'import' => 'le fichier importé'][$d['source']] ?? 'la fiche';
}

/** {id: dossier}. Base : les fiches du cabinet ; complétées par les infos lues dans Pennylane (dont les valeurs non vides
 *  priment) et par les dossiers du cabinet de Pennylane. */
function bilan_dossiers(array $cabinet = []): array
{
    $dossiers = [];
    foreach ((array)lire_json(chemin_data('cabinet', 'liste.json'), []) as $brut) {
        if (is_array($brut)) {
            $d = bilan_normaliser($brut);
            if ($d['id'] !== '' && $d['nom'] !== '') {
                $d['extrait'] = txt($brut['importe_le'] ?? null);  // chiffres importés d'un fichier : déjà « lus »
                $dossiers[$d['id']] = $d;
            }
        }
    }
    foreach (glob(chemin_data('cabinet', 'dossiers', '*.json')) ?: [] as $f) {
        $brut = lire_json($f, null);
        if (!is_array($brut)) {
            continue;
        }
        $detail = bilan_normaliser($brut);
        if ($detail['id'] === '' || $detail['nom'] === '') {
            continue;
        }
        $base = $dossiers[$detail['id']] ?? [];
        $vide = fn($v) => is_array($v) ? implode('', array_map(fn($x) => is_array($x) ? json_encode($x) : (string)$x, $v)) === '' : !$v;
        foreach ($base as $k => $v) {
            if (!$vide($v) && $vide($detail[$k])) {  // contact sans aucune valeur (l'API n'en donne pas) : celui de la fiche
                $detail[$k] = $v;
            }
        }
        $detail['extrait'] = date('d/m/Y', (int)filemtime($f));
        $dossiers[$detail['id']] = $detail;
    }
    foreach ($cabinet as $c) {  // un nouveau client de Pennylane apparaît sans autre lecture
        if (!isset($dossiers[$c['id']])) {
            $dossiers[$c['id']] = bilan_normaliser(['pennylane_id' => $c['id'], 'nom' => $c['nom'], 'siren' => $c['siren']]);
        }
    }
    uasort($dossiers, fn($a, $b) => strcasecmp($a['nom'], $b['nom']));
    return $dossiers;
}

function soumis_is(array $d): bool
{
    return in_array('is', explode('_', $d['categorie_fiscale']), true);  // bic_is, bnc_is… ; bic_ir, bnc, vide : non
}

function tva_annuelle(array $d): bool
{
    return in_array($d['frequence_tva'], ['yearly', 'annual', 'annually'], true);
}

/** [début, fin] du dernier exercice terminé (fin <= aujourd'hui) : celui dont on envoie les comptes. */
function exercice_a_envoyer(array $d, ?string $aujourdhui = null): array
{
    $aujourdhui ??= aujourdhui();
    $meilleur = [null, null];
    foreach ($d['exercices'] as $e) {
        $fin = lire_date($e['fin']);
        if ($fin && $fin <= $aujourdhui && (!$meilleur[1] || $fin > $meilleur[1])) {
            $meilleur = [lire_date($e['debut']), $fin];
        }
    }
    return $meilleur;
}

/** H / F : civilité du contact enregistrée dans sa fiche, sinon celle de l'associé qui porte son nom ; "" si inconnue. */
function genre_contact(array $d): string
{
    if (($d['civilite_contact'] ?? '') !== '') {
        return $d['civilite_contact'] === 'M' ? 'H' : 'F';
    }
    $c = $d['contact'];
    $norm = fn($s) => implode(' ', preg_split('/\s+/', mb_strtolower(trim($s)), -1, PREG_SPLIT_NO_EMPTY));
    foreach ($d['associes'] as $a) {
        if ($a['nom'] !== '' && $norm($a['nom']) === $norm($c['nom']) && ($c['prenom'] === '' || $norm($a['prenom']) === $norm($c['prenom']))) {
            return BILAN_GENRES[$a['genre']] ?? '';
        }
    }
    return '';
}

/** Comptes sans doublon, dans l'ordre de Pennylane : [{banque, libelle, iban, solde (centimes ou null)}]. Regroupés par IBAN,
 *  ou par libellé quand l'IBAN manque (l'API ne le donne pas) ; solde actuel, indicatif. */
function comptes_uniques(array $d): array
{
    $groupes = [];
    foreach ($d['comptes'] as $c) {
        $solde = lire_montant($c['solde']);
        $cle = $c['iban'] !== '' ? $c['iban'] : $c['banque'] . '|' . $c['libelle'];
        if (!isset($groupes[$cle])) {
            $groupes[$cle] = ['banque' => $c['banque'], 'libelle' => $c['libelle'], 'iban' => $c['iban'], 'solde' => $solde];
        } elseif ($solde !== null) {
            $groupes[$cle]['solde'] = $solde + ($groupes[$cle]['solde'] ?? 0);
        }
    }
    return array_values($groupes);
}

function nom_compte(array $c): string
{
    return $c['banque'] . ($c['libelle'] !== '' ? " ({$c['libelle']})" : '');
}

/** Solde (indicatif) du compte choisi : par IBAN s'il est connu, sinon somme des comptes de cette banque. null si inconnu. */
function solde_banque(array $d, string $banque, string $iban = ''): ?int
{
    $iban = str_replace(' ', '', $iban);
    $soldes = [];
    foreach (comptes_uniques($d) as $c) {
        if (($iban !== '' ? $c['iban'] === $iban : $c['banque'] === trim($banque)) && $c['solde'] !== null) {
            $soldes[] = $c['solde'];
        }
    }
    return $soldes ? array_sum($soldes) : null;
}

/** Comptes déposés au greffe : sociétés commerciales. Pas pour un entrepreneur individuel, une société civile, une
 *  indivision, une association ou un LMNP. Forme inconnue : oui (cas le plus courant). */
function depot_au_greffe(array $d): bool
{
    $forme = mb_strtolower($d['forme_juridique']);
    foreach (['entrepreneur', 'individuel', 'civile', 'indivision', 'association', 'lmnp'] as $m) {
        if (str_contains($forme, $m)) {
            return false;
        }
    }
    return true;
}

/** « …, l'établissement des comptes annuels 2025 et le dépôt des comptes au greffe » -> « … et l'établissement… 2025 ». */
function missions_sans_greffe(string $texte): string
{
    $fin = ' et le dépôt des comptes au greffe';
    if (!str_ends_with($texte, $fin)) {
        return $texte;
    }
    $texte = substr($texte, 0, -strlen($fin));
    $pos = strrpos($texte, ', ');
    return $pos === false ? $texte : substr($texte, 0, $pos) . ' et ' . substr($texte, $pos + 2);
}

/** Montant arrondi à l'euro pour la déclaration de revenus : 4 789,62 -> « 4 790 ». */
function montant_entier(int $c): string
{
    return montant_saisie((int)(round($c / 100) * 100));
}

function libelle_exercice(?string $debut, ?string $fin): string
{
    if (!$fin) {
        return '';
    }
    return (!$debut || substr($debut, 0, 4) === substr($fin, 0, 4)) ? substr($fin, 0, 4) : substr($debut, 0, 4) . '-' . substr($fin, 0, 4);
}

/** [résultat net, IS] de l'exercice clos à $fin, lus dans Pennylane ; [null, null] si absents. */
function resultat_exercice(array $d, ?string $fin): array
{
    $r = $d['resultat'];
    if (!$r || !$fin || lire_date($r['fin'] ?? '') !== $fin) {
        return [null, null];
    }
    return [lire_montant($r['resultat_net']), lire_montant($r['impot_societes'])];
}

/** [loyers 6132, indemnité d'occupation] de l'exercice clos à $fin ; [null, null] si absents. */
function loyers_exercice(array $d, ?string $fin): array
{
    $r = $d['resultat'];
    if (!$r || !$fin || lire_date($r['fin'] ?? '') !== $fin) {
        return [null, null];
    }
    return [lire_montant($r['loyers_6132']), lire_montant($r['indemnite_occupation'])];
}

/** Valeurs proposées pour le formulaire ; $formule_connue : "Tu" / "Vous" de la fiche du client (tutoiement par défaut). */
function formulaire_initial(array $d, array $p, ?string $formule_connue = null): array
{
    [$debut, $fin] = exercice_a_envoyer($d);
    $exercice = libelle_exercice($debut, $fin);
    $comptes = comptes_uniques($d);
    [$resultat, $impot] = resultat_exercice($d, $fin);
    // Catégorie fiscale inconnue (fiche sans régime fiscal) : un IS dans la balance suffit
    $is_soumis = soumis_is($d) || ($d['categorie_fiscale'] === '' && $impot !== null && $impot !== 0);
    $statut_is = 'a_payer';
    $montant_is = '';
    if ($is_soumis && $impot !== null) {
        $statut_is = $impot === 0 ? 'neant' : ($impot < 0 ? 'creance' : 'a_payer');
        $montant_is = $impot === 0 ? '' : montant_saisie(abs($impot));
    }
    // Compte proposé : le premier qui a de l'argent (les comptes à 0 € sont souvent inutilisés)
    $compte = null;
    foreach ($comptes as $c) {
        if ($c['solde']) {
            $compte = $c;
            break;
        }
    }
    $compte ??= $comptes[0] ?? null;
    // Compte à alimenter : proposé si l'IS est à payer et que le solde (indicatif) ne le couvre pas
    $solde = $compte ? solde_banque($d, $compte['banque'], $compte['iban']) : null;
    $alimenter = $is_soumis && $compte && $statut_is === 'a_payer' && !($impot !== null && $solde !== null && $solde >= $impot);
    $greffe = depot_au_greffe($d);
    $missions = $exercice !== '' ? str_replace('{exercice}', $exercice, $p['Missions']) : '';
    if (!$greffe) {
        $missions = missions_sans_greffe($missions);
    }
    // Indemnité d'occupation : cochée si la balance la nomme ; sinon montant des loyers 6132 proposé (case décochée)
    [$loyers, $occupation] = loyers_exercice($d, $fin);
    $indemnite = $occupation !== null && $occupation > 0;
    $montant_indemnite = ($indemnite || $loyers) ? montant_entier($indemnite ? $occupation : $loyers) : '';
    return [
        'prenom' => $d['contact']['prenom'], 'genre' => genre_contact($d), 'appel' => 'Bonjour', 'tu' => $formule_connue !== 'Vous',
        'nom_contact' => $d['contact']['nom'], 'email' => $d['contact']['email'], 'cc' => $d['cc'], 'societe' => $d['nom'], 'date_cloture' => $fin ?? '',
        'is_soumis' => $is_soumis, 'statut_is' => $statut_is, 'montant_is' => $montant_is, 'tva_annuelle' => tva_annuelle($d),
        'facture' => true, 'missions_facture' => $missions, 'reglement_facture' => $p['Reglement'],
        'afficher_resultat' => $resultat !== null, 'resultat_net' => $resultat !== null ? montant_saisie($resultat) : '',
        'alimenter_compte' => (bool)$alimenter, 'banque' => $compte['banque'] ?? '', 'iban' => $compte['iban'] ?? '',
        'indemnite' => $indemnite, 'montant_indemnite' => $montant_indemnite, 'annee_declaration' => $fin ? (string)((int)substr($fin, 0, 4) + 1) : '',
        'docs_juridiques' => $greffe,
    ];
}

/** Ce que les chiffres du dossier disent sous chaque case (« D'après Pennylane : … »), trésorerie et résultat lus. */
function bilan_indications(array $d, array $v): array
{
    $src = bilan_source($d);
    $cat = $d['categorie_fiscale'];
    $noms_cat = ['bic_is' => 'IS', 'bic_is_gt' => 'IS', 'bic_ir' => 'IR (BIC)', 'bnc' => 'IR (BNC)'];
    $is = $cat === '' ? 'Catégorie fiscale inconnue : IS déduit de la balance (compte 695).'
        : (soumis_is($d) ? "D'après $src : société à l'" . ($noms_cat[$cat] ?? 'IS') . '.'
            : "D'après $src : " . ($noms_cat[$cat] ?? mb_strtoupper($cat)) . ", pas de déclaration d'IS.");
    $freq = ['monthly' => 'mensuelle', 'quarterly' => 'trimestrielle', 'yearly' => 'annuelle', 'not_applicable' => 'non assujettie'][$d['frequence_tva']]
        ?? ($d['frequence_tva'] ?: 'inconnue');
    $fin = lire_date($v['date_cloture'] ?? '');
    [$loyers, $occupation] = loyers_exercice($d, $fin);
    if ($occupation) {
        $indemnite = "Indemnité d'occupation dans la balance : " . euros($occupation) . '.';
    } elseif ($loyers) {
        $indemnite = 'Loyers 6132 de l\'exercice : ' . euros($loyers) . ". Si c'est une indemnité d'occupation versée au dirigeant, coche la case (montant pré-rempli).";
    } else {
        $indemnite = $d['extrait'] !== '' ? "Ni loyer ni indemnité d'occupation dans la balance." : '';
    }
    $forme = $d['forme_juridique'] ?: 'Forme juridique inconnue';
    $juridique = "$forme : " . (depot_au_greffe($d) ? 'comptes déposés au greffe.' : 'pas de dépôt des comptes au greffe.');
    // Trésorerie totale (indicatif) et solde du compte choisi face à l'IS à payer
    $connus = array_values(array_filter(comptes_uniques($d), fn($x) => $x['solde'] !== null));
    if ($d['extrait'] === '') {
        $treso = ['texte' => 'Trésorerie : lue dans Pennylane au choix du dossier, ou dans le fichier importé.', 'lignes' => []];
    } elseif (!$connus) {
        $treso = ['texte' => "Trésorerie : aucun compte bancaire d'après $src.", 'lignes' => []];
    } else {
        $pleins = array_values(array_filter($connus, fn($x) => $x['solde']));
        $lignes = array_map(fn($x) => nom_compte($x) . ' : ' . euros($x['solde']), $pleins);
        if (count($connus) > count($pleins)) {
            $lignes[] = (count($connus) - count($pleins)) . ' autre(s) compte(s) à 0 €';
        }
        $treso = ['texte' => 'Trésorerie actuelle : ' . euros(array_sum(array_column($connus, 'solde'))) . ' (indicatif)', 'lignes' => $lignes];
    }
    $solde = solde_banque($d, (string)($v['banque'] ?? ''), (string)($v['iban'] ?? ''));
    $etat_solde = 'neutre';
    if ($d['extrait'] === '') {
        $texte_solde = 'Solde du compte : inconnu (ni Pennylane ni fichier importé).';
    } elseif ($solde === null) {
        $texte_solde = 'Solde du compte : inconnu (choisis un compte de la liste).';
    } else {
        $texte_solde = 'Solde actuel ' . trim((string)$v['banque']) . ' : ' . euros($solde) . ' (indicatif)';
        $impot = lire_montant($v['montant_is'] ?? '');
        if (($v['statut_is'] ?? '') === 'a_payer' && $impot) {
            if ($solde < $impot) {
                $texte_solde .= ". Insuffisant pour l'IS de " . euros($impot) . ' : à alimenter.';
                $etat_solde = 'rouge';
            } else {
                $texte_solde .= ". Couvre l'IS de " . euros($impot) . '.';
                $etat_solde = 'vert';
            }
        }
    }
    [$resultat] = resultat_exercice($d, $fin);
    $texte_resultat = $resultat === null ? 'Résultat : pas encore connu pour cet exercice (à saisir).'
        : "Résultat d'après $src : " . euros($resultat) . ($resultat < 0 ? ". Perte : le mail dira qu'il n'y a rien à affecter." : '.');
    $infos = array_values(array_filter([$d['forme_juridique'], $d['siren'] ? "SIREN {$d['siren']}" : '',
        soumis_is($d) ? 'IS' : mb_strtoupper($d['categorie_fiscale']),
        $d['frequence_tva'] ? 'TVA ' . (['monthly' => 'mensuelle', 'quarterly' => 'trimestrielle', 'yearly' => 'annuelle', 'not_applicable' => 'non assujettie'][$d['frequence_tva']] ?? $d['frequence_tva']) : '']));
    return ['is' => $is, 'tva' => "D'après $src : TVA $freq.", 'indemnite' => $indemnite, 'juridique' => $juridique, 'tresorerie' => $treso,
        'solde' => ['texte' => $texte_solde, 'etat' => $etat_solde], 'resultat' => $texte_resultat, 'infos' => $infos,
        'comptes' => array_map(fn($c) => $c + ['nom' => nom_compte($c), 'solde_texte' => $c['solde'] !== null ? euros($c['solde']) : ''], comptes_uniques($d))];
}

// ---------- Rédaction du mail ----------

function enumeration_ainsi(array $elements): string
{
    if (count($elements) === 1) {
        return $elements[0];
    }
    return implode(', ', array_slice($elements, 0, -1)) . ' ainsi que ' . end($elements);
}

/** [objet, texte, html, pièces à joindre] ; ErreurFormulaire si une donnée obligatoire manque. */
function bilan_rediger(array $v): array
{
    $manques = [];
    $prenom = trim((string)($v['prenom'] ?? ''));
    $societe = trim((string)($v['societe'] ?? ''));
    $cloture = lire_date($v['date_cloture'] ?? '');
    if ($prenom === '') {
        $manques[] = 'prénom du client';
    }
    if ($societe === '') {
        $manques[] = 'nom de la société';
    }
    if (!$cloture) {
        $manques[] = 'date de clôture';
    }
    if (($v['appel'] ?? '') === 'Cher' && !in_array($v['genre'] ?? '', ['H', 'F'], true)) {
        $manques[] = 'genre du client (pour « Cher / Chère »)';
    }
    $montant_impot = lire_montant($v['montant_is'] ?? '');
    $statut_is = ($v['statut_is'] ?? '') ?: 'a_payer';
    if (!empty($v['is_soumis']) && $statut_is !== 'neant' && $montant_impot === null) {
        $manques[] = "montant de l'IS";
    }
    $resultat = lire_montant($v['resultat_net'] ?? '');
    if (!empty($v['afficher_resultat']) && $resultat === null) {
        $manques[] = 'résultat net comptable';
    }
    if (!empty($v['facture']) && trim((string)($v['missions_facture'] ?? '')) === '') {
        $manques[] = 'missions facturées';
    }
    $is_a_payer = !empty($v['is_soumis']) && $statut_is === 'a_payer' && $montant_impot !== null && $montant_impot !== 0;
    $alimenter = !empty($v['alimenter_compte']) && $is_a_payer;
    if ($alimenter && trim((string)($v['banque'] ?? '')) === '') {  // IBAN facultatif : l'API Pennylane ne le donne pas
        $manques[] = 'banque du compte à alimenter';
    }
    $indemnite = lire_montant($v['montant_indemnite'] ?? '');
    if (!empty($v['indemnite']) && $indemnite === null) {
        $manques[] = "montant de l'indemnité d'occupation";
    }
    if (!empty($v['indemnite']) && trim((string)($v['annee_declaration'] ?? '')) === '') {
        $manques[] = 'année de la déclaration de revenus';
    }
    if ($manques) {
        throw new ErreurFormulaire($manques);
    }

    $tu = !empty($v['tu']);
    [$te, $ta, $ton] = $tu ? ['te', 'ta', 'ton'] : ['vous', 'votre', 'votre'];
    $clos = "au titre de l'exercice comptable clos au " . date_longue($cloture);
    $pieces = ["La plaquette afférente aux comptes annuels de la société $societe $clos", "La liasse fiscale de la société $clos (incluse dans la plaquette)"];
    if (!empty($v['is_soumis'])) {
        if ($statut_is === 'neant' || $montant_impot === 0) {
            $detail = 'néant';
        } elseif ($statut_is === 'creance') {
            $detail = 'créance de ' . euros($montant_impot, false) . ' à rembourser';
        } else {
            $detail = 'solde de ' . euros($montant_impot, false) . ' à payer';
        }
        $pieces[] = "La déclaration afférente à l'impôt sur les sociétés $clos ($detail)";
    }
    if (!empty($v['tva_annuelle'])) {
        $pieces[] = "La déclaration annuelle de TVA $clos";
    }
    if (!empty($v['facture'])) {
        $reglement = trim((string)($v['reglement_facture'] ?? ''));
        $pieces[] = "La facture d'honoraires pour " . trim((string)$v['missions_facture']) . ($reglement !== '' ? " ($reglement)" : '');
    }
    $pieces = array_map(fn($p, $i) => $p . ($i === count($pieces) - 1 ? '.' : ','), $pieces, array_keys($pieces));

    $paragraphes = [];  // [type, contenu] : « texte » ou « bloc » (lignes de l'indemnité d'occupation)
    if (!empty($v['afficher_resultat'])) {
        $paragraphes[] = $resultat < 0
            ? ['texte', "Le résultat de l'exercice est négatif : la société enregistre une perte nette comptable de " . euros($resultat, false) . '. Il n\'y a donc rien à affecter.']
            : ['texte', 'Le résultat net comptable de la société s\'élève à ' . euros($resultat) . '.'];
    }
    if ($alimenter) {
        $iban = trim((string)($v['iban'] ?? ''));
        $paragraphes[] = ['texte', "J'attire $ton attention sur le fait qu'il convient d'alimenter le compte bancaire " . trim((string)$v['banque'])
            . ($iban !== '' ? " ($iban)" : '') . " afin de régler l'échéance à venir d'impôt sur les sociétés."];
    }
    if (!empty($v['indemnite'])) {
        $m = euros($indemnite);
        $paragraphes[] = ['texte', "Pour information, voici le montant à reporter dans $ta déclaration de revenus " . trim((string)$v['annee_declaration'])
            . " (case 4BE) concernant l'indemnité d'occupation versée par la société" . NBSP . ':'];
        $paragraphes[] = ['bloc', ['Formulaire 2042, revenus fonciers (locations non meublées)',
            'Case 4BE, recettes brutes (micro-foncier, ≤ 15' . NBSP . '000' . NBSP . '€)' . NBSP . ": $m",
            "dont indemnité d'occupation du siège social" . NBSP . ": $m",
            "Ces revenus sont imposés au barème de l'impôt sur le revenu et aux prélèvements sociaux, après un abattement de 30 %."]];
    }
    $transmis = ['la liasse fiscale'];
    if (!empty($v['is_soumis'])) {
        $transmis[] = "la déclaration d'IS";
    }
    if (!empty($v['tva_annuelle'])) {
        $transmis[] = 'la déclaration annuelle de TVA';
    }
    $debut = $tu ? "Je t'informe" : 'Je vous informe';
    if (count($transmis) === 1) {
        $paragraphes[] = ['texte', "$debut que la liasse fiscale a été transmise à l'Administration fiscale par voie électronique."];
    } elseif (count($transmis) === 2) {
        $paragraphes[] = ['texte', "$debut que la liasse fiscale, ainsi que {$transmis[1]}, ont été transmises à l'Administration fiscale par voie électronique."];
    } else {
        $paragraphes[] = ['texte', "$debut que " . enumeration_ainsi($transmis) . " ont été transmises à l'Administration fiscale par voie électronique."];
    }
    if (!empty($v['docs_juridiques'])) {
        $paragraphes[] = ['texte', ($tu ? "Je t'envoie" : 'Je vous envoie') . ' prochainement les documents juridiques liés au dépôt des comptes annuels.'];
    }
    $paragraphes[] = ['texte', "Je $te souhaite bonne réception de ces éléments et reste à $ta disposition pour toute information ou précision qui $te serait utile."];

    $appel = ($v['appel'] ?? '') === 'Cher' ? (($v['genre'] ?? '') === 'H' ? 'Cher ' : 'Chère ') . "$prenom," : "Bonjour $prenom,";
    $intro = "Je $te prie de bien vouloir trouver ci-joint" . NBSP . ':';
    $fin = $tu ? 'Bien à toi,' : 'Bien à vous,';
    $objet = "Comptes annuels $societe, exercice clos au " . date_longue($cloture);

    $lignes = array_merge([$appel, '', $intro, ''], array_map(fn($p) => "- $p", $pieces));
    foreach ($paragraphes as [$genre, $contenu]) {
        $lignes[] = '';
        array_push($lignes, ...($genre === 'bloc' ? $contenu : [$contenu]));
    }
    array_push($lignes, '', $fin);
    $texte = typographie(implode("\r\n", $lignes));
    $e = fn($s) => htmlspecialchars(typographie($s), ENT_QUOTES, 'UTF-8');
    $corps = ['<p>' . $e($appel) . '</p>', '<p>' . $e($intro) . '</p>',
        '<ul>' . implode('', array_map(fn($p) => '<li style="margin-bottom:6px">' . $e($p) . '</li>', $pieces)) . '</ul>'];
    foreach ($paragraphes as [$genre, $contenu]) {
        if ($genre === 'bloc') {
            $corps[] = '<p style="margin-left:18px; padding-left:10px; border-left:3px solid #6662FF"><b>' . $e($contenu[0]) . '</b><br>'
                . implode('<br>', array_map($e, array_slice($contenu, 1, -1))) . '<br><i>' . $e(end($contenu)) . '</i></p>';
        } else {
            $corps[] = '<p>' . $e($contenu) . '</p>';
        }
    }
    $corps[] = '<p>' . $e($fin) . '</p>';
    $page = '<div style="font-family: Aptos, Calibri, Arial, sans-serif; font-size: 11pt;">' . implode('', $corps) . '</div>';
    $joindre = ['plaquette (avec la liasse)'];
    if (!empty($v['is_soumis'])) {
        $joindre[] = "déclaration d'IS";
    }
    if (!empty($v['tva_annuelle'])) {
        $joindre[] = 'déclaration annuelle de TVA';
    }
    if (!empty($v['facture'])) {
        $joindre[] = "facture d'honoraires";
    }
    return [typographie($objet), $texte, $page, $joindre];
}

// ---------- Fiche du client : coordonnées gardées d'un mail à l'autre ----------

/** Champs du formulaire qui sont des coordonnées du client : enregistrés dans sa fiche (data/cabinet/liste.json), pas comme
 *  une modification de ce seul mail. */
const BILAN_COORDONNEES = ['prenom', 'nom_contact', 'email', 'cc', 'genre', 'tu'];

/** Met à jour la fiche du client avec ces coordonnées (fiche créée si le dossier ne vient que de Pennylane). */
function bilan_fiche_enregistrer(string $id, string $nom, array $v): void
{
    sous_verrou(function () use ($id, $nom, $v) {
        $liste = array_values(array_filter((array)lire_json(chemin_data('cabinet', 'liste.json'), []), 'is_array'));
        $place = null;
        foreach ($liste as $i => $f) {
            if ((string)($f['pennylane_id'] ?? $f['id'] ?? '') === $id) {
                $place = $i;
            }
        }
        $fiche = $place !== null ? $liste[$place] : ['pennylane_id' => $id, 'nom' => $nom];
        $contact = (array)($fiche['contact'] ?? []);
        foreach (['prenom' => 'prenom', 'nom_contact' => 'nom', 'email' => 'email'] as $k => $cle) {
            if (array_key_exists($k, $v)) {
                $contact[$cle] = trim((string)$v[$k]);
            }
        }
        if (array_key_exists('genre', $v)) {
            $contact['civilite'] = ['H' => 'M', 'F' => 'Mme'][$v['genre']] ?? '';
        }
        $fiche['contact'] = $contact;
        if (array_key_exists('cc', $v)) {
            $fiche['cc'] = trim((string)$v['cc']);
        }
        if (array_key_exists('tu', $v)) {
            $fiche['formule'] = !empty($v['tu']) ? 'Tu' : 'Vous';
        }
        if ($place !== null) {
            $liste[$place] = $fiche;
        } else {
            $liste[] = $fiche;
        }
        ecrire_json(chemin_data('cabinet', 'liste.json'), $liste);
    });
}

/** Exercice d'un dossier saisi à la main (ou importé sans date) : clôture saisie dans le formulaire, ouverture un an avant. */
function bilan_fiche_exercice(string $id, string $nom, string $fin): void
{
    sous_verrou(function () use ($id, $nom, $fin) {
        $liste = array_values(array_filter((array)lire_json(chemin_data('cabinet', 'liste.json'), []), 'is_array'));
        $place = null;
        foreach ($liste as $i => $f) {
            if ((string)($f['pennylane_id'] ?? $f['id'] ?? '') === $id) {
                $place = $i;
            }
        }
        $fiche = $place !== null ? $liste[$place] : ['pennylane_id' => $id, 'nom' => $nom];
        $fiche['exercices'] = [['debut' => date('Y-m-d', strtotime("$fin -1 year +1 day")), 'fin' => $fin, 'statut' => '']];
        if ($place !== null) {
            $liste[$place] = $fiche;
        } else {
            $liste[] = $fiche;
        }
        ecrire_json(chemin_data('cabinet', 'liste.json'), $liste);
    });
}

/** Adresses e-mail d'un champ « a@x.fr, b@y.fr ; … » (les invalides sont ignorées). */
function adresses(string $texte): array
{
    return array_values(array_filter(array_map('trim', preg_split('/[;,]/', $texte) ?: []), fn($x) => filter_var($x, FILTER_VALIDATE_EMAIL)));
}

// ---------- Import d'un fichier (sans Pennylane) : liste des clients et chiffres de l'exercice ----------

/** Colonnes reconnues du fichier (voir exemples/dossiers.csv) ; seule « societe » est obligatoire. */
const BILAN_COLONNES = ['societe', 'id_pennylane', 'siren', 'forme_juridique', 'regime', 'tva', 'debut_exercice', 'cloture', 'prenom', 'nom',
    'email', 'cc', 'civilite', 'tutoiement', 'resultat_net', 'impot_societes', 'loyers_6132', 'indemnite_occupation', 'banque', 'solde_banque'];
const BILAN_ALIAS = ['raison_sociale' => 'societe', 'entreprise' => 'societe', 'dossier' => 'societe', 'date_cloture' => 'cloture',
    'fin_exercice' => 'cloture', 'debut' => 'debut_exercice', 'mail' => 'email', 'e_mail' => 'email', 'copie' => 'cc', 'formule' => 'tutoiement',
    'resultat' => 'resultat_net', 'is' => 'impot_societes', 'impot' => 'impot_societes', 'loyers' => 'loyers_6132',
    'indemnite' => 'indemnite_occupation', 'solde' => 'solde_banque', 'categorie_fiscale' => 'regime', 'frequence_tva' => 'tva'];

/** Texte d'un fichier reçu (CSV enregistré par Excel en Windows-1252, BOM, UTF-16) -> UTF-8 propre. */
function en_utf8(string $texte): string
{
    $texte = str_starts_with($texte, "\xEF\xBB\xBF") ? substr($texte, 3) : $texte;
    if (str_starts_with($texte, "\xFF\xFE") || str_starts_with($texte, "\xFE\xFF")) {  // UTF-16 (« Texte Unicode » d'Excel)
        return (string)mb_convert_encoding(substr($texte, 2), 'UTF-8', str_starts_with($texte, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
    }
    return mb_check_encoding($texte, 'UTF-8') ? $texte : (string)mb_convert_encoding($texte, 'UTF-8', 'Windows-1252');
}

/** Texte CSV -> lignes. Séparateur « ; », « , » ou tabulation deviné sur la 1re ligne ; un retour à la ligne entre guillemets
 *  reste dans sa cellule. Lignes vides ignorées. */
function lire_csv(string $texte): array
{
    $texte = trim($texte);
    if ($texte === '') {
        return [];
    }
    $premiere = strtok($texte, "\r\n") ?: '';
    $comptes = [';' => substr_count($premiere, ';'), ',' => substr_count($premiere, ','), "\t" => substr_count($premiere, "\t")];
    arsort($comptes);
    $sep = (string)array_key_first($comptes);
    $f = fopen('php://temp', 'r+');
    fwrite($f, $texte);
    rewind($f);
    $out = [];
    while (($v = fgetcsv($f, null, $sep, '"', '')) !== false) {
        if ($v !== [null] && implode('', array_map('strval', $v)) !== '') {
            $out[] = array_map(fn($x) => trim((string)$x), $v);
        }
    }
    fclose($f);
    return $out;
}

/** Première feuille d'un classeur Excel (.xlsx) -> lignes de cellules (texte), sans bibliothèque : le .xlsx est un zip de XML. */
function lire_xlsx(string $octets): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($tmp, $octets);
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        @unlink($tmp);
        throw new InvalidArgumentException('Classeur Excel illisible.');
    }
    $partages = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $s = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        foreach ($s ? $s->si : [] as $si) {  // texte simple (t) ou texte mis en forme (r/t)
            $partages[] = isset($si->t) ? (string)$si->t : implode('', array_map(fn($r) => (string)$r->t, iterator_to_array($si->r, false)));
        }
    }
    $feuille = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    @unlink($tmp);
    $f = $feuille !== false ? simplexml_load_string($feuille, 'SimpleXMLElement', LIBXML_NONET) : false;
    if (!$f) {
        throw new InvalidArgumentException('Classeur Excel sans feuille lisible.');
    }
    $lignes = [];
    foreach ($f->sheetData->row as $row) {
        $ligne = [];
        foreach ($row->c as $c) {
            preg_match('/^([A-Z]+)/', (string)$c['r'], $m);
            $col = 0;
            foreach (str_split($m[1] ?? 'A') as $lettre) {
                $col = $col * 26 + ord($lettre) - 64;
            }
            $type = (string)$c['t'];
            $v = $type === 's' ? ($partages[(int)$c->v] ?? '') : ($type === 'inlineStr' ? (string)$c->is->t : (string)$c->v);
            $ligne[$col - 1] = trim($v);
        }
        if (implode('', $ligne) !== '') {
            $lignes[] = array_replace(array_fill(0, max(array_keys($ligne)) + 1, ''), $ligne);
        }
    }
    return $lignes;
}

/** Nom de colonne -> clé de BILAN_COLONNES (« Date de clôture » -> « cloture »), ou '' si inconnu. */
function bilan_colonne(string $titre): string
{
    $k = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(sans_accents($titre))) ?? '', '_');
    $k = preg_replace('/^date_de_/', 'date_', $k) ?? $k;
    $k = BILAN_ALIAS[$k] ?? $k;
    return in_array($k, BILAN_COLONNES, true) ? $k : '';
}

/** « 31/12/2025 », « 2025-12-31 » ou numéro de série Excel (46022) -> « 2025-12-31 » ; sinon null. */
function date_import(string $v): ?string
{
    $v = trim($v);
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $v, $m)) {
        return checkdate((int)$m[2], (int)$m[1], (int)$m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
    }
    if (preg_match('/^\d{5}(\.\d+)?$/', $v)) {
        return gmdate('Y-m-d', ((int)$v - 25569) * 86400);
    }
    return lire_date($v);
}

/** Importe le fichier dans les fiches du cabinet (data/cabinet/liste.json) : un dossier par ligne, rapproché d'un dossier déjà
 *  connu par son identifiant Pennylane, son SIREN ou son nom. Une cellule vide ne remplace rien. Retourne
 *  [ajoutés, mis à jour, erreurs] ; une ligne en erreur est ignorée (et dite), les autres sont importées. */
function bilan_importer(string $contenu): array
{
    $lignes = str_starts_with($contenu, "PK\x03\x04") ? lire_xlsx($contenu) : lire_csv(en_utf8($contenu));
    if (count($lignes) < 2) {
        throw new InvalidArgumentException('Fichier vide : une ligne de titres, puis un dossier par ligne (voir exemples/dossiers.csv).');
    }
    $titres = array_map('bilan_colonne', array_shift($lignes));
    if (!in_array('societe', $titres, true)) {
        throw new InvalidArgumentException('Colonne « societe » introuvable dans la ligne de titres (voir exemples/dossiers.csv).');
    }
    $simple = fn(string $v) => trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(sans_accents($v))) ?? '', '_');
    $regimes = ['is' => 'bic_is', 'ir' => 'bic_ir', 'bic' => 'bic_ir', 'bic_ir' => 'bic_ir', 'bic_is' => 'bic_is', 'bnc' => 'bnc', 'ir_bnc' => 'bnc',
        'ir_bic' => 'bic_ir', 'ir_foncier' => 'ir'];
    $tva = ['mensuelle' => 'monthly', 'trimestrielle' => 'quarterly', 'annuelle' => 'yearly', 'non_assujettie' => 'not_applicable',
        'non' => 'not_applicable', 'aucune' => 'not_applicable', 'monthly' => 'monthly', 'quarterly' => 'quarterly', 'yearly' => 'yearly'];
    return sous_verrou(function () use ($lignes, $titres, $regimes, $tva, $simple) {
        $liste = array_values(array_filter((array)lire_json(chemin_data('cabinet', 'liste.json'), []), 'is_array'));
        $ajoutes = $maj = 0;
        $erreurs = [];
        foreach ($lignes as $n => $cellules) {
            $l = [];
            foreach ($titres as $i => $k) {
                if ($k !== '' && trim((string)($cellules[$i] ?? '')) !== '') {
                    $l[$k] = trim((string)$cellules[$i]);
                }
            }
            $no = $n + 2;  // numéro de ligne dans le fichier (titres = ligne 1)
            if (($l['societe'] ?? '') === '') {
                if ($l) {
                    $erreurs[] = "Ligne $no : nom de la société manquant.";
                }
                continue;
            }
            $faux = [];
            foreach (['resultat_net', 'impot_societes', 'loyers_6132', 'indemnite_occupation', 'solde_banque'] as $k) {
                if (isset($l[$k]) && lire_montant($l[$k]) === null) {
                    $faux[] = "$k « {$l[$k]} »";
                }
            }
            foreach (['cloture', 'debut_exercice'] as $k) {
                if (isset($l[$k]) && !date_import($l[$k])) {
                    $faux[] = "$k « {$l[$k]} » (date JJ/MM/AAAA attendue)";
                }
            }
            if (isset($l['email']) && !filter_var($l['email'], FILTER_VALIDATE_EMAIL)) {
                $faux[] = "email « {$l['email']} »";
            }
            if ($faux) {
                $erreurs[] = "Ligne $no ({$l['societe']}) : " . implode(', ', $faux) . '.';
                continue;
            }
            $siren = preg_replace('/\D/', '', $l['siren'] ?? '');
            $pl = preg_replace('/\D/', '', $l['id_pennylane'] ?? '');
            $place = null;
            foreach ($liste as $i => $f) {
                if (($pl !== '' && (string)($f['pennylane_id'] ?? '') === $pl) || ($siren !== '' && preg_replace('/\D/', '', (string)($f['siren'] ?? '')) === $siren)
                    || mb_strtolower(trim((string)($f['nom'] ?? ''))) === mb_strtolower($l['societe'])) {
                    $place = $i;
                    break;
                }
            }
            $fiche = $place !== null ? $liste[$place] : ['pennylane_id' => $pl !== '' ? $pl : 'f' . substr(md5(mb_strtolower($l['societe'])), 0, 10)];
            $fiche['nom'] = $l['societe'];
            $fiche['source'] = 'import';
            $fiche['importe_le'] = date('d/m/Y');
            if ($siren !== '') {
                $fiche['siren'] = $siren;
            }
            if (isset($l['forme_juridique'])) {
                $fiche['forme_juridique'] = $l['forme_juridique'];
            }
            if (isset($l['regime'])) {
                $fiche['categorie_fiscale'] = $regimes[$simple($l['regime'])] ?? $simple($l['regime']);
            }
            if (isset($l['tva'])) {
                $fiche['frequence_tva'] = $tva[$simple($l['tva'])] ?? $simple($l['tva']);
            }
            $contact = (array)($fiche['contact'] ?? []);
            foreach (['prenom' => 'prenom', 'nom' => 'nom', 'email' => 'email'] as $k => $cle) {
                if (isset($l[$k])) {
                    $contact[$cle] = $l[$k];
                }
            }
            if (isset($l['civilite'])) {
                $contact['civilite'] = ['m' => 'M', 'mr' => 'M', 'monsieur' => 'M', 'h' => 'M', 'mme' => 'Mme', 'madame' => 'Mme', 'f' => 'Mme'][$simple($l['civilite'])] ?? '';
            }
            $fiche['contact'] = $contact;
            if (isset($l['cc'])) {
                $fiche['cc'] = implode(', ', adresses($l['cc']));
            }
            if (isset($l['tutoiement'])) {
                $fiche['formule'] = in_array($simple($l['tutoiement']), ['tu', 'oui', 'o', '1'], true) ? 'Tu' : 'Vous';
            }
            if (isset($l['cloture'])) {
                $fin = date_import($l['cloture']);
                $debut = isset($l['debut_exercice']) ? date_import($l['debut_exercice']) : date('Y-m-d', strtotime("$fin -1 year +1 day"));
                $fiche['exercices'] = [['debut' => $debut, 'fin' => $fin, 'statut' => '']];
                $chiffres = ['debut' => $debut, 'fin' => $fin];
                foreach (['resultat_net', 'impot_societes', 'loyers_6132', 'indemnite_occupation'] as $k) {
                    $chiffres[$k] = isset($l[$k]) ? en_decimal(lire_montant($l[$k])) : '';
                }
                $fiche['resultat'] = $chiffres;
            }
            if (isset($l['banque'])) {
                $fiche['comptes'] = [['banque' => $l['banque'], 'libelle' => '', 'iban' => '',
                    'solde' => isset($l['solde_banque']) ? en_decimal(lire_montant($l['solde_banque'])) : '']];
            }
            if ($place !== null) {
                $liste[$place] = $fiche;
                $maj++;
            } else {
                $liste[] = $fiche;
                $ajoutes++;
            }
        }
        ecrire_json(chemin_data('cabinet', 'liste.json'), $liste);
        return [$ajoutes, $maj, $erreurs];
    });
}

/** Nouveau dossier saisi à la main (sans Pennylane ni fichier) : tout se complète ensuite dans le formulaire. */
function bilan_nouveau_dossier(string $nom): string
{
    $nom = trim($nom);
    if ($nom === '') {
        throw new InvalidArgumentException('Nom de la société manquant.');
    }
    return sous_verrou(function () use ($nom) {
        $liste = array_values(array_filter((array)lire_json(chemin_data('cabinet', 'liste.json'), []), 'is_array'));
        foreach ($liste as $f) {
            if (mb_strtolower(trim((string)($f['nom'] ?? ''))) === mb_strtolower($nom)) {
                return (string)($f['pennylane_id'] ?? '');  // déjà là : le même dossier
            }
        }
        $id = 'm' . bin2hex(random_bytes(5));
        $liste[] = ['pennylane_id' => $id, 'nom' => $nom, 'source' => 'manuel'];
        ecrire_json(chemin_data('cabinet', 'liste.json'), $liste);
        return $id;
    });
}

/** Retire un dossier saisi à la main ou importé (fiche, modifications et suivi). Un dossier Pennylane revient à la prochaine lecture. */
function bilan_retirer_dossier(string $id): void
{
    sous_verrou(function () use ($id) {
        $liste = array_values(array_filter((array)lire_json(chemin_data('cabinet', 'liste.json'), []),
            fn($f) => is_array($f) && (string)($f['pennylane_id'] ?? $f['id'] ?? '') !== $id));
        ecrire_json(chemin_data('cabinet', 'liste.json'), $liste);
        foreach (['saisies.json', 'envois.json'] as $n) {
            $t = lire_json(chemin_data('bilan', $n), []);
            unset($t[$id]);
            ecrire_json(chemin_data('bilan', $n), $t);
        }
        @unlink(chemin_data('cabinet', 'dossiers', basename($id) . '.json'));
    });
}

// ---------- Fichier .eml (sans Outlook) : le mail s'ouvre comme un brouillon dans la messagerie de la personne ----------

/** Mail au format .eml (RFC 5322, MIME) : texte + HTML, images de la signature jointes « en ligne » (cid:). « X-Unsent: 1 » :
 *  Outlook l'ouvre comme un nouveau mail prêt à partir (pièces à joindre, puis Envoyer). */
function bilan_eml(string $objet, string $texte, string $html, array $a, array $cc = []): string
{
    $mot = fn(string $s) => preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    $images = [];
    $html = preg_replace_callback('#src="data:(image/(?:png|jpe?g|gif));base64,([A-Za-z0-9+/=\s]+)"#i', function ($m) use (&$images) {
        $cid = 'image' . count($images) . '.' . bin2hex(random_bytes(4)) . '@mail';
        $images[] = [$cid, strtolower($m[1]), preg_replace('/\s+/', '', $m[2])];
        return "src=\"cid:$cid\"";
    }, $html) ?? $html;
    $b1 = 'alt-' . bin2hex(random_bytes(8));
    $b2 = 'rel-' . bin2hex(random_bytes(8));
    $partie = fn(string $type, string $contenu) => "Content-Type: $type\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($contenu), 76, "\r\n");
    $entetes = ['X-Unsent: 1', 'To: ' . implode(', ', adresses(implode(',', $a)))];
    if (adresses(implode(',', $cc))) {
        $entetes[] = 'Cc: ' . implode(', ', adresses(implode(',', $cc)));
    }
    array_push($entetes, 'Subject: ' . $mot($objet), 'Date: ' . date(DATE_RFC2822), 'MIME-Version: 1.0',
        "Content-Type: multipart/alternative; boundary=\"$b1\"");
    $corps = "--$b1\r\n" . $partie('text/plain; charset=UTF-8', $texte)
        . "--$b1\r\nContent-Type: multipart/related; boundary=\"$b2\"\r\n\r\n"
        . "--$b2\r\n" . $partie('text/html; charset=UTF-8', "<!doctype html><html><head><meta charset=\"utf-8\"></head><body>$html</body></html>");
    foreach ($images as [$cid, $type, $donnees]) {
        $corps .= "--$b2\r\nContent-Type: $type\r\nContent-Transfer-Encoding: base64\r\nContent-ID: <$cid>\r\nContent-Disposition: inline\r\n\r\n"
            . chunk_split($donnees, 76, "\r\n");
    }
    return implode("\r\n", $entetes) . "\r\n\r\n" . $corps . "--$b2--\r\n--$b1--\r\n";
}
