<?php

declare(strict_types=1);

require_once __DIR__ . '/../../functions.php';
require_once __DIR__ . '/../../user_context.php';

// ── Assemblage de chunks ─────────────────────────────────

test('les chunks devraient être réassemblés dans le bon ordre', function (): void {
    $userId = 0;
    $importId = 'test_chunks_' . uniqid();
    $dossierImport = cheminImport($userId, $importId);
    mkdir($dossierImport . '/chunks', 0755, true);

    ajouterImport($userId, ['id' => $importId, 'date_creation' => date('c'), 'statut' => 'upload_en_cours']);

    // Simuler un fichier CSV découpé en 3 chunks
    $contenuComplet = "Source,Type,Destination,Alt,Follow,Anchor\n";
    for ($i = 0; $i < 100; $i++) {
        $contenuComplet .= "https://example.com/page-$i,Hyperlink,https://example.com/dest-$i,,true,ancre $i\n";
    }

    $tailleChunk = (int) ceil(strlen($contenuComplet) / 3);
    $chunks = str_split($contenuComplet, $tailleChunk);

    foreach ($chunks as $index => $chunk) {
        file_put_contents(
            $dossierImport . '/chunks/' . str_pad((string) $index, 6, '0', STR_PAD_LEFT),
            $chunk
        );
    }

    // Assembler
    $cheminCsv = $dossierImport . '/liens_upload.csv';
    $outputHandle = fopen($cheminCsv, 'wb');
    $fichierChunks = glob($dossierImport . '/chunks/*');
    sort($fichierChunks);

    foreach ($fichierChunks as $chunkPath) {
        $chunkHandle = fopen($chunkPath, 'rb');
        while (!feof($chunkHandle)) {
            fwrite($outputHandle, fread($chunkHandle, 8192));
        }
        fclose($chunkHandle);
    }
    fclose($outputHandle);

    expect(file_get_contents($cheminCsv))->toBe($contenuComplet);

    // Nettoyage
    supprimerImportComplet($userId, $importId);
});

test('le workflow complet chunked + worker devrait fonctionner', function (): void {
    $userId = 0;
    $importId = 'test_chunked_full_' . uniqid();
    $dossierImport = cheminImport($userId, $importId);
    mkdir($dossierImport . '/chunks', 0755, true);

    ajouterImport($userId, ['id' => $importId, 'date_creation' => date('c'), 'statut' => 'upload_en_cours']);

    $csv = "Source,Type,Destination,Alt,Follow,Anchor\n";
    $csv .= "https://example.com/a,Hyperlink,https://example.com/b,,true,chaussures running\n";
    $csv .= "https://example.com/c,Hyperlink,https://example.com/d,,true,chaussures running\n";
    $csv .= "https://example.com/e,Hyperlink,https://example.com/b,,true,baskets\n";

    // Simuler 2 chunks
    $moitie = (int) ceil(strlen($csv) / 2);
    file_put_contents($dossierImport . '/chunks/000000', substr($csv, 0, $moitie));
    file_put_contents($dossierImport . '/chunks/000001', substr($csv, $moitie));

    // Assembler
    $cheminCsv = $dossierImport . '/liens_upload.csv';
    $output = fopen($cheminCsv, 'wb');
    $chunks = glob($dossierImport . '/chunks/*');
    sort($chunks);
    foreach ($chunks as $c) {
        fwrite($output, file_get_contents($c));
        unlink($c);
    }
    fclose($output);
    rmdir($dossierImport . '/chunks');

    expect(file_get_contents($cheminCsv))->toBe($csv);

    // Lancer le worker
    $commande = sprintf(
        '%s %s %s 0 2 5 -1 %s %s %d 2>&1',
        escapeshellarg(PHP_BINARY),
        escapeshellarg(__DIR__ . '/../../worker.php'),
        escapeshellarg($importId),
        escapeshellarg(''),
        escapeshellarg($cheminCsv),
        $userId
    );
    exec($commande, $sortie, $codeRetour);
    expect($codeRetour)->toBe(0);

    // Vérifier
    $db = new PDO('sqlite:' . $dossierImport . '/liens.sqlite');
    $nb = (int) $db->query('SELECT COUNT(*) FROM liens')->fetchColumn();
    expect($nb)->toBe(3);

    $prog = json_decode(file_get_contents($dossierImport . '/progress.json'), true);
    expect($prog['statut'])->toBe('import_termine');

    // Nettoyage
    $db = null;
    supprimerImportComplet($userId, $importId);
});
