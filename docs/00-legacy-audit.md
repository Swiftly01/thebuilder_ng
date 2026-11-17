# Legacy audit

The existing backend is Laravel 12 with ticket, sponsor, volunteer, event registration, QR/PDF and historical migrations. The new brief supersedes earlier compatibility plans. See legacy-source-inventory.json for every application PHP file and dependency classification. No historical migration or broad directory is ported. Pure TOTP logic is the only initial port candidate; its algorithm and RFC vectors will be retested.

Documentation audited: 
- agent-1-forensics.md
- agent-2-architecture.md
- agent-3-design-system.md
- agent-4-public-pages.md
- agent-5-auth.md
- agent-6-dashboards.md
- agent-7-responsive.md
- agent-8-qa.md
- COLOR-TOKENS.md
- planning/backend-implementation/00-executive-assessment.md
- planning/backend-implementation/01-documentation-inventory.md
- planning/backend-implementation/02-frontend-route-audit.md
- planning/backend-implementation/03-requirements-traceability.md
- planning/backend-implementation/04-backend-gap-analysis.md
- planning/backend-implementation/05-system-architecture.md
- planning/backend-implementation/06-database-architecture.md
- planning/backend-implementation/07-api-contract.md
- planning/backend-implementation/08-authentication-authorization.md
- planning/backend-implementation/09-cms-architecture.md
- planning/backend-implementation/10-youtube-integration.md
- planning/backend-implementation/11-stellar-deployment.md
- planning/backend-implementation/12-frontend-integration.md
- planning/backend-implementation/13-security-risk-assessment.md
- planning/backend-implementation/14-testing-strategy.md
- planning/backend-implementation/15-implementation-roadmap.md
- planning/backend-implementation/16-production-readiness.md
- planning/backend-implementation/17-implementation-progress.md
- planning/backend-implementation/BACKEND_DATABASE_AUDIT.md
- planning/backend-implementation/FRONTEND_BACKEND_AUDIT.md
- planning/backend-implementation/project-documentation-audit.md
- planning/backend-implementation/README.md
- README.md
- The Builder — SDLC Phase 1 Planning & Feasibility.md
- The Builder — SDLC Phase 2 Requirements Analysis (SRS).md
- The Builder — SDLC Phase 3 System Design.md
- The Builder — SDLC Phase 4 Implementation.md
- The_Builder_Functional_Requirements.md

MVP authority: Functional Requirements section 29 and SRS; latest brief fixes Laravel 12, MySQL, clean /api/v1 and frontend preservation. Personalised recommendations, social login, comments, following and podcasts remain deferred. Visible editorial events will use a clean optional listing, never ticketing.
