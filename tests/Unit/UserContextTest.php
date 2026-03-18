<?php

declare(strict_types=1);

require_once __DIR__ . '/../../functions.php';
require_once __DIR__ . '/../../user_context.php';

// ── obtenirUserId ────────────────────────────────────────

test('obtenirUserId devrait retourner 0 en mode standalone', function (): void {
    expect(obtenirUserId())->toBe(0);
});

// ── cheminUtilisateur ────────────────────────────────────

test('cheminUtilisateur devrait retourner le bon chemin', function (): void {
    $chemin = cheminUtilisateur(42);
    expect($chemin)->toEndWith('/data/users/42');
});

// ── cheminImport ─────────────────────────────────────────

test('cheminImport devrait retourner le bon chemin', function (): void {
    $chemin = cheminImport(42, 'abc123');
    expect($chemin)->toEndWith('/data/users/42/imports/abc123');
});

// ── cheminRegistreImports ────────────────────────────────

test('cheminRegistreImports devrait retourner le bon chemin', function (): void {
    $chemin = cheminRegistreImports(42);
    expect($chemin)->toEndWith('/data/users/42/imports.json');
});
