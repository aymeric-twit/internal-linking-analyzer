<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/user_context.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

$importId = $_GET['jobId'] ?? '';
if (!validerJobId($importId)) {
    repondreErreur('Import ID invalide.', 400);
}

$userId = verifierProprietaire($importId);
$cheminResultats = cheminImport($userId, $importId) . '/resultats.json';

if (!file_exists($cheminResultats)) {
    repondreErreur('Résultats introuvables. Lancez d\'abord l\'analyse.', 404);
}

$contenu = file_get_contents($cheminResultats);
if ($contenu === false) {
    repondreErreur('Impossible de lire les résultats.', 500);
}

echo $contenu;
