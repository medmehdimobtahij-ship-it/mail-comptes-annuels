<?php
/**
 * Mise en forme des mails : montants, dates, typographie française. Montants en CENTIMES (entiers) : aucun arrondi flottant.
 */
declare(strict_types=1);

const NBSP = "\u{a0}";
const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

/** « - 7 940,13 », « 22735 », « 1.234,5 », « 891 € » -> centimes ; vide ou illisible -> null. */
function lire_montant($texte): ?int
{
    $s = str_replace(['€', NBSP, "\u{202f}", ' '], '', trim((string)($texte ?? '')));
    if ($s === '') {
        return null;
    }
    if (str_contains($s, ',') && str_contains($s, '.')) {
        // 1.234,56 (milliers à la française) ; 1,234.56 (export à l'anglaise : la virgule vient avant le point)
        $s = strrpos($s, ',') < strrpos($s, '.') ? str_replace(',', '', $s) : str_replace('.', '', $s);
    }
    $s = str_replace(',', '.', $s);
    if (!preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $s)) {
        return null;
    }
    $negatif = str_starts_with($s, '-');
    $s = ltrim($s, '+-');
    [$e, $d] = array_pad(explode('.', $s, 2), 2, '');
    $c = (int)($e === '' ? '0' : $e) * 100 + (int)str_pad(substr($d . '00', 0, 2), 2, '0');
    if (strlen($d) > 2 && (int)$d[2] >= 5) {  // arrondi au centime le plus proche
        $c++;
    }
    return $negatif ? -$c : $c;
}

/** Centimes -> « 22 735 € », « - 7 940,13 € » (espaces insécables). $signe = false : valeur absolue. */
function euros(int $c, bool $signe = true): string
{
    $m = $signe ? $c : abs($c);
    $negatif = $m < 0;
    $m = abs($m);
    $entier = (string)intdiv($m, 100);
    $dec = str_pad((string)($m % 100), 2, '0', STR_PAD_LEFT);
    $groupes = [];
    while ($entier !== '') {
        array_unshift($groupes, substr($entier, -3));
        $entier = strlen($entier) > 3 ? substr($entier, 0, -3) : '';
    }
    $txt = implode(NBSP, $groupes) . ($dec === '00' ? '' : ',' . $dec);
    return ($negatif ? '-' . NBSP : '') . $txt . NBSP . '€';
}

/** Centimes -> texte d'un champ de formulaire : « 5 046,78 », « -7 940,13 », « 891 ». */
function montant_saisie(int $c): string
{
    return str_replace(['-' . NBSP, NBSP], ['-', ' '], str_replace(NBSP . '€', '', euros($c)));
}

/** « 2026-06-30 » -> « 30 juin 2026 » (« 1er » pour le premier du mois). */
function date_longue(?string $iso): string
{
    $d = lire_date($iso);
    if (!$d) {
        return '';
    }
    [$a, $m, $j] = array_map('intval', explode('-', $d));
    return ($j === 1 ? '1er' : $j) . ' ' . MOIS[$m - 1] . ' ' . $a;
}

/** Espace insécable avant « : », « ; » et « € » (une espace ordinaire déjà là est remplacée). */
function typographie(string $s): string
{
    return preg_replace('/[ \x{a0}]?([:;€])(?=\s|$|\))/u', NBSP . '$1', $s) ?? $s;
}
