<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/user_context.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
ini_set('display_errors', '0');
error_reporting(0);

$importId = $_POST['jobId'] ?? '';
if (!validerJobId($importId)) {
    repondreErreur('Import ID invalide.', 400);
}

$userId = verifierProprietaire($importId);
$dossierImport = cheminImport($userId, $importId);
$cheminSqlite = $dossierImport . '/liens.sqlite';

if (!file_exists($cheminSqlite)) {
    repondreErreur('Base de données introuvable. Veuillez d\'abord importer le fichier de liens.', 404);
}

// Récupérer le fichier ancres
$fichier = $_FILES['fichier_ancres'] ?? null;
if (!$fichier || $fichier['error'] !== UPLOAD_ERR_OK) {
    repondreErreur('Le fichier ancres est requis.');
}

$separateur = $_POST['separateur'] ?? ';';
if (!in_array($separateur, [';', ',', "\t"], true)) {
    $separateur = ';';
}

$avecEntete = ($_POST['avec_entete'] ?? '0') === '1';

// Lire le fichier ancres
$handleAncres = fopen($fichier['tmp_name'], 'r');
if ($handleAncres === false) {
    repondreErreur('Impossible de lire le fichier ancres.');
}

// Détecter le BOM UTF-8
$bom = fread($handleAncres, 3);
if ($bom !== "\xEF\xBB\xBF") {
    fseek($handleAncres, 0);
}

// Sauter l'en-tête si demandé
if ($avecEntete) {
    fgetcsv($handleAncres, 0, $separateur, '"', '\\');
}

$couples = [];
$numLigne = $avecEntete ? 1 : 0;

while (($ligne = fgetcsv($handleAncres, 0, $separateur, '"', '\\')) !== false) {
    $numLigne++;
    if (count($ligne) < 2) {
        fclose($handleAncres);
        repondreErreur("Ligne $numLigne : le fichier doit contenir au moins 2 colonnes (ancre et URL cible).");
    }

    $ancre = normaliserAncre($ligne[0]);
    $urlCible = normaliserUrl($ligne[1]);

    if ($ancre === '' || $urlCible === '') {
        continue;
    }

    $couples[] = [
        'ancre' => $ancre,
        'ancre_originale' => trim($ligne[0]),
        'url_cible' => $urlCible,
    ];
}

fclose($handleAncres);

if (empty($couples)) {
    repondreErreur('Le fichier ancres est vide ou ne contient aucun couple valide.');
}

// Ouvrir la base SQLite
try {
    $db = new PDO('sqlite:' . $cheminSqlite);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode = WAL');
} catch (PDOException $e) {
    repondreErreur('Impossible d\'ouvrir la base de données : ' . $e->getMessage(), 500);
}

// Préparer les requêtes
$stmtCannibales = $db->prepare('
    SELECT destination, COUNT(*) as nb_liens
    FROM liens
    WHERE ancre = :ancre AND destination != :url_cible
    GROUP BY destination
    ORDER BY nb_liens DESC
');

$stmtLegitimes = $db->prepare('
    SELECT COUNT(*) as nb
    FROM liens
    WHERE ancre = :ancre AND destination = :url_cible
');

$stmtSources = $db->prepare('
    SELECT source, destination, COUNT(*) as nb
    FROM liens
    WHERE ancre = :ancre AND destination != :url_cible
    GROUP BY source, destination
    ORDER BY nb DESC
');

// Analyser chaque couple
$resultats = [];
$nbCannibalisations = 0;
$totalRatio = 0;

foreach ($couples as $couple) {
    $ancre = $couple['ancre'];
    $urlCible = $couple['url_cible'];

    $stmtCannibales->execute([':ancre' => $ancre, ':url_cible' => $urlCible]);
    $destinationsParasites = $stmtCannibales->fetchAll(PDO::FETCH_ASSOC);

    $nbLiensCannibales = 0;
    foreach ($destinationsParasites as $dp) {
        $nbLiensCannibales += (int) $dp['nb_liens'];
    }

    $stmtLegitimes->execute([':ancre' => $ancre, ':url_cible' => $urlCible]);
    $nbLiensLegitimes = (int) $stmtLegitimes->fetchColumn();

    $total = $nbLiensCannibales + $nbLiensLegitimes;
    $ratio = $total > 0 ? round(($nbLiensCannibales / $total) * 100, 1) : 0;

    $severite = determinerSeverite($ratio);

    $stmtSources->execute([':ancre' => $ancre, ':url_cible' => $urlCible]);
    $pagesSources = $stmtSources->fetchAll(PDO::FETCH_ASSOC);

    $resultats[] = [
        'ancre' => $couple['ancre_originale'],
        'ancre_normalisee' => $ancre,
        'url_cible' => $urlCible,
        'liens_legitimes' => $nbLiensLegitimes,
        'liens_cannibales' => $nbLiensCannibales,
        'destinations_parasites' => count($destinationsParasites),
        'ratio' => $ratio,
        'severite' => $severite,
        'detail_destinations' => $destinationsParasites,
        'detail_sources' => $pagesSources,
    ];

    if ($nbLiensCannibales > 0) {
        $nbCannibalisations++;
    }
    $totalRatio += $ratio;
}

// Score global
$scoreGlobal = count($resultats) > 0 ? round($totalRatio / count($resultats), 1) : 0;

// Sauvegarder les résultats
$donneesResultats = [
    'job_id' => $importId,
    'nb_couples' => count($resultats),
    'nb_cannibalisations' => $nbCannibalisations,
    'score_global' => $scoreGlobal,
    'severite_globale' => determinerSeverite($scoreGlobal),
    'resultats' => $resultats,
];

$cheminResultats = $dossierImport . '/resultats.json';
file_put_contents($cheminResultats, json_encode($donneesResultats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

// Décompter les crédits (par nombre de pages distinctes analysées)
if (class_exists(\Platform\Module\Quota::class)) {
    $nbPagesDistinctes = (int) $db->query('SELECT COUNT(*) FROM (SELECT source AS url FROM liens UNION SELECT destination AS url FROM liens)')->fetchColumn();
    \Platform\Module\Quota::track('internal-linking-analyzer', max(1, $nbPagesDistinctes));
}

repondreJson([
    'statut' => 'analyse_terminee',
    'nbCouples' => count($resultats),
    'nbCannibalisations' => $nbCannibalisations,
    'scoreGlobal' => $scoreGlobal,
    'jobId' => $importId,
]);
