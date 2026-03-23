<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/user_context.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
ini_set('display_errors', '0');
error_reporting(0);

$userId = obtenirUserId();

// ── GET : lister les imports ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $imports = lireRegistreImports($userId);

    // Trier par date décroissante
    usort($imports, function (array $a, array $b): int {
        return strcmp($b['date_creation'] ?? '', $a['date_creation'] ?? '');
    });

    repondreJson(['imports' => $imports]);
}

// ── POST : actions sur les imports ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'supprimer') {
        $importId = $_POST['id'] ?? '';

        if (!validerJobId($importId)) {
            repondreErreur([
                'fr' => 'Import ID invalide.',
                'en' => 'Invalid import ID.',
            ]);
        }

        $resultat = supprimerImportComplet($userId, $importId);

        if (!$resultat) {
            repondreErreur([
                'fr' => 'Import introuvable.',
                'en' => 'Import not found.',
            ], 404);
        }

        repondreJson([
            'succes' => true,
            'message' => 'Import supprimé.',
            'message_fr' => 'Import supprimé.',
            'message_en' => 'Import deleted.',
        ]);
    }

    repondreErreur([
        'fr' => 'Action inconnue.',
        'en' => 'Unknown action.',
    ]);
}

repondreErreur([
    'fr' => 'Méthode non supportée.',
    'en' => 'Method not supported.',
], 405);
