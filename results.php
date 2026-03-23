<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/user_context.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

$importId = $_GET['jobId'] ?? '';
if (!validerJobId($importId)) {
    repondreErreur([
        'fr' => 'Import ID invalide.',
        'en' => 'Invalid import ID.',
    ], 400);
}

$userId = verifierProprietaire($importId);
$cheminResultats = cheminImport($userId, $importId) . '/resultats.json';

if (!file_exists($cheminResultats)) {
    repondreErreur([
        'fr' => 'Résultats introuvables. Lancez d\'abord l\'analyse.',
        'en' => 'Results not found. Please run the analysis first.',
    ], 404);
}

$contenu = file_get_contents($cheminResultats);
if ($contenu === false) {
    repondreErreur([
        'fr' => 'Impossible de lire les résultats.',
        'en' => 'Unable to read results.',
    ], 500);
}

echo $contenu;
