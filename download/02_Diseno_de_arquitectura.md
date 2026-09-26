# Diseño de Arquitectura — Sistema de Gestión de Pensionados (SGP)

| Campo | Valor |
|---|---|
| Proyecto | Sistema de Gestión de Pensionados (SGP) |
| Cliente | Ministerio de Trabajo |
| Documento | Diseño de Arquitectura |
| Versión | 1.6 |
| Fecha | 2026-09-27 |
| Estado | Borrador para revisión del equipo de desarrollo |
| Documentos relacionados | `Requisitos funcionales.md`, `Modelo de datos.md` |

---

## 1. Introducción

### 1.1 Propósito y alcance

Este documento define la arquitectura del SGP: la visión del sistema, el stack tecnológico, la estructura modular, los patrones de diseño y su correspondencia con los principios SOLID, la estrategia de pruebas TDD, la seguridad, la auditoría y el plan de desarrollo por fases y sprints. Es la guía vinculante para el equipo: cualquier decisión de implementación que contravenga este documento debe pasar por una revisión arquitectónica y quedar registrada como ADR (sección 16).

El alcance arquitectónico cubre el backend (API REST + dominio + persistencia). El frontend del Ministerio y la migración de datos legados se tratan como elementos periféricos con contratos definidos aquí.

### 1.2 Principios rectores

1. **Dominio primero**: la lógica de negocio (cálculo de pensión, máquina de estados, elegibilidad) vive en el dominio, framework-agnóstica y probada con tests puros sin base de datos.
2. **TDD como proceso, no como fase**: cada unidad de comportamiento nace de un test en rojo; la suite es la especificación ejecutable de los requisitos.
3. **SOLID aplicado con pragmatismo**: abstracciones donde hay variabilidad real (regímenes de cálculo), no especulativas.
4. **Base de datos como último recurso de integridad**: las invariantes críticas (unicidad, rangos, estados) se garantizan con constraints además de validación de aplicación.
5. **Auditoría por diseño**: ninguna operación crítica ocurre sin evidencia persistente.

## 2. Stack tecnológico

| Componente | Versión de referencia | Justificación |
|---|---|---|
| PHP | 8.3+ (hasta 8.4) | Enums respaldados, propiedades readonly, tipos estrictos; base de Laravel 12 |
| Laravel | 12.x | Línea base estable al momento del diseño; convenciones aquí descritas aplican igualmente a versiones superiores |
| MySQL | 8.4 LTS (InnoDB, utf8mb4) | Canal LTS con soporte extendido, idóneo para sistemas gubernamentales; CHECK constraints enforceados, CTEs, ventanas y JSON nativo |
| Framework de pruebas | Pest 3 (sobre PHPUnit 11) | Sintaxis declarativa, datasets nativos para los casos tabulados de cálculo |
| Calidad estática | PHPStan nivel 8, Laravel Pint, Rector | Detección temprana de regresiones de tipos y estilo |
| Arquitectura de módulos | deptrac | Verificación automática de las reglas de dependencia entre módulos en CI |
| RBAC | spatie/laravel-permission 6 | Estándar de facto, mantenido, compatible con policies de Laravel |
| Auditoría | spatie/laravel-activitylog 4 | Bitácora genérica de acciones sobre modelos, extensible |
| DTOs | readonly classes nativas | Sin dependencia extra; contratos explícitos entre capas |
| Entornos | Docker + docker Compose | Paridad dev/staging/prod on-premise, requisito RNF-006 |

**Nota sobre MySQL 8.4 LTS vs canal Innovation (9.x)**: el canal Innovation incorpora características con ciclo de soporte corto. Para un sistema estatal con horizonte de vida de una década se selecciona el LTS: las funcionalidades usadas (constraints, transacciones con bloqueo pesimista, `utf8mb4_0900_ai_ci`, JSON) están todas disponibles. La capa de acceso a datos evita SQL propietario no portable, de modo que una futura migración de versión no toca el dominio.

## 3. Visión arquitectónica: monolito modular

### 3.1 Decisión

El SGP se construye como **monolito modular**: una única aplicación desplegable con fronteras internas estrictas por módulo, en lugar de microservicios. Razones:

- El dominio es acotado y de volumen moderado (una organización, un proceso de negocio), sin presión de escalamiento independiente.
- El equipo es pequeño; los microservicios impondrían coste operativo (despliegue, observabilidad, consistencia distribuida) sin beneficio real.
- Las transacciones que cruzan agregados (aprobación de expediente + alta de pensionado + historial) exigen atomicidad fuerte, trivial en un monolito y costosa entre servicios.
- La modularización estricta conserva la puerta abierta: un módulo con fronteras limpias puede extraerse a un servicio si algún día lo exige la carga.

### 3.2 Criterios de modularización

Cada módulo encapsula un agregado de negocio o un conjunto cohesivo de catálogos, y se comunica con los demás únicamente a través de sus servicios de aplicación (capa Application) o de contratos del módulo Shared. Se prohíbe: (a) usar modelos Eloquent de otro módulo, (b) consultar tablas ajenas directamente, y (c) invocar la capa Infrastructure de otro módulo. deptrac materializa estas reglas en el pipeline de CI.

## 4. Estructura de módulos

| Módulo | Responsabilidad | Puede depender de |
|---|---|---|
| `Shared` | Kernel común: contratos (Clock, SequenceGenerator, TransactionManager), base de value objects, traits de auditoría, excepciones de dominio | ninguno |
| `Catalogs` | Catálogos simples y geográficos (provincias, municipios, agencias, organismos, clasificadores) | `Shared` |
| `Settings` | Configuración general versionada y secuencias de numeración | `Shared` |
| `People` | Entidad Persona y su ciclo de vida (alta, edición, fallecimiento, búsqueda) | `Shared`, `Catalogs` (razas) |
| `Organizations` | Entidades, oficinas, jerarquías, cargos y firmas autorizadas | `Shared`, `Catalogs`, `People` |
| `LegalBasis` | Tipos y bases legales, vigencias | `Shared`, `Catalogs` (organismos) |
| `PensionCases` | Expedientes, subregistros (salarios, servicios, ciclos), máquina de estados e historial | `Shared`, `People`, `Organizations`, `Catalogs`, `LegalBasis` |
| `PensionCalculation` | Motor de cálculo: elegibilidad, años de servicio, promedio, cuantía; simulación y persistencia de resultados | `Shared`, `Settings`, `PensionCases` |
| `Pensioners` | Pensionados, ciclo de vida de la pensión, reclasificación | `Shared`, `People`, `Catalogs`, `PensionCases` |
| `Payments` | Control bancario, secuencias de uso, nómina electrónica, pagos (propuesta) | `Shared`, `Catalogs`, `Pensioners` |
| `Security` | Autenticación, RBAC, usuarios, auditoría de acciones | `Shared`, `People` |
| `Reporting` | Reportes estadísticos y exportaciones (solo lectura) | todos, vía contratos de lectura |

Estructura de directorios (extracto ilustrativo):

```
app/
├── Modules/
│   ├── Shared/
│   │   ├── Contracts/          (ClockInterface, SequenceGeneratorInterface, ...)
│   │   ├── Support/            (Money, CubanIdentityNumber, Period)
│   │   └── Exceptions/
│   ├── Catalogs/
│   │   ├── Domain/             (entidades puras, invariantes)
│   │   ├── Application/        (Actions, Services, DTOs, contratos de repositorio)
│   │   ├── Infrastructure/     (modelos Eloquent, repositorios, seeders)
│   │   ├── Presentation/       (Controllers, Requests, Resources, Routes)
│   │   ├── Tests/              (Unit/ y Feature/ del módulo)
│   │   └── CatalogsServiceProvider.php
│   ├── PensionCases/           (misma estructura interna)
│   ├── PensionCalculation/
│   │   ├── Domain/
│   │   │   ├── Strategies/     (RegimeCalculationStrategyInterface + implementaciones)
│   │   │   ├── EligibilityChecker.php
│   │   │   └── PensionCalculator.php
│   │   ├── Application/        (CalculatePensionAction, PreviewCalculationAction)
│   │   └── ...
│   └── ...
├── Providers/
└── Support/

database/
├── migrations/                 (una por tabla, orden determinístico)
├── seeders/                    (CatalogsSeeder, CubaGeographySeeder, ...)
└── factories/
```

## 5. Estructura interna de capas

Cada módulo sigue la misma estratificación pragmática inspirada en arquitectura hexagonal:

| Capa | Contenido | Depende de |
|---|---|---|
| `Domain` | Entidades y value objects puros (sin Eloquent, sin HTTP), invariantes, excepciones de negocio | nada |
| `Application` | Actions/Services orquestando casos de uso, DTOs readonly, contratos de repositorio, eventos de dominio | `Domain` |
| `Infrastructure` | Modelos Eloquent, implementaciones de repositorios, observers, seeders, adapters externos | `Domain` + `Application` (implementa contratos) |
| `Presentation` | Controllers, FormRequests, API Resources, rutas del módulo | `Application` (vía interfaces) |

### 5.1 Flujo de una solicitud (ejemplo: aprobar expediente)

```
POST /api/v1/pension-cases/{id}/transitions
  └─ ApproveCaseRequest            (validación HTTP: base_legal_id, nota)
      └─ PensionCaseController     (delgado: resuelve y delega)
          └─ ApproveCaseAction     (Application: unidad de caso de uso)
              ├─ PensionCaseRepository (contrato de Application)
              ├─ PensionCalculator     (Domain: recalcula y valida elegibilidad)
              ├─ CaseStateMachine      (Domain: valida transición permitida)
              ├─ DB::transaction()     (TransactionManager de Shared)
              │    ├─ persiste estado approved + resolución
              │    ├─ registra CaseHistory (append-only)
              │    └─ CreatePensionerAction (mismo módulo Pensioners, vía contrato)
              └─ PensionCaseResource → respuesta JSON 200
```

Reglas de flujo: los controllers no contienen lógica de negocio ni Eloquent; las Actions son invocables (`__invoke`) y de una sola responsabilidad; toda escritura multi-tabla pasa por `TransactionManager` (wrapper de `DB::transaction` inyectado, testeable con fake).

### 5.2 Corolario de persistencia

Eloquent se confina a `Infrastructure`: los modelos mapean tablas, definen relaciones y casts, y materializan entidades de dominio (hidratación manual o mediante mappers delgados del módulo). Las consultas complejas viven en repositorios; los controllers nunca llaman al facade `DB` ni usan `Model::query()` de otro módulo. Este confinamiento es lo que permite probar el dominio (cálculo, estados) sin base de datos y a máxima velocidad.

**Materialización y enforcement (ADR-11, 2026-09-26).** El patrón dejó de ser solo convención: el módulo Security es la plantilla canónica (`Application/Contracts` para los puertos de repositorio, `Application/Services` para los casos de uso, `Application/DTO` para contratos de entrada/salida readonly, `Infrastructure/Persistence` para el único acceso a datos, y controllers que solo validan, delegan y traducen la respuesta). Las 4 capas están esqueletizadas en los 12 módulos (`scripts/gen-modules.php`) y `backend/tests/Architecture/LayeringTest.php` rompe el build en CI cuando alguien consulta la BD desde Presentation, usa facades/HTTP/queries en Application, ensucia Domain con Eloquent o importa Presentation desde Infrastructure. Los modelos viven en `Modules/<M>/Infrastructure/Persistence/Models` (el namespace raíz `App\Models` quedó prohibido y verificado); el binding interfaz → implementación se registra en el `ServiceProvider` de cada módulo.

**Contracts también para los servicios (ADR-12, 2026-09-26).** La misma disciplina se extendió a los casos de uso: cada clase de `Application/Services` expone su interfaz en `Application/Contracts` (`AuthServiceInterface` es la plantilla en Security), los controllers solo conocen esos contratos y el `ServiceProvider` del módulo resuelve el binding por defecto. `LayeringTest` lo blinda con dos reglas adicionales: R5 impide que Presentation importe servicios concretos y R6 rompe el build si aparece un servicio sin contrato, de modo que la frontera HTTP queda completamente invertida (DIP), los controllers se prueban con stubs del caso de uso y decoradores (auditoría, rate limiting, variantes en cola) pueden cablearse sin tocar una línea de código HTTP.

**Estampado de auditoría (ADR-14, 2026-09-26).** El trío de trazabilidad del modelo de datos (`created_by`/`updated_by` FK autoreferencial con `restrictOnDelete` + `deleted_at`) aterrizó por primera vez en `users` con relleno automático: el observer genérico `Shared\Support\AuditableObserver` estampa el actor en cada `creating`/`updating` de cualquier modelo que declare las columnas en `$fillable` (los valores explícitos de seeders/importaciones se preservan y el contexto anónimo/CLI nunca borra historia), y Laravel lo resuelve vía contenedor para que el puerto `Shared\Contracts\CurrentUserProviderInterface` se inyecte sin que el código de negocio toque la sesión (DIP). La implementación vive en `Security\Infrastructure\Authentication\AuthenticatedUserIdProvider` (guard por defecto + fallback sanctum) porque solo Security toca la autenticación; al residir el puerto en Shared, cualquier módulo de la Fase 1+ adopta el patrón con una línea en su provider — `Model::observe(AuditableObserver::class)` — sin violar deptrac. Las cuentas con borrado lógico quedan excluidas de la autenticación (login 401) y de toda consulta Eloquent.

**Catálogos como recurso genérico (ADR-15, 2026-09-26).** Los 18 catálogos de la Fase 1 aterrizaron con un patrón de registry: `Catalogs\Application\CatalogRegistry` declara la definición inmutable de cada catálogo uniforme (modelo, columnas, reglas de validación, dependientes que bloquean la desactivación) y de ella beben las tres piezas — los FormRequests arman sus reglas, el `CatalogService` genérico ejecuta los invariantes (unicidad RN-008 anticipada al 422, código inmutable, desactivación lógica bloqueada por referencias activas) y el `EloquentCatalogRepository` resuelve el class-string del modelo. Un solo par de servicio/repositorio con contrato (ADR-11/12) cubre los 16 catálogos uniformes tras `/api/v1/catalogs/{type}`; municipios y agencias tienen servicios propios porque sus claves naturales y reglas difieren (clave compuesta provincia+municipio; coherencia RN-04 garantizada en BD por FK compuesta sobre `municipalities(id, province_id)`, además de la validación de servicio). Los 18 modelos extienden `CatalogModel` (soft delete como desactivación, estampado de autoría por `AuditableObserver` registrado en el provider iterando el registry) y deliberadamente no importan tipos de Security — el autor se expone como id y la bitácora humana llega con RF-AUD-001.

**Configuración general versionada (ADR-16, 2026-09-27).** RN-007 aterrizó en el módulo Settings como la primera lógica de dominio pura del proyecto: `EffectiveSettingsResolver` fija la semántica de vigencia (la versión con mayor `effective_from` menor o igual a la fecha, con la fecha propia incluida) sobre una proyección mínima (`EffectiveSettingCandidate`: id + fecha), testeada con datasets unitarios sin BD; el repositorio materializa la línea de tiempo y el servicio la orquesta con el puerto `ClockInterface` («hoy» nunca se consulta al sistema directamente, la prueba lo congela). El no-solapamiento queda garantizado en BD por `UNIQUE(effective_from)` — fechas distintas particionan el tiempo en vigencias disjuntas —, anticipado al 422 semántico por la sonda del servicio y probado hasta el `QueryException`; el CHECK `max ≥ base` se replica en BD y en validación. Las versiones son inmutables (RF-CAT-005: la corrección crea una vigencia nueva; el recurso no expone update y PATCH responde 405) y solo las vigencias futuras pueden eliminarse (409 `VersionAlreadyEffectiveException`); la FK `pension_cases.calculation_setting_id` de la Fase 3 reforzará la regla en BD. `effective_to` es derivado (día anterior a la siguiente vigencia, null en la más reciente): se calcula en lectura y nunca se desnormaliza.

## 6. Patrones de diseño y correspondencia SOLID

### 6.1 Patrones aplicados

| Patrón | Dónde | Para qué |
|---|---|---|
| Action (comando) | `Application/*` de todos los módulos | Un caso de uso = una clase invocable; orquestación legible y testeable |
| Repository (delgado) | `Application/Contracts` + `Infrastructure` | Desacoplar dominio de Eloquent y permitir fakes en tests |
| Strategy | `PensionCalculation/Domain/Strategies` | Variantes de cálculo por régimen (general, especiales) sin tocar el motor |
| State (máquina) | `PensionCases/Domain/CaseStateMachine` + enum respaldado | Transiciones del expediente centralizadas, verificadas e inmutables |
| Value Object | `Shared/Support` (Money, Period, CubanIdentityNumber) | Invariantes de datos primitivos (dinero, rangos de fechas, CI) |
| DTO readonly | `Application` | Contratos de entrada/salida entre capas; sin mutación |
| Observer | `Infrastructure` (observers de auditoría) | Bitácora automática en eventos Eloquent de modelos críticos |
| Factory | `database/factories` | Fixtures de tests reproducibles |
| Adapter | `Settings/Infrastructure` (SequenceGenerator sobre MySQL) | El dominio pide " siguiente número"; la infraestructura lo resuelve con bloqueo pesimista |

### 6.2 SOLID en concreto

- **S — Single Responsibility**: `PensionCalculator` calcula; `EligibilityChecker` evalúa criterios; `ApproveCaseAction` orquesta; `PensionCaseController` transporta HTTP. Ninguna clase acumula dos de estas funciones.
- **O — Open/Closed**: agregar un régimen especial de pensión = nueva clase `XRegimeStrategy` + registro en el resolver; el motor `PensionCalculator` no se modifica (abierto a extensión, cerrado a cambio).
- **L — Liskov**: toda implementación de `RegimeCalculationStrategyInterface` acepta el mismo `CalculationInput` y devuelve `CalculationResult`; los tests de contrato (dataset compartido) garantizan sustituibilidad.
- **I — Interface Segregation**: contratos mínimos y específicos: `ClockInterface`, `SequenceGeneratorInterface`, `TransactionManagerInterface`; los repositorios exponen solo los buscadores que el caso de uso usa.
- **D — Dependency Inversion**: controllers y actions dependen de interfaces vinculadas en el `ServiceProvider` del módulo (`$this->app->bind(PensionCaseRepositoryInterface::class, EloquentPensionCaseRepository::class)`), lo que permite reemplazar implementaciones e inyectar fakes en tests.

### 6.3 Enum respaldado de estados (extracto)

```php
<?php

namespace App\Modules\PensionCases\Domain;

enum CaseStatus: string
{
    case Submitted   = 'submitted';
    case UnderReview = 'under_review';
    case Approved    = 'approved';
    case Rejected    = 'rejected';

    public function transitionsTo(self $target): bool
    {
        return in_array($target, self::allowedTransitions()[$this], true);
    }

    /** @return array<self, list<self>> */
    private static function allowedTransitions(): array
    {
        return [
            self::Submitted   => [self::UnderReview, self::Rejected],
            self::UnderReview => [self::Approved, self::Rejected, self::Submitted],
            self::Approved    => [],  // terminal; reapertura solo vía acción administrativa
            self::Rejected    => [],
        ];
    }
}
```

La misma tabla de transiciones alimenta la especificación de tests (`Pest` dataset) y la validación del API, garantizando que doc, código y pruebas no divergen.

## 7. Modelo de dominio y transacciones

Agregados identificados: **Persona** (identidad y ciclo de vida), **Expediente** (raíz que gobierna salarios, servicios, ciclos e historial; sus invariantes — serie salarial sin años duplicados, servicios sin solapamientos, transiciones válidas — se validan dentro del agregado), **Pensionado** (referencia a persona y expediente de origen; unicidad de pensión activa por persona) y **Control bancario** (unicidad de control activo por pensionado). Las invariantes de cada agregado se protegen por triple vía: dominio (excepción), application (validación previa) y base de datos (constraints), en cumplimiento de RNF-008 y RN-008.

Las escrituras que cruzan agregados ocurren en una transacción de base de datos única. Caso paradigmático: la aprobación (`approved`) persiste la decisión, congela el cálculo con su versión de configuración, escribe el historial y crea el pensionado de forma atómica; si cualquiera falla, todo se revierte (RF-EXP-007 y RF-PEN-001).

## 8. Gestión de datos

- **Migraciones**: una por tabla, convenciones del `Modelo de datos.md`, `foreignId()->constrained()` con `RESTRICT` explícito, índices nombrados y CHECKs vía `DB::statement` cuando el builder no los cubre. Prohibido editar migraciones ya aplicadas en entornos compartidos: se versiona con migración nueva.
- **Seeders**: `CubaGeographySeeder` (15 provincias, 168 municipios), `CatalogsSeeder` (clasificadores), `OrganizationsSeeder` (organismos), `RolesAndPermissionsSeeder` y `SettingsSeeder` (configuración inicial + secuencia de control bancario). Idempotentes vía `upsert` sobre claves naturales.
- **Repositorios**: contratos en `Application/Contracts` con firmas de caso de uso (p. ej. `ofStatus(CaseStatus $s): LazyCollection`), no CRUD genérico; la paginación se resuelve con objetos de página, no exponiendo query builders.
- **Secuencias**: `numbering_sequences` con incremento dentro de transacción y `SELECT ... FOR UPDATE`; los huecos tras rollback son aceptados por diseño (RN-009: nunca reutilizar).

## 9. API REST

### 9.1 Convenciones

- Base versionada: `/api/v1`; JSON UTF-8; autenticación por token Sanctum (portador) para integradores y sesión para el frontend.
- Recursos en plural inglés kebab-case: `pension-cases`, `bank-controls`.
- Colecciones paginadas con envoltura `data` + `meta` (page, per_page, total). Errores con RFC 9457 `application/problem+json` (title, status, detail, y `errors` de validación).
- Transiciones de estado como subrecurso declarativo: `POST /pension-cases/{id}/transitions` con cuerpo `{"to": "approved", "note": "...", "legal_basis_id": 12}` — evita verbos RPC ambiguos.
- Concurrencia optimista: si `updated_at` del cliente difiere, respuesta `409` con problem+json (RF-API-003).

### 9.2 Endpoints principales

| Método | Ruta | Permiso | Descripción |
|---|---|---|---|
| POST | `/api/v1/auth/login` | público | Autenticación (rate limited) |
| GET/POST/PATCH/DELETE | `/api/v1/catalogs/{type}` (+ `/{id}`) | `catalogs.view/manage` | CRUD de catálogos uniformes (recurso genérico ADR-15: 16 tipos) |
| GET/POST/PATCH/DELETE | `/api/v1/municipalities` (+ `/{id}`) | `catalogs.view/manage` | CRUD de municipios con filtro por provincia (RF-CAT-002) |
| GET/POST/PATCH/DELETE | `/api/v1/agencies` (+ `/{id}`) | `catalogs.view/manage` | CRUD de agencias bancarias con coherencia RN-04 (RF-CAT-003) |
| GET/POST/DELETE | `/api/v1/general-settings` (+ `/{id}`, `/current?at=…`) | `settings.view/manage` | Configuración general versionada (RF-CAT-005, RN-007): sin update — versiones inmutables; `/current` resuelve la vigencia a una fecha |
| GET/POST/PATCH | `/api/v1/people` (+ `/{id}`) | `people.*` | CRUD de personas |
| GET | `/api/v1/people?identity=…` | `people.view` | Búsqueda por identidad |
| GET/POST/PATCH | `/api/v1/entities`, `/api/v1/offices` | `organizations.*` | Estructura organizacional |
| GET/POST | `/api/v1/legal-bases` | `legalbases.*` | Base legal |
| GET/POST | `/api/v1/pension-cases` | `cases.view/create` | Listado/filtro (estado, oficina, fechas) y alta |
| GET | `/api/v1/pension-cases/{id}` | `cases.view` | Detalle con subregistros e historial |
| POST/PATCH/DELETE | `/api/v1/pension-cases/{id}/salary-records` … | `cases.edit` | Subrecursos salarios/servicios/ciclos |
| POST | `/api/v1/pension-cases/{id}/transitions` | `cases.review/approve/reject` | Máquina de estados |
| POST | `/api/v1/pension-cases/{id}/calculation-preview` | `cases.calculate` | Simulación sin persistencia |
| GET | `/api/v1/pensioners` (+ `/{id}`) | `pensioners.view` | Pensionados y ficha |
| PATCH | `/api/v1/pensioners/{id}/status` | `pensioners.manage` | Suspensión/terminación/reclasificación |
| GET/POST | `/api/v1/bank-controls` | `payments.*` | Control bancario (asignación y activo) |
| GET | `/api/v1/reports/{report}` | `reports.view` | Reportes con parámetros de rango |
| GET | `/api/v1/exports/{report}.csv` | `reports.export` | Exportaciones |
| GET | `/api/v1/settings/general` | `settings.view` | Configuración vigente y versiones |

### 9.3 Documentación interactiva (OpenAPI/Swagger)

La documentación de la API no es un artefacto aparte: se genera desde el código con atributos OpenAPI (`zircote/swagger-php` 6) integrados en la capa Presentation de cada módulo (`#[OA\Post]` sobre la acción del controller, `#[OA\Schema]` sobre el Resource), de modo que el spec viaja con el endpoint que documenta y no puede quedar obsoleto en silencio (ADR-13). `darkaonline/l5-swagger` expone la UI y el JSON:

- **UI interactiva**: `GET /api/documentation` — exploración y "Try it out" real contra la API.
- **Spec OpenAPI 3**: `GET /api/docs` — consumible por generadores de clientes y por el futuro frontend del Ministerio.
- **Esquema de seguridad**: `sanctumAuth` (HTTP Bearer) documentado a nivel de operación; los endpoints protegidos lo declaran y la UI solicita el token al probar.
- El envelope RF-API-002 (`data`/`message`/422 con `errors` por campo) queda documentado con ejemplos en cada respuesta, incluidas las formas de error 401/422.
- Los metadatos globales (info, tag `Auth`, security scheme) viven en `app/OpenApi/ApiDoc.php`, fuera de las fronteras de módulos, igual que `routes/api.php`.

Blindaje: `tests/Feature/ApiDocsTest.php` es el contrato de la documentación — falla si un endpoint publicado no aparece en el spec, si el esquema de seguridad se pierde o si la UI deja de responder, por lo que "endpoint sin documentar" rompe el build igual que una capa violada. La regeneración del spec se controla con `L5_SWAGGER_GENERATE_ALWAYS` (true en desarrollo y tests; en producción se desactiva y se genera en el pipeline de despliegue — endurecimiento de la Fase 6).

## 10. Seguridad

### 10.1 Autenticación y RBAC

- Login por email + contraseña (Argon2id), bloqueo temporal tras 5 intentos fallidos (cache), tokens Sanctum con expiración y revocación por usuario.
- Roles: `admin`, `director`, `specialist`, `operator`, `auditor`; permisos granulares `modulo.accion` gestionados con spatie/laravel-permission.
- Policies de modelo por recurso (p. ej. `PensionCasePolicy::approve` exige rol director Y oficina en ámbito territorial del usuario) — la autorización vive en el backend, nunca delegada al cliente.
- Middleware de rate limiting global (60 req/min por token) y reforzado en login (RF-SEG-001).

### 10.2 Endurecimiento

Validación estricta de entrada en FormRequests (tipos, rangos, reglas de dominio como `CubanIdentityNumber`); escape de salida en recursos; consultas siempre parametrizadas vía Eloquent; cabeceras de seguridad (CSP, X-Frame-Options, HSTS en terminación TLS del proxy); logs de seguridad sin datos sensibles (nunca contraseñas ni CI completos).

## 11. Auditoría y trazabilidad

Tres mecanismos complementarios:

1. **Bitácora de acciones** (spatie/laravel-activitylog + observers de Infrastructure): registra create/update/delete de modelos críticos con atributos antiguos y nuevos, usuario y contexto (`request_id`).
2. **Historial de estados** (`pension_case_histories`, append-only, RN-010): cada transición con `from_status`, `to_status`, usuario, nota y fecha.
3. **Columnas de trazabilidad** (`created_by`, `updated_by`, `deleted_at`) en tablas de negocio; borrado lógico con restauración exclusiva de `admin`, auditada.

La consulta de bitácoras (RF-AUD-003) filtra por usuario, modelo, acción y fechas, con exportación CSV. La bitácora no admite UPDATE/DELETE desde la aplicación; el acceso directo a BD queda fuera del alcance de la app y sujeto a procedimiento de seguridad de la infraestructura.

## 12. Estrategia TDD

### 12.1 Pirámide y reparto

| Nivel | Qué prueba | Herramientas | Objetivo de cobertura |
|---|---|---|---|
| Unitarios (≈70 %) | Dominio puro: cálculo, máquina de estados, value objects, validators, estrategias por régimen | Pest 3, datasets tabulados, sin BD | ≥ 90 % del dominio y application |
| Feature/API (≈20 %) | Endpoints completos: validación, permisos, transiciones, envolvente JSON, problem+json | Pest + RefreshDatabase + factories + Sanctum actingAs | ≥ 80 % global |
| Integración/BD (≈10 %) | Repositorios, constraints (unicidad, CHECK), transacciones y bloqueo de secuencias | Pest contra MySQL real (contenedor), excepciones esperadas | rutas críticas |

**Regla de oro**: la base de tests de dinero y estados corre **contra MySQL 8.4 real** en contenedor, nunca SQLite: DECIMAL, CHECK y bloqueo pesimista se comportan distinto entre motores y esta es la clase de bug que solo aparece en producción.

### 12.2 Flujo de trabajo (Red-Green-Refactor)

1. **Red**: se escribe primero el test del comportamiento (case de cálculo, transición, constraint) y se verifica que falla por la razón esperada.
2. **Green**: implementación mínima (Action, Strategy, migración) hasta que el test pasa.
3. **Refactor**: extracción de duplicados, afinado de tipos; la suite garantiza equivalencia.

Los datasets tabulados de cálculo provienen de casos reales acordados con el área funcional (preguntas abiertas P-01..P-03 del documento de requisitos); cada fila es un criterio de aceptación ejecutable de RF-CAL-*.

### 12.3 Ejemplo de test de dominio (extracto ilustrativo)

```php
<?php

use App\Modules\PensionCalculation\Domain\PensionCalculator;
use App\Modules\PensionCalculation\Application\DTO\CalculationInput;

dataset('cuantias', [
    'general 30 años, 60% + 2% por excedente' => [
        new CalculationInput(/* salarioPromedio: 5000.00, aniosServicio: 30, config: base 60, tope 90, incremento 2, minimo 25 */),
        6500.00,
    ],
    'tope aplicado en 40 años' => [
        new CalculationInput(/* ... promedio 5000.00, 40 años, mismos parámetros */),
        4500.00, // 5000 × 90 % tope
    ],
]);

it('calcula la cuantía conforme a la configuración', function (CalculationInput $input, float $expected) {
    expect((new PensionCalculator())->calculate($input)->amount->toScalar())
        ->toBe($expected);
})->with('cuantias');
```

### 12.4 Dobles de prueba y determinismo

`ClockInterface` y `SequenceGeneratorInterface` permiten fijar fechas y números en tests de flujo completo (determinismo de auditoría). Los fakes de repositorio solo se usan en tests unitarios de application; los feature siempre usan MySQL real. Infection (mutación) se ejecuta semanalmente sobre el paquete de cálculo como termómetro de calidad de la suite.

### 12.5 Integración continua

Pipeline (por cada PR): Pint → PHPStan 8 → deptrac (reglas de módulos) → tests unitarios → tests feature+BD → cobertura (umbrales de 12.1) → build de imagen Docker. La rama `main` despliega a staging; producción requiere tag y aprobación manual (cumple RNF-007).

## 13. Gobernanza del código

Conventional Commits; PRs revisados por un par (CODEOWNER por módulo); ramas efímeras `feat/SGP-…`; CHANGELOG por release; documentación viva en los tres documentos del proyecto, versionados en el mismo repositorio que el código. Rector en modo conservador para modernizaciones de sintaxis planificadas, nunca en PRs funcionales.

## 14. Entornos y despliegue

- **docker-compose** con servicios `app` (PHP-FPM), `web` (Nginx), `db` (MySQL 8.4), `queue` y `scheduler` (cola para exportaciones y nómina); volúmenes persistentes para BD y respaldos.
- **Entornos**: `local` (compose + Xdebug), `staging` (réplica de prod con datos anonimizados), `production` (on-premise, sin egress a internet). `APP_ENV`/`APP_DEBUG` endurecidos; secretos fuera del repositorio (`.env` gestionado por infraestructura).
- **Respaldos** (RNF-009): dump lógico diario + binlog continuo, retención 30 días, simulacro mensual de restauración documentado.
- **Observabilidad básica**: logs estructurados JSON con `request_id` correlacionable con bitácora, healthcheck `/up`, y sondeo de lentitud de consultas (`slow query log`) alimentando el backlog de RNF-002.

## 15. Plan de desarrollo

### 15.1 Enfoque

Desarrollo incremental por fases verticales: cada fase entrega valor verificable en staging con su suite de pruebas. Sprints de 2 semanas; la estimación total (13 sprints ≈ 26 semanas) incluye un colchón implícito del ~15 % por imprevistos y validaciones funcionales. Equipo sugerido: 1 tech lead/arquitecto (50 %), 2-3 desarrolladores backend, 1 QA, y un analista funcional del Ministerio como validador (P-01..P-06).

| Fase | Sprints | Semanas | Contenido | Definition of Done |
|---|---|---|---|---|
| 0. Arranque | 1 | 2 | Repositorio, Docker, CI/CD base, Laravel 12 bootstrap, convenciones, autenticación esqueleto | Pipeline verde desde commit 1; login demo en staging |
| 1. Fundamentos | 2 | 4 | Módulos Shared, Catalogs, Settings, People: migraciones, seeders Cuba, CRUD API, auditoría base, RBAC básico | CRUD de catálogos y personas en staging con tests; seeders idempotentes |
| 2. Estructura organizacional | 1 | 2 | Organizations y LegalBasis: entidades, oficinas, jerarquías, firmas, bases legales | Jerarquías y coherencia geográfica probadas (RN-03/RN-04) |
| 3. Expedientes | 2 | 4 | PensionCases: expediente, salarios, servicios, ciclos, máquina de estados, historial, búsquedas | Flujo submitted→rejected/approved (sin pensionado) con matriz de transiciones 100 % testeada |
| 4. Motor de cálculo | 2 | 4 | PensionCalculation: elegibilidad, cómputo de servicio, promedio, estrategias por régimen, simulación, persistencia congelada | Datasets de cálculo aprobados por el analista; trazabilidad RF-CAL-008 |
| 5. Pensionados y pagos | 2 | 4 | Pensioners y Payments: alta atómica, ciclo de vida, controles bancarios, secuencias, export nómina | Aprobación end-to-end con control bancario; concurrencia de secuencias testeada |
| 6. Reportes, API completa y seguridad fina | 2 | 4 | Reporting, exportaciones, OpenAPI, hardening, políticas y bloqueos, ámbito territorial | Reportes con volúmenes objetivo ≤ RNF-002; revisión de seguridad interna |
| 7. Estabilización y UAT | 1 | 2 | Pruebas de carga, corrección de hallazgos, manuales de operación, plan de despliegue | Acta UAT firmada; RNF verificados; go-live aprobado |

### 15.2 Estrategia TDD por fase

- **Fases 0-1**: tests de infraestructura (migraciones aplican, seeders idempotentes, constraints únicas); foundation del CI.
- **Fase 2**: tests de reglas (aciclicidad, coherencia geográfica) como unitarios de dominio antes de tocar controllers.
- **Fase 3**: la matriz de transiciones se codifica primero como dataset de Pest; el enum y la máquina de estados nacen de él.
- **Fase 4**: el corazón TDD del proyecto: cada fila de dataset de cálculo es un test; las estrategias comparten test de contrato (Liskov).
- **Fase 5**: feature tests transaccionales (aprobación atómica) y tests de concurrencia real de secuencias (paralelo con procesos).
- **Fases 6-7**: tests de permisos por rol (matriz completa), de rendimiento y de exportación.

### 15.3 Riesgos y mitigaciones

| Riesgo | Prob. | Impacto | Mitigación |
|---|---|---|---|
| Ambigüedad de reglas de cálculo (P-01..P-03) | Alta | Alto | Parametrización + validación temprana con datasets firmados por el analista en la fase 4 |
| Cambios de catálogos oficiales (P-06) | Media | Medio | Seeders versionables; sin datos quemados en código |
| Contención de secuencia de control bancario | Media | Alto | Bloqueo pesimista + tests de concurrencia real + monitoreo de tiempo de espera |
| Cuello de botella de reportes con datos históricos | Media | Medio | Índices compuestos por diseño, paginación estricta, reportes pesados a cola |
| Rotación del equipo | Media | Medio | Documentación viva + módulos aislados + conventional commits |
| Retraso de validación funcional | Media | Alto | Analista asignado desde el día 1; demos de fin de sprint con checklist de requisitos |

## 16. Registro de decisiones (ADR resumidos)

| ADR | Decisión | Alternativa descartada | Motivo clave |
|---|---|---|---|
| ADR-01 | Monolito modular | Microservicios | Atomicidad transaccional, equipo pequeño, dominio acotado (sección 3.1) |
| ADR-02 | MySQL 8.4 LTS | Canal Innovation 9.x | Soporte extendido para sistema estatal; features necesarias disponibles |
| ADR-03 | Naming inglés + glosario | Español en código | Convención estándar del equipo; glosario ES↔EN mantiene fidelidad del dominio |
| ADR-04 | Pest 3 | PHPUnit puro | Datasets declarativos para cálculo; sintaxis homogénea |
| ADR-05 | spatie permission + activitylog | RBAC/bitácora a medida | Madurez y mantenimiento; extensibles con observers propios |
| ADR-06 | DECIMAL(12,2) para dinero | FLOAT/DOUBLE | Exactitud financiera; RNF-008 |
| ADR-07 | Transiciones como subrecurso POST | PATCH de estado | Deja explícito el evento y su evidencia (nota, base legal) |
| ADR-08 | Tests de BD contra MySQL real | SQLite en memoria | Divergencias de CHECK/DECIMAL/locking; detectar en CI, no en producción |
| ADR-09 | Configuración versionada | Singleton mutable | Cambios legales sin perder reproducibilidad histórica (RN-007) |
| ADR-10 | deptrac en CI | Convención informal | Las fronteras de módulo que no se verifican se erosionan |
| ADR-11 | Service + Repository materializado en código y verificado con tests de arquitectura | Controllers con lógica y Eloquent embebidos (como quedó la Fase 0) | La separación documentada que no se verifica mecánicamente se erosiona; lección de la revisión de código de Fase 0 |
| ADR-12 | Contracts también para los servicios: Presentation consume Application solo vía interfaces | Inyectar la clase de servicio concreta en el controller | DIP completo en la frontera HTTP: controllers testeables con stubs del caso de uso y decoradores cableables sin tocar código HTTP; verificado por las reglas R5/R6 del LayeringTest |
| ADR-13 | Documentación OpenAPI generada desde el código (atributos en Presentation + l5-swagger) | Spec YAML/JSON mantenido a mano o externo al repo | El spec manual se desincroniza de las rutas; el generado viaja con cada PR y el test de contrato rompe el build si falta un endpoint |
| ADR-14 | Campos de auditoría con estampado automático: observer genérico en Shared + puerto `CurrentUserProviderInterface` (impl en Security) | Columnas sueltas rellenadas a mano en cada service | Trazabilidad uniforme (RF-AUD-*) desde la primera tabla (`users`): el actor se resuelve por DIP sin facades en Application y la plantilla queda lista para Fase 1+ con una línea de registro por módulo |
| ADR-15 | Catálogos uniformes como recurso genérico `/api/v1/catalogs/{type}` dirigido por un registry de definiciones; municipios y agencias con servicios dedicados; coherencia RN-04 por FK compuesta en BD | Un controller+servicio+repositorio por catálogo (≈90 clases repetitivas) o endpoints por tabla | El registry es fuente única para validación, serialización y observadores: agregar un catálogo es una entrada de datos, no código nuevo; 18 tablas comparten 6 piezas de contratos |
| ADR-16 | Vigencia implícita de la configuración: `UNIQUE(effective_from)` en BD + resolver de dominio puro; versiones inmutables sin update y borrado solo de vigencias futuras; `effective_to` derivado en lectura | `effective_from`/`effective_to` almacenados con solapamiento controlado solo en aplicación (MySQL carece de exclusion constraints) | Con la regla «mayor `effective_from` ≤ fecha», fechas distintas particionan el tiempo: RN-007 queda garantizada por constraint (RN-008) y la semántica vive en un único resolver puro testeado con datasets |

## 17. Control de versiones del documento

| Versión | Fecha | Cambios | Autor |
|---|---|---|---|
| 1.0 | 2026-09-22 | Versión inicial: arquitectura, TDD y plan de fases | Arquitectura Backend |
| 1.1 | 2026-09-26 | ADR-11: patrón Service + Repository materializado (Security como plantilla canónica) + enforcement con tests de arquitectura | Arq. Backend |
| 1.2 | 2026-09-26 | ADR-12: contracts para las clases de servicio (`AuthServiceInterface` como plantilla) + reglas R5/R6 en LayeringTest | Arq. Backend |
| 1.3 | 2026-09-26 | ADR-13: documentación interactiva de la API con OpenAPI/Swagger (spec desde atributos + test de contrato ApiDocsTest) | Arq. Backend |
| 1.4 | 2026-09-26 | ADR-14: campos de auditoría en `users` (`created_by`/`updated_by` FK autoreferencial + `deleted_at`) con estampado automático (`AuditableObserver` en Shared + `CurrentUserProviderInterface`); entrada `users` actualizada en el Modelo de datos | Arq. Backend |
| 1.5 | 2026-09-26 | ADR-15: catálogos de la Fase 1 (18 tablas, CRUD `/api/v1/catalogs/{type}` genérico + `/municipalities` + `/agencies`, seeders Cuba 15/168 idempotentes, FK compuesta RN-04); tabla de endpoints actualizada | Arq. Backend |
| 1.6 | 2026-09-27 | ADR-16: configuración general versionada RN-007 (módulo Settings: tabla `general_settings` con `effective_from` UNIQUE + CHECK `max ≥ base`, resolver de dominio puro con datasets, `/api/v1/general-settings` sin update por inmutabilidad, `/current?at=…` resuelve la vigencia, borrado solo de vigencias futuras 409); entrada `general_settings` actualizada en el Modelo de datos | Arq. Backend |

