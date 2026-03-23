<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/user_context.php';

$importId = $_GET['jobId'] ?? '';
$type = $_GET['type'] ?? '';

if (!validerJobId($importId)) {
    repondreErreur([
        'fr' => 'Import ID invalide.',
        'en' => 'Invalid import ID.',
    ], 400);
}

if (!in_array($type, ['resume', 'detail', 'actions', 'pagerank', 'orphelins', 'diversite', 'hubs', 'ancres', 'cannibale_auto'], true)) {
    repondreErreur([
        'fr' => 'Type d\'export invalide.',
        'en' => 'Invalid export type.',
    ], 400);
}

$userId = verifierProprietaire($importId);
$dossierImport = cheminImport($userId, $importId);

// Types d'analyse avancée → déléguer à advanced_analysis.php pour le calcul
if (in_array($type, ['pagerank', 'orphelins', 'diversite', 'hubs', 'ancres', 'cannibale_auto'], true)) {
    $cheminSqlite = $dossierImport . '/liens.sqlite';
    if (!file_exists($cheminSqlite)) {
        repondreErreur([
            'fr' => 'Base de données introuvable.',
            'en' => 'Database not found.',
        ], 404);
    }
    require_once __DIR__ . '/advanced_analysis.php';
    exporterAnalyseAvanceeCsv($cheminSqlite, $type);
    exit;
}

// Types de cannibalisation classiques
$cheminResultats = $dossierImport . '/resultats.json';
if (!file_exists($cheminResultats)) {
    repondreErreur([
        'fr' => 'Résultats introuvables.',
        'en' => 'Results not found.',
    ], 404);
}

$contenu = file_get_contents($cheminResultats);
if ($contenu === false) {
    repondreErreur([
        'fr' => 'Impossible de lire les résultats.',
        'en' => 'Unable to read results.',
    ], 500);
}

$donnees = json_decode($contenu, true);
if (!is_array($donnees) || empty($donnees['resultats'])) {
    repondreErreur([
        'fr' => 'Aucun résultat à exporter.',
        'en' => 'No results to export.',
    ], 404);
}

$resultats = $donnees['resultats'];

$nomFichier = match ($type) {
    'resume' => 'resume_cannibalisation.csv',
    'detail' => 'detail_cannibalisation.csv',
    'actions' => 'actions_cannibalisation.csv',
};

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $nomFichier . '"');
header('X-Content-Type-Options: nosniff');

$output = fopen('php://output', 'w');
fwrite($output, "\xEF\xBB\xBF");

switch ($type) {
    case 'resume':
        fputcsv($output, ['Ancre', 'URL cible', 'Liens légitimes', 'Liens cannibalisés', 'Destinations parasites', 'Ratio (%)', 'Sévérité'], ';');
        foreach ($resultats as $r) {
            fputcsv($output, [$r['ancre'], $r['url_cible'], $r['liens_legitimes'], $r['liens_cannibales'], $r['destinations_parasites'], $r['ratio'], $r['severite']['label']], ';');
        }
        break;

    case 'detail':
        fputcsv($output, ['Ancre', 'URL cible souhaitée', 'Page source', 'Destination erronée', 'Nb occurrences'], ';');
        foreach ($resultats as $r) {
            foreach ($r['detail_sources'] ?? [] as $source) {
                fputcsv($output, [$r['ancre'], $r['url_cible'], $source['source'], $source['destination'], $source['nb']], ';');
            }
        }
        break;

    case 'actions':
        fputcsv($output, ['Page source', 'Ancre actuelle', 'Destination actuelle (erronée)', 'Destination souhaitée', 'Action recommandée'], ';');
        foreach ($resultats as $r) {
            foreach ($r['detail_sources'] ?? [] as $source) {
                $action = sprintf('Modifier le lien "%s" pour qu\'il pointe vers %s au lieu de %s', $r['ancre'], $r['url_cible'], $source['destination']);
                fputcsv($output, [$source['source'], $r['ancre'], $source['destination'], $r['url_cible'], $action], ';');
            }
        }
        break;
}

fclose($output);
