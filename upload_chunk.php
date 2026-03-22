<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/user_context.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
ini_set('display_errors', '0');
error_reporting(0);

$action = $_POST['action'] ?? '';

// ── Initialisation : créer l'import et préparer le répertoire ────────
if ($action === 'init') {
    nettoyerAnciensImports();

    $userId = obtenirUserId();
    $nomFichier = trim($_POST['nom_fichier'] ?? '');
    $tailleTotale = (int) ($_POST['taille_totale'] ?? 0);
    $nbChunks = (int) ($_POST['nb_chunks'] ?? 0);

    if ($nomFichier === '' || $tailleTotale <= 0 || $nbChunks <= 0) {
        repondreErreur('Paramètres d\'initialisation invalides.');
    }

    // Vérifier l'extension
    $extension = strtolower(pathinfo($nomFichier, PATHINFO_EXTENSION));
    if (!in_array($extension, ['csv', 'txt'], true)) {
        repondreErreur('Le fichier doit être un CSV (.csv ou .txt).');
    }

    $importId = bin2hex(random_bytes(12));
    $dossierImport = cheminImport($userId, $importId);

    if (!mkdir($dossierImport, 0755, true)) {
        repondreErreur('Impossible de créer le répertoire de l\'import.', 500);
    }

    // Créer le sous-dossier pour les chunks
    mkdir($dossierImport . '/chunks', 0755, true);

    // Sauvegarder les métadonnées de l'upload
    ecrireProgression($dossierImport . '/upload_meta.json', [
        'nom_fichier' => $nomFichier,
        'taille_totale' => $tailleTotale,
        'nb_chunks' => $nbChunks,
        'chunks_recus' => 0,
        'user_id' => $userId,
        'statut' => 'upload_en_cours',
    ]);

    // Enregistrer l'import dans le registre utilisateur
    ajouterImport($userId, [
        'id' => $importId,
        'nom_fichier' => $nomFichier,
        'date_creation' => date('c'),
        'taille_fichier' => $tailleTotale,
        'nb_lignes' => 0,
        'nb_ancres_distinctes' => 0,
        'nb_urls_distinctes' => 0,
        'statut' => 'upload_en_cours',
    ]);

    repondreJson([
        'jobId' => $importId,
        'statut' => 'pret',
    ]);
}

// ── Réception d'un chunk ─────────────────────────────────────────────
if ($action === 'chunk') {
    $importId = $_POST['jobId'] ?? '';
    $indexChunk = filter_input(INPUT_POST, 'index_chunk', FILTER_VALIDATE_INT);

    if (!validerJobId($importId)) {
        repondreErreur('Import ID invalide.');
    }

    if ($indexChunk === null || $indexChunk === false || $indexChunk < 0) {
        repondreErreur('Index de chunk invalide.');
    }

    $userId = obtenirUserId();
    $dossierImport = cheminImport($userId, $importId);
    $cheminMeta = $dossierImport . '/upload_meta.json';

    if (!file_exists($cheminMeta)) {
        repondreErreur('Import introuvable.', 404);
    }

    $fichier = $_FILES['chunk'] ?? null;
    if (!$fichier || $fichier['error'] !== UPLOAD_ERR_OK) {
        repondreErreur('Chunk manquant ou corrompu.');
    }

    // Sauvegarder le chunk
    $cheminChunk = $dossierImport . '/chunks/' . str_pad((string) $indexChunk, 6, '0', STR_PAD_LEFT);
    if (!move_uploaded_file($fichier['tmp_name'], $cheminChunk)) {
        repondreErreur('Impossible de sauvegarder le chunk.', 500);
    }

    // Mettre à jour le compteur de chunks reçus
    $meta = json_decode(file_get_contents($cheminMeta), true);
    $meta['chunks_recus'] = ($meta['chunks_recus'] ?? 0) + 1;

    $nbChunks = $meta['nb_chunks'];
    $tousRecus = $meta['chunks_recus'] >= $nbChunks;

    if ($tousRecus) {
        $meta['statut'] = 'assemblage';
    }

    ecrireProgression($cheminMeta, $meta);

    repondreJson([
        'statut' => $tousRecus ? 'assemblage' : 'ok',
        'chunks_recus' => $meta['chunks_recus'],
        'nb_chunks' => $nbChunks,
    ]);
}

// ── Assemblage des chunks + lancement du worker ──────────────────────
if ($action === 'assemble') {
    $importId = $_POST['jobId'] ?? '';
    $colSource = filter_input(INPUT_POST, 'col_source', FILTER_VALIDATE_INT);
    $colDestination = filter_input(INPUT_POST, 'col_destination', FILTER_VALIDATE_INT);
    $colAncre = filter_input(INPUT_POST, 'col_ancre', FILTER_VALIDATE_INT);

    if (!validerJobId($importId)) {
        repondreErreur('Import ID invalide.');
    }

    if ($colSource === null || $colSource === false
        || $colDestination === null || $colDestination === false
        || $colAncre === null || $colAncre === false) {
        repondreErreur('Mapping de colonnes invalide.');
    }

    $colFiltreRaw = $_POST['col_filtre'] ?? '';
    $colFiltre = $colFiltreRaw !== '' ? (int) $colFiltreRaw : -1;
    $valeurFiltre = trim($_POST['valeur_filtre'] ?? '');

    $userId = obtenirUserId();
    $dossierImport = cheminImport($userId, $importId);
    $cheminMeta = $dossierImport . '/upload_meta.json';

    if (!file_exists($cheminMeta)) {
        repondreErreur('Import introuvable.', 404);
    }

    $meta = json_decode(file_get_contents($cheminMeta), true);

    if ($meta['chunks_recus'] < $meta['nb_chunks']) {
        repondreErreur(sprintf(
            'Upload incomplet : %d/%d chunks reçus.',
            $meta['chunks_recus'],
            $meta['nb_chunks']
        ));
    }

    // Assembler les chunks dans le fichier final
    $cheminCsv = $dossierImport . '/liens_upload.csv';
    $outputHandle = fopen($cheminCsv, 'wb');

    if ($outputHandle === false) {
        repondreErreur('Impossible de créer le fichier assemblé.', 500);
    }

    $dossierChunks = $dossierImport . '/chunks';
    $fichierChunks = glob($dossierChunks . '/*');
    sort($fichierChunks);

    foreach ($fichierChunks as $chunkPath) {
        $chunkHandle = fopen($chunkPath, 'rb');
        if ($chunkHandle === false) {
            fclose($outputHandle);
            repondreErreur('Erreur lors de la lecture du chunk : ' . basename($chunkPath), 500);
        }
        while (!feof($chunkHandle)) {
            fwrite($outputHandle, fread($chunkHandle, 8192));
        }
        fclose($chunkHandle);
    }

    fclose($outputHandle);

    // Supprimer les chunks pour libérer l'espace disque
    foreach ($fichierChunks as $chunkPath) {
        unlink($chunkPath);
    }
    @rmdir($dossierChunks);

    // Écrire la progression initiale du worker
    $cheminProgression = $dossierImport . '/progress.json';
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

    // Mettre à jour les métadonnées et le registre
    $meta['statut'] = 'import_lance';
    ecrireProgression($cheminMeta, $meta);

    mettreAJourImport($userId, $importId, ['statut' => 'import_en_cours']);

    repondreJson([
        'jobId' => $importId,
        'statut' => 'import_en_cours',
    ]);
}

// Action inconnue
if ($action === '') {
    repondreErreur('Paramètre "action" requis (init, chunk, assemble).');
}

repondreErreur('Action inconnue : ' . $action);
