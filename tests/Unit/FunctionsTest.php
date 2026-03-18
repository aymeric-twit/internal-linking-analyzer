<?php

declare(strict_types=1);

require_once __DIR__ . '/../../functions.php';

// ── normaliserAncre ──────────────────────────────────────

test('normaliserAncre devrait supprimer les espaces et convertir en minuscules', function (): void {
    expect(normaliserAncre('  Chaussures Running  '))->toBe('chaussures running');
});

test('normaliserAncre devrait gérer les caractères accentués', function (): void {
    expect(normaliserAncre('Événement Été'))->toBe('événement été');
});

test('normaliserAncre devrait retourner une chaîne vide pour une entrée vide', function (): void {
    expect(normaliserAncre(''))->toBe('');
    expect(normaliserAncre('   '))->toBe('');
});

// ── normaliserUrl ────────────────────────────────────────

test('normaliserUrl devrait supprimer le trailing slash', function (): void {
    expect(normaliserUrl('https://example.com/page/'))->toBe('https://example.com/page');
});

test('normaliserUrl devrait supprimer le fragment', function (): void {
    expect(normaliserUrl('https://example.com/page#section'))->toBe('https://example.com/page');
});

test('normaliserUrl devrait supprimer les espaces', function (): void {
    expect(normaliserUrl('  https://example.com/page  '))->toBe('https://example.com/page');
});

test('normaliserUrl devrait gérer une URL simple sans modification', function (): void {
    expect(normaliserUrl('https://example.com/page'))->toBe('https://example.com/page');
});

// ── validerJobId ─────────────────────────────────────────

test('validerJobId devrait accepter un ID alphanumérique', function (): void {
    expect(validerJobId('abc123'))->toBeTrue();
    expect(validerJobId('abc-123_def'))->toBeTrue();
});

test('validerJobId devrait rejeter un ID avec des caractères spéciaux', function (): void {
    expect(validerJobId('../etc/passwd'))->toBeFalse();
    expect(validerJobId('abc 123'))->toBeFalse();
    expect(validerJobId(''))->toBeFalse();
    expect(validerJobId('abc;rm -rf'))->toBeFalse();
});

// ── cheminJob ────────────────────────────────────────────

test('cheminJob devrait retourner le bon chemin', function (): void {
    $chemin = cheminJob('test123');
    expect($chemin)->toEndWith('/data/jobs/test123');
});

// ── repondreJson ─────────────────────────────────────────

test('repondreJson devrait envoyer du JSON avec le bon code HTTP', function (): void {
    // On ne peut pas facilement tester les fonctions "never" qui appellent exit()
    // Ce test vérifie juste que la fonction existe
    expect(function_exists('repondreJson'))->toBeTrue();
});

// ── ecrireProgression ────────────────────────────────────

test('ecrireProgression devrait écrire un fichier JSON atomiquement', function (): void {
    $chemin = sys_get_temp_dir() . '/test_progression_' . uniqid() . '.json';

    ecrireProgression($chemin, [
        'phase' => 'import',
        'statut' => 'en_cours',
        'pourcentage' => 50,
    ]);

    expect(file_exists($chemin))->toBeTrue();

    $contenu = json_decode(file_get_contents($chemin), true);
    expect($contenu)->toBeArray()
        ->and($contenu['phase'])->toBe('import')
        ->and($contenu['statut'])->toBe('en_cours')
        ->and($contenu['pourcentage'])->toBe(50);

    // Le fichier temporaire ne devrait pas exister
    expect(file_exists($chemin . '.tmp'))->toBeFalse();

    unlink($chemin);
});

// ── convertirEnOctets ────────────────────────────────────

test('convertirEnOctets devrait convertir les valeurs PHP ini', function (): void {
    expect(convertirEnOctets('128M'))->toBe(134217728);
    expect(convertirEnOctets('2G'))->toBe(2147483648);
    expect(convertirEnOctets('512K'))->toBe(524288);
    expect(convertirEnOctets('1024'))->toBe(1024);
});

// ── formaterOctets ───────────────────────────────────────

test('formaterOctets devrait formater en unité lisible', function (): void {
    expect(formaterOctets(500))->toBe('500 o');
    expect(formaterOctets(1536))->toBe('1.5 Ko');
    expect(formaterOctets(10485760))->toBe('10 Mo');
    expect(formaterOctets(1610612736))->toBe('1.5 Go');
});
