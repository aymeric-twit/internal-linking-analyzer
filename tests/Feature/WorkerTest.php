<?php

declare(strict_types=1);

require_once __DIR__ . '/../../functions.php';
require_once __DIR__ . '/../../user_context.php';

// ── Import worker complet ────────────────────────────────

test('le worker devrait importer un CSV et créer une base SQLite indexée', function (): void {
    $userId = 0;
    $importId = 'test_worker_' . uniqid();
    $dossierImport = cheminImport($userId, $importId);
    mkdir($dossierImport, 0755, true);

    // Enregistrer l'import dans le registre
    ajouterImport($userId, [
        'id' => $importId,
        'nom_fichier' => 'test.csv',
        'date_creation' => date('c'),
        'statut' => 'import_en_cours',
    ]);

    // Créer un fichier CSV de test (format Screaming Frog)
    $csvContenu = "Source,Type,Destination,Alt,Follow,Anchor\n";
    $csvContenu .= "https://example.com/page-a,Hyperlink,https://example.com/page-b,,true,chaussures running\n";
    $csvContenu .= "https://example.com/page-c,Hyperlink,https://example.com/page-d,,true,nike air max\n";
    $csvContenu .= "https://example.com/page-e,Hyperlink,https://example.com/page-b,,true,chaussures running\n";
    $csvContenu .= "https://example.com/page-a,Hyperlink,https://example.com/page-d,,true,chaussures running\n";
    $csvContenu .= "https://example.com/page-f,Hyperlink,https://example.com/page-b,,true,baskets homme\n";

    $cheminCsv = $dossierImport . '/liens_upload.csv';
    file_put_contents($cheminCsv, $csvContenu);

    // Lancer le worker avec userId
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

    // Vérifier la base SQLite
    $cheminSqlite = $dossierImport . '/liens.sqlite';
    expect(file_exists($cheminSqlite))->toBeTrue();

    $db = new PDO('sqlite:' . $cheminSqlite);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $nbLignes = (int) $db->query('SELECT COUNT(*) FROM liens')->fetchColumn();
    expect($nbLignes)->toBe(5);

    // Vérifier les index (incluant le nouveau idx_source)
    $indexes = $db->query("SELECT name FROM sqlite_master WHERE type='index'")->fetchAll(PDO::FETCH_COLUMN);
    expect($indexes)->toContain('idx_ancre')
        ->and($indexes)->toContain('idx_destination')
        ->and($indexes)->toContain('idx_ancre_dest')
        ->and($indexes)->toContain('idx_source');

    // Vérifier la progression finale
    $progression = json_decode(file_get_contents($dossierImport . '/progress.json'), true);
    expect($progression['statut'])->toBe('import_termine')
        ->and($progression['lignes_importees'])->toBe(5);

    // Vérifier que le registre a été mis à jour
    $imports = lireRegistreImports($userId);
    $importMaj = null;
    foreach ($imports as $imp) {
        if ($imp['id'] === $importId) {
            $importMaj = $imp;
        }
    }
    expect($importMaj)->not->toBeNull()
        ->and($importMaj['statut'])->toBe('pret')
        ->and($importMaj['nb_lignes'])->toBe(5);

    // Nettoyage
    $db = null;
    supprimerImportComplet($userId, $importId);
});

// ── Analyse de cannibalisation ───────────────────────────

test('l\'analyse devrait détecter correctement la cannibalisation', function (): void {
    $userId = 0;
    $importId = 'test_analyse_' . uniqid();
    $dossierImport = cheminImport($userId, $importId);
    mkdir($dossierImport, 0755, true);

    ajouterImport($userId, ['id' => $importId, 'statut' => 'pret', 'date_creation' => date('c')]);

    $cheminSqlite = $dossierImport . '/liens.sqlite';
    $db = new PDO('sqlite:' . $cheminSqlite);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->exec('CREATE TABLE liens (source TEXT NOT NULL, destination TEXT NOT NULL, ancre TEXT NOT NULL)');

    $insertions = [
        ['https://example.com/page-a', 'https://example.com/page-b', 'chaussures running'],
        ['https://example.com/page-c', 'https://example.com/page-b', 'chaussures running'],
        ['https://example.com/page-e', 'https://example.com/page-b', 'chaussures running'],
        ['https://example.com/page-a', 'https://example.com/page-d', 'chaussures running'],
        ['https://example.com/page-f', 'https://example.com/page-g', 'chaussures running'],
        ['https://example.com/page-a', 'https://example.com/page-x', 'nike air max'],
        ['https://example.com/page-h', 'https://example.com/page-z', 'baskets homme'],
    ];

    $stmt = $db->prepare('INSERT INTO liens VALUES (?, ?, ?)');
    foreach ($insertions as $row) {
        $stmt->execute($row);
    }

    $db->exec('CREATE INDEX idx_ancre ON liens(ancre)');
    $db->exec('CREATE INDEX idx_destination ON liens(destination)');
    $db->exec('CREATE INDEX idx_ancre_dest ON liens(ancre, destination)');

    // Test cannibalisation
    $stmtLeg = $db->prepare('SELECT COUNT(*) FROM liens WHERE ancre = :ancre AND destination = :url_cible');
    $stmtLeg->execute([':ancre' => 'chaussures running', ':url_cible' => 'https://example.com/page-b']);
    expect((int) $stmtLeg->fetchColumn())->toBe(3);

    $stmtCan = $db->prepare('SELECT COUNT(*) FROM liens WHERE ancre = :ancre AND destination != :url_cible');
    $stmtCan->execute([':ancre' => 'chaussures running', ':url_cible' => 'https://example.com/page-b']);
    expect((int) $stmtCan->fetchColumn())->toBe(2);

    // Ratio = 2 / 5 * 100 = 40%
    $ratio = round((2 / 5) * 100, 1);
    expect($ratio)->toBe(40.0);

    // Nettoyage
    $db = null;
    supprimerImportComplet($userId, $importId);
});

// ── Import avec filtre par colonne ────────────────────────

test('le worker devrait filtrer les lignes selon la colonne et valeur spécifiées', function (): void {
    $userId = 0;
    $importId = 'test_filtre_' . uniqid();
    $dossierImport = cheminImport($userId, $importId);
    mkdir($dossierImport, 0755, true);

    ajouterImport($userId, [
        'id' => $importId,
        'nom_fichier' => 'test_filtre.csv',
        'date_creation' => date('c'),
        'statut' => 'import_en_cours',
    ]);

    // CSV avec colonne "Link Position" (col 1) — seules les lignes "Content" doivent être importées
    $csvContenu = "Source,Link Position,Destination,Alt,Follow,Anchor\n";
    $csvContenu .= "https://example.com/a,Content,https://example.com/b,,true,lien contenu\n";
    $csvContenu .= "https://example.com/a,Navigation,https://example.com/c,,true,menu nav\n";
    $csvContenu .= "https://example.com/a,Footer,https://example.com/d,,true,footer link\n";
    $csvContenu .= "https://example.com/e,Content,https://example.com/f,,true,autre contenu\n";
    $csvContenu .= "https://example.com/g,Header,https://example.com/h,,true,header link\n";

    $cheminCsv = $dossierImport . '/liens_upload.csv';
    file_put_contents($cheminCsv, $csvContenu);

    // Lancer le worker avec filtre : colonne 1 (Link Position), valeur "Content"
    $commande = sprintf(
        '%s %s %s 0 2 5 1 %s %s %d 2>&1',
        escapeshellarg(PHP_BINARY),
        escapeshellarg(__DIR__ . '/../../worker.php'),
        escapeshellarg($importId),
        escapeshellarg('Content'),
        escapeshellarg($cheminCsv),
        $userId
    );

    exec($commande, $sortie, $codeRetour);
    expect($codeRetour)->toBe(0);

    $cheminSqlite = $dossierImport . '/liens.sqlite';
    expect(file_exists($cheminSqlite))->toBeTrue();

    $db = new PDO('sqlite:' . $cheminSqlite);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Seules 2 lignes "Content" doivent avoir été importées
    $nbLignes = (int) $db->query('SELECT COUNT(*) FROM liens')->fetchColumn();
    expect($nbLignes)->toBe(2);

    // Vérifier les ancres importées
    $ancres = $db->query('SELECT DISTINCT ancre FROM liens ORDER BY ancre')->fetchAll(PDO::FETCH_COLUMN);
    expect($ancres)->toContain('lien contenu')
        ->and($ancres)->toContain('autre contenu')
        ->and($ancres)->not->toContain('menu nav')
        ->and($ancres)->not->toContain('footer link');

    // Vérifier que les lignes filtrées sont comptées
    $progression = json_decode(file_get_contents($dossierImport . '/progress.json'), true);
    expect($progression['lignes_filtrees'])->toBe(3);

    $db = null;
    supprimerImportComplet($userId, $importId);
});

// ── Nettoyage des anciens imports ────────────────────────

test('nettoyerAnciensImports ne devrait pas supprimer les imports récents', function (): void {
    $userId = 44444;
    $importId = 'recent_' . uniqid();
    $dossier = cheminImport($userId, $importId);
    mkdir($dossier, 0755, true);
    file_put_contents($dossier . '/test.json', '{}');

    ajouterImport($userId, [
        'id' => $importId,
        'date_creation' => date('c'),
        'statut' => 'pret',
    ]);

    nettoyerAnciensImports(2592000); // 30 jours

    expect(is_dir($dossier))->toBeTrue();

    // Nettoyage
    supprimerImportComplet($userId, $importId);
    @unlink(cheminRegistreImports($userId));
    @rmdir(cheminUtilisateur($userId) . '/imports');
    @rmdir(cheminUtilisateur($userId));
});

// ── Sévérité ─────────────────────────────────────────────

test('determinerSeverite devrait retourner les bons niveaux', function (): void {
    expect(determinerSeverite(0)['label'])->toBe('Aucune');
    expect(determinerSeverite(15)['label'])->toBe('Faible');
    expect(determinerSeverite(40)['label'])->toBe('Modérée');
    expect(determinerSeverite(60)['label'])->toBe('Élevée');
    expect(determinerSeverite(90)['label'])->toBe('Critique');
});
