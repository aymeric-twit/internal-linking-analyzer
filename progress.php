<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/user_context.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store');
header('X-Content-Type-Options: nosniff');

$importId = $_GET['jobId'] ?? '';

if (!validerJobId($importId)) {
    repondreErreur('Import ID invalide.', 400);
}

$userId = obtenirUserId();
$cheminProgression = cheminImport($userId, $importId) . '/progress.json';

if (!file_exists($cheminProgression)) {
    repondreErreur('Import introuvable.', 404);
}

$contenu = file_get_contents($cheminProgression);
if ($contenu === false) {
    repondreErreur('Impossible de lire la progression.', 500);
}

echo $contenu;
