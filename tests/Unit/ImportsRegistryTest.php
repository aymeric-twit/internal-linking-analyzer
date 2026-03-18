<?php

declare(strict_types=1);

require_once __DIR__ . '/../../functions.php';
require_once __DIR__ . '/../../user_context.php';

// ── lireRegistreImports ──────────────────────────────────

test('lireRegistreImports devrait retourner un tableau vide pour un utilisateur sans imports', function (): void {
    expect(lireRegistreImports(99999))->toBe([]);
});

// ── ajouterImport / lireRegistreImports ──────────────────

test('ajouterImport devrait créer le fichier et ajouter une entrée', function (): void {
    $userId = 88888;
    $chemin = cheminRegistreImports($userId);
    $dossier = dirname($chemin);

    // Nettoyage préventif
    if (file_exists($chemin)) {
        unlink($chemin);
    }

    ajouterImport($userId, [
        'id' => 'test-import-1',
        'nom_fichier' => 'test.csv',
        'date_creation' => '2026-03-14T10:00:00',
        'statut' => 'pret',
    ]);

    $imports = lireRegistreImports($userId);
    expect($imports)->toHaveCount(1)
        ->and($imports[0]['id'])->toBe('test-import-1')
        ->and($imports[0]['nom_fichier'])->toBe('test.csv');

    // Ajouter un second import
    ajouterImport($userId, [
        'id' => 'test-import-2',
        'nom_fichier' => 'test2.csv',
        'date_creation' => '2026-03-14T11:00:00',
        'statut' => 'pret',
    ]);

    $imports = lireRegistreImports($userId);
    expect($imports)->toHaveCount(2);

    // Nettoyage
    unlink($chemin);
    @rmdir($dossier);
});

// ── mettreAJourImport ────────────────────────────────────

test('mettreAJourImport devrait modifier les champs d\'un import existant', function (): void {
    $userId = 77777;

    ajouterImport($userId, [
        'id' => 'maj-test',
        'nom_fichier' => 'original.csv',
        'statut' => 'import_en_cours',
    ]);

    mettreAJourImport($userId, 'maj-test', [
        'statut' => 'pret',
        'nb_lignes' => 5000,
    ]);

    $imports = lireRegistreImports($userId);
    expect($imports[0]['statut'])->toBe('pret')
        ->and($imports[0]['nb_lignes'])->toBe(5000)
        ->and($imports[0]['nom_fichier'])->toBe('original.csv');

    // Nettoyage
    unlink(cheminRegistreImports($userId));
    @rmdir(cheminUtilisateur($userId));
});

// ── supprimerImportComplet ───────────────────────────────

test('supprimerImportComplet devrait retirer l\'import et son dossier', function (): void {
    $userId = 66666;
    $importId = 'suppr-test';

    // Créer le dossier et le registre
    $dossier = cheminImport($userId, $importId);
    mkdir($dossier, 0755, true);
    file_put_contents($dossier . '/test.json', '{}');

    ajouterImport($userId, [
        'id' => $importId,
        'nom_fichier' => 'suppr.csv',
        'statut' => 'pret',
    ]);

    // Supprimer
    $resultat = supprimerImportComplet($userId, $importId);
    expect($resultat)->toBeTrue();
    expect(is_dir($dossier))->toBeFalse();
    expect(lireRegistreImports($userId))->toHaveCount(0);

    // Nettoyage
    unlink(cheminRegistreImports($userId));
    @rmdir(cheminUtilisateur($userId) . '/imports');
    @rmdir(cheminUtilisateur($userId));
});

test('supprimerImportComplet devrait retourner false pour un import inexistant', function (): void {
    expect(supprimerImportComplet(55555, 'inexistant'))->toBeFalse();
});
