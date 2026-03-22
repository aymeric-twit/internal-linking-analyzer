<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/user_context.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
ini_set('display_errors', '0');
error_reporting(0);

// Détecter si PHP a rejeté le POST à cause de la taille
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && empty($_POST) && empty($_FILES)
    && isset($_SERVER['CONTENT_LENGTH'])
    && (int) $_SERVER['CONTENT_LENGTH'] > 0
) {
    $tailleDemande = (int) $_SERVER['CONTENT_LENGTH'];
    repondreErreur(sprintf(
        'Le fichier (%s) dépasse la limite du serveur (%s). '
        . 'Pour les gros fichiers : uploadez le CSV sur le serveur via SFTP (FileZilla, etc.) '
        . 'puis utilisez le champ "Chemin serveur" en indiquant le chemin complet.',
        formaterOctets($tailleDemande),
        ini_get('post_max_size') ?: '8M'
    ), 413);
}

// Nettoyage
nettoyerAnciensImports();

$userId = obtenirUserId();

// Générer un importId unique
$importId = bin2hex(random_bytes(12));
$dossierImport = cheminImport($userId, $importId);

if (!mkdir($dossierImport, 0755, true)) {
    repondreErreur('Impossible de créer le répertoire de l\'import.', 500);
}

$cheminProgression = $dossierImport . '/progress.json';

// Récupérer le mapping de colonnes
$colSource = filter_input(INPUT_POST, 'col_source', FILTER_VALIDATE_INT);
$colDestination = filter_input(INPUT_POST, 'col_destination', FILTER_VALIDATE_INT);
$colAncre = filter_input(INPUT_POST, 'col_ancre', FILTER_VALIDATE_INT);

if ($colSource === null || $colSource === false
    || $colDestination === null || $colDestination === false
    || $colAncre === null || $colAncre === false) {
    repondreErreur('Mapping de colonnes invalide.');
}

// Filtre optionnel (ex: Link Position = "Content")
$colFiltreRaw = $_POST['col_filtre'] ?? '';
$colFiltre = $colFiltreRaw !== '' ? (int) $colFiltreRaw : -1;
$valeurFiltre = trim($_POST['valeur_filtre'] ?? '');

// Déterminer la source du fichier CSV
$cheminCsv = '';
$cheminServeur = trim($_POST['chemin_serveur'] ?? '');
$nomFichier = 'liens.csv';

if ($cheminServeur !== '') {
    $cheminReel = realpath($cheminServeur);
    if ($cheminReel === false || !is_file($cheminReel) || !is_readable($cheminReel)) {
        repondreErreur('Fichier introuvable ou non lisible sur le serveur.');
    }
    $extension = strtolower(pathinfo($cheminReel, PATHINFO_EXTENSION));
    if (!in_array($extension, ['csv', 'txt'], true)) {
        repondreErreur('Le fichier doit être un CSV (.csv ou .txt).');
    }
    $cheminCsv = $cheminReel;
    $nomFichier = basename($cheminReel);
} else {
    $fichier = $_FILES['fichier_liens'] ?? null;
    if (!$fichier || $fichier['error'] !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE => 'Le fichier dépasse la taille maximale autorisée par le serveur.',
            UPLOAD_ERR_FORM_SIZE => 'Le fichier dépasse la taille maximale autorisée.',
            UPLOAD_ERR_PARTIAL => 'Le fichier n\'a été que partiellement uploadé.',
            UPLOAD_ERR_NO_FILE => 'Aucun fichier sélectionné.',
        ];
        $codeErreur = $fichier['error'] ?? UPLOAD_ERR_NO_FILE;
        repondreErreur($messages[$codeErreur] ?? 'Erreur lors de l\'upload.');
    }

    $extension = strtolower(pathinfo($fichier['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ['csv', 'txt'], true)) {
        repondreErreur('Le fichier doit être un CSV (.csv ou .txt).');
    }

    $cheminCsv = $dossierImport . '/liens_upload.csv';
    $nomFichier = $fichier['name'];
    if (!move_uploaded_file($fichier['tmp_name'], $cheminCsv)) {
        repondreErreur('Impossible de déplacer le fichier uploadé.', 500);
    }
}

// Enregistrer l'import dans le registre utilisateur
$tailleFichier = filesize($cheminCsv) ?: 0;
ajouterImport($userId, [
    'id' => $importId,
    'nom_fichier' => $nomFichier,
    'date_creation' => date('c'),
    'taille_fichier' => $tailleFichier,
    'nb_lignes' => 0,
    'nb_ancres_distinctes' => 0,
    'nb_urls_distinctes' => 0,
    'statut' => 'import_en_cours',
]);

// Écrire la progression initiale
ecrireProgression($cheminProgression, [
    'phase' => 'import',
    'statut' => 'demarrage',
    'lignes_importees' => 0,
    'pourcentage' => 0,
    'debut' => time(),
]);

// Lancer le worker en arrière-plan (avec userId)
// PHP_BINARY retourne php-fpm en contexte FPM — utiliser le CLI
$phpBin = PHP_BINARY;
if (str_contains($phpBin, 'fpm')) {
    $phpBin = preg_replace('/fpm\d*/', 'cli', $phpBin) ?: '/usr/bin/php';
    if (!file_exists($phpBin)) {
        $phpBin = trim(shell_exec('which php8.3 2>/dev/null') ?: shell_exec('which php 2>/dev/null') ?: '/usr/bin/php');
    }
}
$workerScript = __DIR__ . '/worker.php';
$commande = sprintf(
    '%s %s %s %d %d %d %d %s %s %d > %s 2>&1 &',
    escapeshellarg($phpBin),
    escapeshellarg($workerScript),
    escapeshellarg($importId),
    $colSource,
    $colDestination,
    $colAncre,
    $colFiltre,
    escapeshellarg($valeurFiltre),
    escapeshellarg($cheminCsv),
    $userId,
    escapeshellarg($dossierImport . '/worker.log')
);

exec($commande);

repondreJson([
    'jobId' => $importId,
    'statut' => 'import_en_cours',
]);
