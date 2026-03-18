# Internal Linking Analyzer

Outil d'analyse du maillage interne pour les professionnels du SEO. Importez votre export CSV de liens internes (Screaming Frog, Sitebulb, etc.) et obtenez un audit complet : PageRank interne, cannibalisation d'ancres, pages orphelines, diversité des ancres, distribution par section, et croisement avec les données Search Console.

Conçu pour les sites à forte volumétrie (100K+ URLs, millions de liens).

## Fonctionnalités

### 9 onglets d'analyse

| Onglet | Description |
|--------|------------|
| **Dashboard** | Score de santé /100, ratio liens/page, profondeur BFS, culs-de-sac, ancres génériques, top 5 problèmes priorisés |
| **PageRank** | Surfeur raisonnable (pondération ancre, cohérence thématique, doublons), treemap par section, efficacité par lien, alertes automatiques |
| **Orphelines** | Pages sans liens entrants + quasi-orphelines (1-2 liens), suggestions de maillage automatiques, filtre par section |
| **Diversité** | Indice de diversité des ancres, détection sur-optimisation (ancre dominante > 60%), filtre prédéfini (faible/sur-opt/dominante) |
| **Hubs & Autorités** | Top hubs/autorités avec ratio IN/OUT, fuites d'équité, pages puits (absorbent le PR sans redistribuer) |
| **Sections** | Matrice heatmap des flux inter-sections, diagnostics de silos, détection d'îlots, top flux sortants |
| **Ancres** | Classification automatique (descriptif/mot-clé/générique/URL nue/vide), nuage par type, détection cannibalisation suspecte |
| **Cannibalisation** | Détection automatique (sans CSV) + manuelle (avec CSV ancre/URL cible), priorisation par impact |
| **GSC Insights** | Croisement maillage × Search Console : pages fortes sans maillage, quick wins, ancre vs requête, budget crawl par section |

### Import intelligent

- Upload par morceaux (chunked) — supporte les fichiers de plusieurs Go
- Chemin serveur direct (SFTP)
- Mapping de colonnes auto-détecté (Screaming Frog, Sitebulb, etc.)
- **Filtre par colonne** à l'import (ex : `Link Position = Content` pour exclure les liens nav/footer/header)

### UX

- Tableaux paginés (50 lignes/page) avec tri, filtre debounce, mode regex (`.*`), export CSV
- URLs copiables au survol
- Bouton retour aux imports
- Mémo descriptif sur chaque onglet
- Score de santé avec jauge visuelle colorée
- Problèmes prioritaires cliquables (naviguent vers l'onglet concerné)
- Nuage d'ancres interactif groupé par type
- Clic sur une ancre → panneau de détail (sources, destinations, paires)

## Installation

### Standalone

```bash
composer install
php -S localhost:8080
```

### Plateforme SEO (mode embedded)

1. Cloner dans le répertoire plugins
2. Installer via l'admin > Plugins
3. Le plugin fonctionne en standalone ET en plateforme sans modification

## Configuration

### Fichier `.env` (optionnel)

Copier `.env.example` :

```bash
cp .env.example .env
```

Aucune clé API requise pour les fonctionnalités de base. Le plugin Search Console nécessite que le plugin GSC de la plateforme soit configuré.

### Filtre à l'import

Pour les exports Screaming Frog "All Inlinks", sélectionnez :
- **Colonne Source** : `Source` (col 0)
- **Colonne Destination** : `Destination` (col 2)
- **Colonne Ancre** : `Anchor` (col 5)
- **Colonne filtre** : `Link Position` (col 1)
- **Valeur** : `Content`

Cela exclut les liens de navigation, footer et header pour une analyse centrée sur le contenu éditorial.

## Architecture technique

### Stack

- **PHP 8.1+** — Backend, analyses, export CSV
- **SQLite** — Stockage des liens par import (1 base par import)
- **JavaScript** vanilla — Frontend, pas de framework
- **Bootstrap 5** — UI, responsive
- **Chart.js** — Treemap PageRank (chargé dynamiquement)

### Structure

```
├── index.php                  # Point d'entrée HTML (9 onglets)
├── app.js                     # Frontend (~2500 lignes)
├── styles.css                 # Styles (~750 lignes)
├── process.php                # Import CSV (chemin serveur)
├── upload_chunk.php           # Import chunked (gros fichiers)
├── worker.php                 # Worker CLI background (import SQLite)
├── progress.php               # Polling progression
├── analyse.php                # Analyse cannibalisation manuelle
├── results.php                # Résultats cannibalisation
├── advanced_analysis.php      # 9 analyses avancées (~1400 lignes)
├── gsc_bridge.php             # Bridge vers la BDD Search Console
├── gsc_analysis.php           # 4 analyses croisées GSC
├── download.php               # Export CSV
├── imports.php                # Gestion des imports précédents
├── functions.php              # Utilitaires (normalisation, sévérité)
├── user_context.php           # Contexte utilisateur (standalone/plateforme)
├── module.json                # Manifest plugin plateforme
└── tests/                     # 51 tests Pest (270 assertions)
```

### Performance (site réel : 56K pages, 2.8M liens)

| Analyse | Temps | Mémoire | JSON |
|---------|-------|---------|------|
| Dashboard | 11s | 271 Mo | 1 Ko |
| PageRank | 16s | 229 Mo | 100 Ko |
| Orphelines | 4s | 302 Mo | 30 Ko |
| Diversité | 1s | 8 Mo | 214 Ko |
| Hubs | 6s | 302 Mo | 55 Ko |
| Sections | 6s | 13 Mo | 65 Ko |
| Ancres | 0.6s | 304 Mo | 1.4 Mo |
| Cannib. auto | 0.3s | 304 Mo | 92 Ko |

Le PageRank utilise un algorithme optimisé avec indices numériques et streaming SQL (pas de `fetchAll` sur les millions de liens). Le PR thématique par section est désactivé automatiquement au-delà de 20K pages.

## Tests

```bash
vendor/bin/pest
```

51 tests, 270 assertions couvrant :
- Fonctions utilitaires (normalisation, sévérité, taille)
- Registre des imports (CRUD)
- Upload chunked + assemblage
- Worker CSV → SQLite
- Filtre par colonne à l'import
- Toutes les analyses avancées (dashboard, orphelins, diversité, hubs, sections, PageRank, ancres, cannibalisation auto)
- Classification d'ancres
- Profondeur BFS
- Score de correspondance ancre/requête (GSC)

## Algorithmes

### PageRank — Surfeur raisonnable

Modèle pondéré avec 3 critères :
1. **Qualité d'ancre** : descriptive (2+ mots) → ×1.5, générique → ×0.5
2. **Cohérence thématique** : intra-section → ×1.3, inter-section → ×0.8
3. **Doublons** : première occurrence → ×1.2, suivantes pénalisées

20 itérations, damping factor 0.85, normalisation max=100.

### Score de santé (0-100)

Composite de pénalités :
- Orphelines > 5% → -15, > 15% → -25
- Faible diversité > 10% → -10, > 25% → -20
- Culs-de-sac > 10% → -10, > 25% → -20
- Ancres génériques > 30% → -10, > 50% → -20
- Ratio liens/page < 2 → -10
- Profondeur moyenne > 4 → -5, > 6 → -15

### Classification des ancres

| Type | Critère | Exemple |
|------|---------|---------|
| `vide` | Chaîne vide | |
| `generique` | Liste de termes connus | "cliquez ici", "en savoir plus" |
| `url_nue` | Commence par http/https/www | "https://example.com" |
| `mot_cle` | 1 mot | "chaussures" |
| `descriptif` | 2+ mots | "guide chaussures running" |

## Licence

Propriétaire — Usage interne.
