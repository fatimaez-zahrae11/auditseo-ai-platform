# Proposed Project Architecture

## Global Structure
```text

auditseo-ai-platform/
├── backend/      Laravel API REST
├── frontend/     React + Vite
├── ml-service/   FastAPI + scikit-learn (Phase 2 — non prioritaire)
├── docs/         Documentation
├── README.md
└── .gitignore
```
## Backend

The backend is the main part assigned to the student.

It will include:
- Authentication: signup, signin, logout
- PostgreSQL database connection
- SEO audit logic
- SEO scoring system
- SEO issue detection
- Security: validation, authorization, IDOR protection, rate limiting
- Unit and feature tests
- Optional call to ML service *(Phase 2 — non implémenté actuellement)*
- Optional AI Helper for textual recommendations

## Frontend

The frontend will be developed with React + Vite.

It will consume the Laravel API and display:
- Login and signup pages
- SEO audit form
- Dashboard
- Charts
- Audit history
- PDF export

## ML Service (Phase 2 — reporté)

Ce service n'est pas développé dans la phase actuelle du projet.
Le backend fonctionne avec un moteur de règles fixes (rule-based) pour la
classification de sévérité des problèmes SEO. Le service ML pourra être
ajouté plus tard sans modifier l'architecture existante, via un appel HTTP
optionnel depuis Laravel.

Quand il sera développé (Python, FastAPI, scikit-learn), son rôle sera de
classifier les problèmes SEO par sévérité :
- critical
- important
- minor

Laravel appellera ce service via HTTP.

## Testing Strategy

Since the student will mainly work on the backend, unit and feature tests are required.

Planned tests:
- Authentication tests
- API validation tests
- SEO audit tests
- Scoring tests
- Authorization / IDOR tests
- Rate limiting tests
- ML client tests *(Phase 2 — non implémenté actuellement)*

## Notes

- The .env file must never be pushed to GitHub.
- API keys must never be hard-coded.
- External AI is optional and limited to textual recommendations.
- The core of the project remains the secure Laravel backend and SEO audit logic.
- The ML service is deferred to Phase 2 and is not part of the current MVP.



detailed struct :
```text
auditseo-ai-platform/
│
├── backend/                         # MA PARTIE PRINCIPALE : Laravel API REST
│   │
│   ├── app/
│   │   ├── Http/
│   │   │   ├── Controllers/
│   │   │   │   └── Api/
│   │   │   │       ├── AuthController.php
│   │   │   │       # Gère signup, signin, logout, profil utilisateur
│   │   │   │
│   │   │   │       ├── AuditController.php
│   │   │   │       # Gère la création d’un audit SEO, l’historique et les détails
│   │   │   │
│   │   │   │       ├── DomainController.php
│   │   │   │       # Gère les domaines/sites web liés à chaque utilisateur
│   │   │   │
│   │   │   │       ├── RecommendationController.php
│   │   │   │       # Gère les recommandations générées par l’AI Helper
│   │   │   │
│   │   │   │       └── HealthCheckController.php
│   │   │   │       # Endpoint simple pour vérifier que l’API fonctionne
│   │   │   │
│   │   │   ├── Requests/
│   │   │   │   ├── RegisterRequest.php
│   │   │   │   # Validation du formulaire d’inscription
│   │   │   │
│   │   │   │   ├── LoginRequest.php
│   │   │   │   # Validation du formulaire de connexion
│   │   │   │
│   │   │   │   ├── StoreAuditRequest.php
│   │   │   │   # Validation de l’URL avant de lancer un audit SEO
│   │   │   │
│   │   │   │   └── GenerateRecommendationRequest.php
│   │   │   │   # Validation avant l’appel à l’AI Helper
│   │   │   │
│   │   │   └── Middleware/
│   │   │       ├── EnsureUserOwnsAudit.php
│   │   │       # Protection IDOR : empêcher un utilisateur d’accéder à l’audit d’un autre
│   │   │
│   │   │       └── RateLimitAuditRequests.php
│   │   │       # Limiter les requêtes sensibles : login, audit SEO, appels IA
│   │   │
│   │   ├── Models/
│   │   │   ├── User.php
│   │   │   # Utilisateurs de la plateforme
│   │   │
│   │   │   ├── Domain.php
│   │   │   # Site ou domaine analysé
│   │   │
│   │   │   ├── Audit.php
│   │   │   # Résultat global d’un audit SEO
│   │   │
│   │   │   ├── AuditIssue.php
│   │   │   # Problèmes détectés : title absent, image sans alt, lien cassé...
│   │   │
│   │   │   ├── AiRecommendation.php
│   │   │   # Recommandations générées par Anthropic/OpenRouter
│   │   │
│   │   │   └── ApiUsageLog.php
│   │   │   # Journal des appels IA/API pour suivi et sécurité
│   │   │
│   │   ├── Services/
│   │   │   ├── Seo/
│   │   │   │   ├── SeoCrawlerService.php
│   │   │   │   # Récupère le contenu HTML d’une URL
│   │   │   │
│   │   │   │   ├── SeoAnalyzerService.php
│   │   │   │   # Analyse les balises title, meta, H1-H6, images, liens...
│   │   │   │
│   │   │   │   ├── SeoIssueDetectorService.php
│   │   │   │   # Détecte les problèmes SEO à partir de l’analyse (règles fixes)
│   │   │   │
│   │   │   │   └── SeoScoringService.php
│   │   │   │   # Calcule le score global et les sous-scores /100
│   │   │   │
│   │   │   ├── Ml/                              # [PHASE 2 — non implémenté actuellement]
│   │   │   │   └── MlClassifierClient.php
│   │   │   │   # Appelle le service ML FastAPI pour classer la sévérité
│   │   │   │   # (classification gérée par règles fixes dans la phase actuelle)
│   │   │   │
│   │   │   └── Ai/
│   │   │       └── AiRecommendationService.php
│   │   │       # Appelle Anthropic/OpenRouter seulement pour générer des recommandations textuelles
│   │   │
│   │   ├── Policies/
│   │   │   ├── AuditPolicy.php
│   │   │   # Autorisation : vérifier qui peut voir/modifier un audit
│   │   │
│   │   │   └── DomainPolicy.php
│   │   │   # Autorisation : vérifier l’accès aux domaines
│   │   │
│   │   └── Exceptions/
│   │       └── ApiExceptionHandler.php
│   │       # Format propre des erreurs JSON envoyées au frontend
│   │
│   ├── routes/
│   │   └── api.php
│   │   # Déclaration des endpoints API : auth, audits, recommandations, historique
│   │
│   ├── database/
│   │   ├── migrations/
│   │   # Création des tables PostgreSQL
│   │   │
│   │   ├── seeders/
│   │   # Données de test : utilisateurs, domaines, audits fictifs
│   │   │
│   │   └── factories/
│   │   # Génération automatique de fausses données pour les tests
│   │
│   ├── tests/
│   │   ├── Feature/
│   │   │   ├── AuthTest.php
│   │   │   # Vérifie signup, signin, mauvais mot de passe, token
│   │   │
│   │   │   ├── AuditApiTest.php
│   │   │   # Vérifie création d’audit, URL valide/invalide, stockage BDD
│   │   │
│   │   │   ├── AuditAuthorizationTest.php
│   │   │   # Vérifie qu’un utilisateur ne peut pas accéder aux audits d’un autre
│   │   │
│   │   │   ├── RateLimitingTest.php
│   │   │   # Vérifie la limitation des requêtes sensibles
│   │   │
│   │   │   └── RecommendationApiTest.php
│   │   │   # Vérifie l’endpoint de recommandations IA
│   │   │
│   │   └── Unit/
│   │       ├── SeoScoringServiceTest.php
│   │       # Vérifie le calcul du score SEO
│   │
│   │       ├── SeoIssueDetectorServiceTest.php
│   │       # Vérifie la détection des problèmes SEO
│   │
│   │       └── MlClassifierClientTest.php          # [PHASE 2 — non implémenté actuellement]
│   │       # Vérifie l'appel ou le mock du service ML
│   │
│   ├── .env
│   # Fichier local secret : DB password, API keys, token secret
│   # NE JAMAIS envoyer sur GitHub
│   │
│   ├── .env.example
│   # Exemple public sans vrais secrets
│   │
│   ├── composer.json
│   # Dépendances PHP/Laravel
│   │
│   └── phpunit.xml
│       # Configuration des tests unitaires Laravel
│
├── frontend/                        # PARTIE DE L’ENCADRANT : React + Vite
│   │
│   ├── src/
│   │   ├── api/
│   │   │   └── axiosClient.js
│   │   │   # Configuration des appels vers Laravel API
│   │   │
│   │   ├── pages/
│   │   │   ├── Login.jsx
│   │   │   # Page connexion
│   │   │
│   │   │   ├── Register.jsx
│   │   │   # Page inscription
│   │   │
│   │   │   ├── Dashboard.jsx
│   │   │   # Tableau de bord général
│   │   │
│   │   │   ├── AuditCreate.jsx
│   │   │   # Formulaire pour lancer un audit SEO
│   │   │
│   │   │   └── AuditDetails.jsx
│   │   │   # Détails d’un audit : score, problèmes, recommandations
│   │   │
│   │   ├── components/
│   │   │   ├── ScoreCard.jsx
│   │   │   # Carte affichant le score global
│   │   │
│   │   │   ├── IssuesTable.jsx
│   │   │   # Tableau des problèmes SEO détectés
│   │   │
│   │   │   ├── SeoChart.jsx
│   │   │   # Graphiques Chart.js
│   │   │
│   │   │   └── PdfExportButton.jsx
│   │   │   # Export du rapport avec html2canvas + jsPDF
│   │   │
│   │   ├── routes/
│   │   │   └── AppRoutes.jsx
│   │   │   # Organisation des routes frontend
│   │   │
│   │   └── main.jsx
│   │       # Point d’entrée React
│   │
│   ├── package.json
│   # Dépendances frontend : React, Vite, Chart.js, jsPDF...
│   │
│   └── vite.config.js
│       # Configuration Vite
│
├── ml-service/                      # SERVICE MACHINE LEARNING : Python + FastAPI (PHASE 2 — non implémenté)
│   │
│   │   # Ce dossier documente l'architecture cible future.
│   │   # Il n'est pas développé dans la phase actuelle du projet.
│   │
│   ├── app/
│   │   ├── main.py
│   │   # API FastAPI exposant l’endpoint de prédiction
│   │   │
│   │   ├── schemas.py
│   │   # Structure des données reçues et retournées
│   │   │
│   │   ├── predictor.py
│   │   # Charge le modèle ML et retourne la sévérité
│   │   │
│   │   └── model.py
│   │   # Entraînement du modèle scikit-learn
│   │
│   ├── data/
│   │   └── synthetic_seo_issues.csv
│   │   # Jeu de données synthétique pour entraîner le modèle
│   │
│   ├── models/
│   │   └── severity_classifier.pkl
│   │   # Modèle entraîné sauvegardé
│   │
│   ├── tests/
│   │   └── test_predictor.py
│   │   # Tests du modèle ML
│   │
│   ├── requirements.txt
│   # Dépendances Python : fastapi, scikit-learn, pandas...
│   │
│   └── README.md
│       # Explication du service ML
│
├── docs/                            # DOCUMENTATION COMMUNE
│   │
│   ├── architecture.md
│   # Explication de l’architecture globale
│   │
│   ├── api-documentation.md
│   # Liste des endpoints Laravel utilisés par React
│   │
│   ├── database-schema.md
│   # Tables PostgreSQL et relations
│   │
│   ├── security-notes.md
│   # Notes SQL Injection, XSS, IDOR, rate limiting, .env
│   │
│   ├── ml-service.md
│   # Explication du modèle ML cible et de l’appel FastAPI (Phase 2)
│   │
│   └── setup-guide.md
│       # Guide d’installation locale et déploiement VPS
│
├── README.md
│   # Présentation générale du projet sur GitHub
│
├── .gitignore
│   # Empêche d’envoyer .env, vendor, node_modules, fichiers temporaires
│
└── docker-compose.yml               # Optionnel plus tard
    # Peut servir à lancer PostgreSQL, Laravel, frontend et ML ensemble
```
