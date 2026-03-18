<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

$uploadMax = convertirEnOctets(ini_get('upload_max_filesize') ?: '2M');
$postMax = convertirEnOctets(ini_get('post_max_size') ?: '8M');

// La taille de chunk sûre = min(upload_max, post_max) - marge pour les champs POST multipart
$limiteBrute = min($uploadMax, $postMax);
$tailleChunkSure = (int) floor($limiteBrute * 0.9); // 10% de marge pour l'overhead multipart
$tailleChunkSure = max($tailleChunkSure, 102400); // minimum 100 Ko

repondreJson([
    'upload_max_filesize' => $uploadMax,
    'upload_max_filesize_human' => ini_get('upload_max_filesize'),
    'post_max_size' => $postMax,
    'post_max_size_human' => ini_get('post_max_size'),
    'taille_chunk' => $tailleChunkSure,
    'taille_chunk_human' => formaterOctets($tailleChunkSure),
]);
