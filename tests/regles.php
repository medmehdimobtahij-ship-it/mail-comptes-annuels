<?php
/**
 * Appelle une règle du mail (src/bilan.php, src/pennylane.php, src/textes.php) pour les tests : lit {"f": nom, "args": [...]}
 * sur l'entrée standard, écrit le résultat en JSON (ou {"manques": [...]} si la rédaction est bloquée). Sans serveur, sans réseau.
 */
declare(strict_types=1);

$src = dirname(__DIR__) . '/src';
foreach (['noyau', 'textes', 'comptes', 'outlook', 'securite', 'pennylane', 'bilan'] as $m) {
    require "$src/$m.php";
}
$permises = ['lire_montant', 'euros', 'montant_saisie', 'date_longue', 'typographie', 'resultat_et_impot', 'loyers_et_indemnite',
    'exercice_termine', 'missions_sans_greffe', 'libelle_exercice', 'montant_entier', 'depot_au_greffe_brut', 'formulaire', 'rediger',
    'indications', 'eml', 'importer_lignes', 'date_import', 'bilan_colonne'];
$d = json_decode((string)stream_get_contents(STDIN), true);
$f = (string)($d['f'] ?? '');
$a = (array)($d['args'] ?? []);
if (!in_array($f, $permises, true)) {
    fwrite(STDERR, "fonction non permise : $f\n");
    exit(2);
}
$p = $a[1] ?? null;
$dossier = fn(array $b) => ['extrait' => (string)($b['extrait'] ?? '')] + bilan_normaliser($b);  // « extrait » : chiffres déjà lus
$r = match ($f) {
    'depot_au_greffe_brut' => depot_au_greffe(bilan_normaliser($a[0])),
    'formulaire' => formulaire_initial($dossier($a[0]), array_merge(BILAN_DEFAUTS, (array)$p), $a[2] ?? null),
    'indications' => bilan_indications($dossier($a[0]), (array)$p),
    'eml' => bilan_eml(...$a),
    'rediger' => (function () use ($a) {
        try {
            return array_combine(['objet', 'texte', 'html', 'joindre'], bilan_rediger($a[0]));
        } catch (ErreurFormulaire $e) {
            return ['manques' => $e->manques];
        }
    })(),
    'importer_lignes' => lire_csv(en_utf8(base64_decode($a[0]))),
    default => $f(...$a),
};
echo json_encode($r, JSON_UNESCAPED_UNICODE);
