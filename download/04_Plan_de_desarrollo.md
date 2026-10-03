# Plan de Desarrollo por Fases — Sistema de Gestión de Pensionados (SGP)

| Campo | Valor |
|---|---|
| Proyecto | Sistema de Gestión de Pensionados (SGP) |
| Cliente | Ministerio de Trabajo (Cuba) |
| Documento | Plan de Desarrollo por Fases — Implementación del Backend |
| Versión | 1.2 |
| Fecha | 2026-09-26 |
| Estado | Borrador para aprobación del equipo |
| Documentos relacionados | `Requisitos funcionales.md` (RF/RNF/RN) · `Diseño de arquitectura.md` (módulos, TDD, ADR) · `Modelo de datos.md` (35 tablas, migraciones) |
| Alcance | Implementación completa del backend Laravel + MySQL hasta go-live |

---

## 1. Propósito y uso del plan

Este documento expande la sección 15 de `Diseño de arquitectura.md` en un **plan de ejecución operativo**: define, fase por fase y sprint por sprint, qué se construye, en qué orden, con qué dependencias, qué entregables se verifican y qué criterios objetivos permiten declarar cada etapa como terminada. No sustituye a los requisitos ni a la arquitectura: es el puente entre ambos y el código. Cualquier desviación detectada durante la ejecución se registra primero aquí (sección 11, gestión de cambios) y luego se refleja en los documentos fuente, para que la documentación viva nunca diverja del sistema real.

El plan está diseñado para **implementación incremental vertical**: cada fase entrega software funcionando y verificable en staging, nunca capas horizontales inconexas (p. ej., "todas las migraciones primero"). Este enfoque reduce el riesgo de integración tardía, permite validación funcional temprana con el analista del Ministerio y genera demos de fin de sprint con valor real desde el sprint 2. La estimación total es de **13 sprints de 2 semanas (26 semanas calendario)**, con un colchón implícito del ~15 % ya absorbido en la duración de cada fase.

### 1.1 Reglas de paso entre fases (gates)

Cada fase termina en un **gate de salida**: una lista de condiciones objetivas y verificables. Una fase no se considera cerrada —ni la siguiente puede iniciar su diseño detallado— hasta que todas las condiciones del gate se cumplen y quedan registradas. Si un gate no se supera, se activa el protocolo de la sección 11.3 (bloqueo y re-planificación), no se "arrastra deuda" silenciosamente. Las decisiones de re-planificación que afecten alcance, fechas o coste se documentan como entradas en la sección 11.2 y, si alteran decisiones arquitectónicas, generan un ADR nuevo en `Diseño de arquitectura.md`.

### 1.2 Convenciones de este documento

- **RF-XXX-NNN**, **RNF-NNN**, **RN-NNN** y **P-NN** referencian los artefactos de `Requisitos funcionales.md`.
- Cada fase usa una plantilla fija: objetivo · precondiciones (gate de entrada) · alcance funcional · tareas por sprint · entregables · Definition of Done (DoD) · gate de salida · riesgos.
- Las prioridades heredadas de requisitos son **M** (Must, bloqueante de go-live), **S** (Should, importante pero aplazable) y **C** (Could, opcional/propuesta).
- Las fechas del cronograma son **nominales**: se calculan desde una fecha de arranque hipotética (lunes 2026-10-05) y se recalculan al aprobarse el kickoff real.

## 2. Lineamientos transversales de ejecución

Estas reglas aplican a todas las fases y son condiciones permanentes del proyecto; su incumplimiento bloquea el merge del PR correspondiente, con independencia de la fase.

**Stack y estructura.** Laravel 12 sobre PHP 8.3 y MySQL 8.4 LTS, monolito modular con 12 módulos y capas Domain/Application/Infrastructure/Presentation por módulo (secciones 3-5 de `Diseño de arquitectura.md`). Las fronteras entre módulos se verifican con **deptrac** en cada PR (ADR-10): una dependencia no autorizada rompe el pipeline igual que un test en rojo. El dinero es siempre `DECIMAL(12,2)` (RN-005, RNF-008) y toda clave natural única se garantiza con constraint de base de datos (RN-008).

**Metodología de desarrollo.** TDD estricto conforme a la sección 12 de la arquitectura: Red-Green-Refactor en dominio y aplicación; datasets declarativos de Pest 3 para reglas tabulares (transiciones de estado, cálculo de pensiones); tests feature siempre contra MySQL 8.4 real, nunca SQLite (ADR-08). Cobertura mínima: ≥ 90 % en dominio/aplicación y ≥ 80 % global, verificada en CI con umbrales fallidos que rompen el build (RNF-007). PHPStan nivel 8 sin errores y Pint como formateador único.

**Control de versiones.** Conventional Commits (`feat:`, `fix:`, `refactor:`, `docs:`, `test:`); ramas efímeras `feat/SGP-<issue>` con vida máxima de 3 días; merge a `main` solo con PR aprobado por el CODEOWNER del módulo afectado y pipeline verde completo (Pint → PHPStan → deptrac → unitarios → feature+BD → cobertura → build Docker). `main` despliega automáticamente a staging; producción requiere tag anotado y aprobación manual registrada.

**Roles del equipo (referencia para estimación).** 1 tech lead/arquitecto al 50 % de dedicación, 2-3 desarrolladores backend a tiempo completo, 1 QA con foco en datasets y pruebas de carga, y 1 analista funcional del Ministerio como validador (imprescindible en fases 1, 4 y 7). La ausencia del analista en sus semanas críticas es un riesgo gestionado (sección 11.4).

**Calidad funcional continua.** Al cierre de cada sprint hay demo en staging contra datos sembrados de Cuba y revisión de la checklist de RFs tocados en el sprint. Ningún RF se marca como cubierto en la matriz de trazabilidad (sección 12) sin sus tests correspondientes en verde y validación funcional en la demo donde aplique.

## 3. Visión general del plan

| Fase | Sprints | Semanas | Módulos Laravel | RF principales | Resultado verificable |
|---|---|---|---|---|---|
| 0. Arranque | 1 (S1) | 2 | Shared (esqueleto) | RF-SEG-001 parcial | Pipeline CI verde; login demo en staging |
| 1. Fundamentos | 2 (S2-S3) | 4 | Catalogs, Settings, People, Security | RF-CAT, RF-PER, RF-SEG-002/003/004, RF-AUD-001/003/004 | CRUD de catálogos y personas en staging; seeders idempotentes |
| 2. Estructura organizacional | 1 (S4) | 2 | Organizations, LegalBasis | RF-ENT, RF-LEG | Jerarquías y coherencia geográfica probadas (RN-03/RN-04) |
| 3. Expedientes | 2 (S5-S6) | 4 | PensionCases | RF-EXP-001..011, RF-AUD-002 | Flujo completo submitted→approved/rejected sin pensionado |
| 4. Motor de cálculo | 2 (S7-S8) | 4 | PensionCalculation | RF-CAL-001..008 | Cálculo end-to-end con datasets firmados por el analista |
| 5. Pensionados y pagos | 2 (S9-S10) | 4 | Pensioners, Payments | RF-PEN, RF-PAG | Aprobación end-to-end con control bancario y nómina |
| 6. Reportes, API y seguridad fina | 2 (S11-S12) | 4 | Reporting, API pública | RF-REP, RF-API | Reportes con volúmenes objetivo ≤ RNF-002; hardening |
| 7. Estabilización y UAT | 1 (S13) | 2 | transversal | RNF completos | Acta UAT firmada; go-live aprobado |

```mermaid
gantt
    title Cronograma nominal SGP — 13 sprints / 26 semanas (arranque hipotético 2026-10-05)
    dateFormat YYYY-MM-DD
    axisFormat %d %b
    section Desarrollo
    F0 Arranque              :f0, 2026-10-05, 14d
    F1 Fundamentos           :f1, after f0, 28d
    F2 Estructura organizacional :f2, after f1, 14d
    F3 Expedientes           :f3, after f2, 28d
    F4 Motor de calculo      :f4, after f3, 28d
    F5 Pensionados y pagos   :f5, after f4, 28d
    F6 Reportes API y seguridad :f6, after f5, 28d
    section Cierre
    F7 Estabilizacion y UAT  :crit, f7, after f6, 14d
```

El paso entre fases está gobernado por gates; el siguiente diagrama resume la cadena de decisiones y las salidas de contingencia cuando un gate no se supera:

```mermaid
flowchart TD
    F0["Fase 0 · Arranque<br/>S1"] --> G0{"Gate 0<br/>CI verde + login demo"}
    G0 -- no --> R0["Ajustar infra.<br/>1 semana máx."] --> F0
    G0 -- si --> F1["Fase 1 · Fundamentos<br/>S2-S3"]
    F1 --> G1{"Gate 1<br/>CRUD + RBAC + auditoría"}
    G1 -- no --> R1["Deuda acotada con plan<br/>de pago en S4"] --> F1
    G1 -- si --> F2["Fase 2 · Estructura<br/>S4"]
    F2 --> G2{"Gate 2<br/>RN-03 y RN-04 en verde"}
    G2 -- si --> F3["Fase 3 · Expedientes<br/>S5-S6"]
    F3 --> G3{"Gate 3<br/>matriz de transiciones<br/>100 por ciento testeada"}
    G3 -- si --> F4["Fase 4 · Motor de cálculo<br/>S7-S8"]
    F4 --> G4{"Gate 4<br/>datasets firmados<br/>por el analista"}
    G4 -- pendiente --> W4["Sprint de espera:<br/>parametrizacion y<br/>simulacion mientras"] --> F4
    G4 -- si --> F5["Fase 5 · Pensionados y pagos<br/>S9-S10"]
    F5 --> G5{"Gate 5<br/>aprobacion end-to-end<br/>+ concurrencia"}
    G5 -- si --> F6["Fase 6 · Reportes, API<br/>y seguridad fina<br/>S11-S12"]
    F6 --> G6{"Gate 6<br/>RNF-001/002/004<br/>verificados"}
    G6 -- si --> F7["Fase 7 · Estabilización y UAT<br/>S13"]
    F7 --> GO{"Go/No-Go<br/>acta UAT"}
    GO -- no --> FIX["Sprint hotfix<br/>re-planificado"] --> F7
    GO -- si --> PROD["Producción<br/>on-premise"]
```

**Principio de contingencia clave (Gate 4):** si el analista del Ministerio no ha firmado los datasets de cálculo al cierre de la fase 4, el equipo NO espera pasivamente: trabaja la parametrización, la simulación (RF-CAL-006) y adelanta tareas de la fase 5 que no dependan de la cuantía final (control bancario, secuencias). El gate se re-evalúa semanalmente hasta cerrarse.

---

## 4. Fase 0 — Arranque e infraestructura

**Sprint 1 · Semanas 1-2 · Módulos: Shared (esqueleto), infraestructura CI/CD**

### 4.1 Objetivo

Levantar los cimientos técnicos que harán verificable todo lo demás: repositorio con reglas de contribución, entorno Docker reproducible, pipeline de CI completo y el esqueleto modular de Laravel 12 con autenticación funcional. Al terminar la fase, cualquier desarrollador nuevo debe poder clonar el repositorio, ejecutar `docker compose up` y tener el sistema corriendo con login en menos de 30 minutos.

### 4.2 Precondiciones (gate de entrada)

- [x] Repositorio GitHub creado con acceso para todo el equipo (ya existente: `inass_siss`).
- [ ] Analista funcional del Ministerio designado y con disponibilidad agendada para las demos.
- [ ] Decisión de infraestructura de staging confirmada (VM accesible o servidor local del Ministerio).

### 4.3 Tareas del sprint

**Infraestructura y automatización (S1.1-S1.2)**
- [ ] `docker-compose.yml` con los servicios de la sección 14 de arquitectura: `app` (PHP 8.3-FPM), `web` (Nginx), `db` (MySQL 8.4 con volumen persistente), `queue` y `scheduler`.
- [ ] `Makefile` o scripts `composer` para comandos frecuentes: `make up`, `make test`, `make seed`, `make stan`.
- [ ] Imagen Docker de la aplicación con build reproducible en CI (multi-stage, sin dev-deps en runtime).
- [ ] Entorno `staging` desplegado con deploy automático desde `main` y healthcheck `/up` sondeado.

**Calidad desde el commit 1 (S1.3)**
- [ ] Pipeline GitHub Actions por PR: Pint → PHPStan nivel 8 → deptrac → Pest (unitarios + feature contra servicio MySQL 8.4 efímero) → umbrales de cobertura (90/80) → build de imagen.
- [ ] Configuración inicial de `deptrac.yaml` con las 12 módulos y sus dependencias autorizadas (tabla de la sección 4 de arquitectura).
- [ ] Protección de rama `main`: PR obligatorio, CODEOWNERS por módulo, squash-merge, verificación de estado requerida.
- [ ] Plantillas de PR e issue, `CHANGELOG.md` inicial, `CONTRIBUTING.md` con las convenciones del proyecto.

**Esqueleto de aplicación (S1.4-S1.5)**
- [ ] Bootstrap Laravel 12 con estructura `app/Modules/<Módulo>/{Domain,Application,Infrastructure,Presentation,Tests}` y `Providers/` de registro por módulo.
- [ ] Módulo `Shared` con los contratos núcleo: `ClockInterface`, `SequenceGeneratorInterface`, `TransactionManager`.
- [ ] Value objects base con sus tests: `Money` (inmutabilidad, redondeo bancario, rechazo de float), `CubanIdentityNumber` (validación de RN-001 corregida por ADR-30: 11 dígitos + mes/día + sexo por paridad del dígito 10), `Period` (RN-006: fin ≥ inicio).
- [ ] Login funcional con credenciales de demo (RF-SEG-001 en modo esqueleto; el hardening llega en fase 6), rate limiting básico de autenticación activado.
- [ ] Seeder de usuario administrador inicial idempotente.

### 4.4 Entregables

Pipeline CI/CD operativo; entorno local reproducible vía Docker; esqueleto modular con `Shared` completo; login demo en staging; documentos de gobernanza del repositorio (CONTRIBUTING, CODEOWNERS, CHANGELOG).

### 4.5 Definition of Done

- [ ] Un PR de prueba ("prueba del pipeline") recorre el flujo completo en verde: Pint, PHPStan 8, deptrac, Pest contra MySQL real, cobertura y build.
- [ ] `docker compose up` + `make seed` deja el sistema con login funcional en local, verificado por 2 desarrolladores distintos.
- [ ] La cobertura de `Shared` es ≥ 95 % (es el módulo más reutilizado del sistema).
- [ ] deptrac falla si se crea una dependencia no autorizada (probado deliberadamente en una rama de prueba).
- [ ] Staging despliega automáticamente desde `main` y `/up` responde 200.

### 4.6 Gate de salida

1. Pipeline verde desde el primer PR funcional.
2. Login demo operativo en staging.
3. Estructura modular y contratos `Shared` aprobados por el tech lead.

### 4.7 Riesgos de la fase

| Riesgo | Mitigación |
|---|---|
| Staging sin VM del Ministerio disponible | Arrancar staging en cloud privado del integrador; migración de entorno documentada y ensayada |
| Fricción con MySQL 8.4 en CI (imágenes, collation) | Contenedor de CI fijado a la misma versión menor que producción; prueba hecha en el propio sprint |

---

## 5. Fase 1 — Fundamentos: catálogos, configuración y personas

**Sprints 2-3 · Semanas 3-6 · Módulos: Catalogs, Settings, People, Security**

### 5.1 Objetivo

Construir la base de datos maestros sobre la que descansan expedientes y cálculo: catálogos simples y geográficos, configuración general versionada, secuencias de numeración, personas con su ciclo de vida, y el andamiaje transversal de seguridad (RBAC) y auditoría. Es la fase con más "madera oculta" del proyecto: todo lo que aquí quede flojo se pagará con intereses en las fases 3-5.

### 5.2 Precondiciones (gate de entrada)

- [ ] Gate 0 superado y registrado.
- [ ] Listas oficiales preliminares de catálogos entregadas por el analista (razas, niveles educacionales, categorías ocupacionales, tipos de pensión, regímenes). Si llegan incompletas: se siembran las conocidas y se registran huecos como `P-06` (los seeders son versionables y ajustables sin migraciones).

### 5.3 Alcance funcional

| Módulo | Requisitos | Notas |
|---|---|---|
| Catalogs | RF-CAT-001..004 (M), RF-CAT-006 (S) | CRUD de catálogos, municipios, agencias; carga inicial |
| Settings | RF-CAT-005 (M) + soporte a RF-PAG-006 | Configuración versionada `effective_from` + `numbering_sequences` |
| People | RF-PER-001..004 (M), RF-PER-005 (S) | Ciclo de vida de personas, duplicados |
| Security | RF-SEG-002, RF-SEG-003, RF-SEG-004 (M) | RBAC, restricción por estado, usuario↔persona |
| Auditoría | RF-AUD-001, RF-AUD-003, RF-AUD-004 (M) | Bitácora, consulta, borrado lógico |

### 5.4 Tareas por sprint

**Sprint 2 — Catálogos y configuración (S2.1-S2.5)**
- [ ] Migraciones de catálogos simples y geográficos conforme al modelo de datos (provincias, municipios con unicidad compuesta código+provincia, agencias, organismos, clasificadores) con constraints únicos de RN-008.
- [ ] `CubaGeographySeeder` idempotente: 15 provincias y 168 municipios con verificación de conteo en test.
- [ ] CRUD API REST de catálogos: endpoints resource con validación de unicidad devuelta como error 422 semántico (RF-API-002 se aplica desde ahora como convención transversal).
- [ ] Tests feature de CRUD + tests de infraestructura: migraciones aplican limpias en BD vacía y seeders son re-ejecutables sin duplicar.
- [ ] Configuración general versionada: tabla `general_settings` con `effective_from`/`effective_to`, acción de dominio que resuelve la vigente a una fecha dada, constraint de no solapamiento de vigencias (RN-007). TDD: primero el dataset de solapamientos y vigencia, después la implementación.
- [ ] `numbering_sequences` + implementación MySQL de `SequenceGeneratorInterface` con bloqueo pesimista (`SELECT ... FOR UPDATE` dentro de transacción) y test de concurrencia real con 8 procesos paralelos sin huecos ni duplicados (RN-009). Este es un entregable de infraestructura que se consumirá en fases 3 y 5.

**Sprint 3 — Personas y seguridad (S3.1-S3.5)**
- [x] Migración y dominio de `people`: alta, edición con auditoría de valores previos (RF-PER-002), registro de fallecimiento con fecha (RF-PER-003) y su efecto en búsquedas. ✅ 2026-09-27 (ADR-20, PR People)
- [x] Validador de identidad cubano aplicado en dominio y como regla de Request; unicidad de identidad garantizada por constraint de BD (RN-001). ✅ 2026-09-27 (`CubanIdentityNumber` como regla + UNIQUE contra activas y desactivadas); validación corregida 2026-09-30 (ADR-30: 11 dígitos + mes 01-12/día 01-31 + sexo por paridad del dígito 10 contrastado al alta y en PATCH — eliminados prefijo siglo/sexo y fecha de calendario)
- [x] Búsqueda de personas por identidad, nombre aproximado y filtros básicos, paginada (RF-PER-004); control de duplicados al alta con aviso confirmable (RF-PER-005). ✅ 2026-09-27 (`DuplicatePolicy`: 409 con persona registrada / homónimos confirmables)
- [x] Usuarios + RBAC con spatie/laravel-permission (ADR-05): roles y permisos iniciales del análisis (sección 2.2 de requisitos), matriz rol-permiso como dataset de Pest (anticipa la matriz completa de la fase 6). ✅ 2026-09-27 (ADR-18, PR #9)
- [x] Asociación usuario↔persona con unicidad (RF-SEG-004) y restricción de acciones por estado de persona (RF-SEG-003: p. ej., persona fallecida no puede iniciar expediente). ✅ 2026-09-28 (ADR-21, PR #13: `users.person_id` UNIQUE + FK RESTRICT con reserva sobre cuentas desactivadas, link/unlink idempotentes y auditados con 409 conversacional, `/auth/me` con roles/permisos y resumen `LinkedPerson` de la persona vinculada; `PeopleService::canStartNewProcess` materializa la regla de estado —viva y activa— que PensionCases (F3) consumirá al crear expedientes, la matriz completa de la sección 2.4 llega con la Fase 6)
- [x] Auditoría base con spatie/laravel-activitylog + observers propios: bitácora de toda escritura con valores previos (RF-AUD-001, RNF-005), consulta filtrable de bitácoras para roles autorizados (RF-AUD-003), borrado lógico con restauración auditada (RF-AUD-004). ✅ 2026-09-27 (ADR-19, PR #10)
- [x] Gestión de usuarios completa (RF-SEG-001, parte de administración): CRUD de cuentas con roles, bloqueo por N intentos fallidos con desbloqueo del Administrador, política de contraseñas (longitud, complejidad, caducidad opcional) con renovación propia y restablecimiento administrativo. ✅ 2026-09-28 (ADR-24, PR user-management — ejecutada antes de la Fase 3 por decisión del usuario: el personal técnico necesita provisionar operadores/especialistas antes de capturar expedientes; `LockoutPolicy`/`PasswordPolicy` como dominio puro desde `config/security.php`; email reservado por cuentas desactivadas; guardas de auto-desactivación y último administrador; secretos redactados en bitácora; matriz a 16 permisos con `users.view` para auditor)

> **Orden de ejecución ajustado (2026-09-27, sin cambio de alcance)**: RBAC y bitácora se materializan ANTES del módulo People, porque RF-PER-002 (M) exige valores previos en bitácora para toda edición de personas y la bitácora transversal también cubre la escritura de catálogos que el DoD de la fase exige; así el slice de People cierra todos sus requisitos obligatorios en su propio PR. Secuencia real: PR RBAC (S3.4) → PR bitácora (RF-AUD-001/003/004) → PR People (S3.1-S3.3) → PR usuario↔persona y restricción por estado (S3.5).

### 5.5 Entregables

API de catálogos con datos oficiales sembrados; configuración versionada con resolución de vigencia; generador de secuencias concurrencia-seguro; ciclo de vida de personas completo; RBAC con matriz testeada; bitácora de auditoría transversal operativa.

### 5.6 Definition of Done

- [ ] CRUD de catálogos y personas demostrable en staging con datos de Cuba.
- [ ] Seeders idempotentes probados (re-ejecución no duplica ni falla).
- [ ] Test de concurrencia de secuencias en verde con evidencia adjunta al PR.
- [ ] Toda escritura de personas y catálogos genera bitácora con autor, fecha y valores previos (verificado con feature test).
- [ ] Cobertura ≥ 90 % en dominio de People/Settings; ≥ 80 % global.
- [ ] Demo de fin de fase con el analista: catálogos revisados y huecos de `P-06` registrados.

### 5.7 Gate de salida

1. CRUD de catálogos y personas en staging con suite verde.
2. RBAC y auditoría base operativos.
3. Configuración versionada y secuencias disponibles para consumo de fases posteriores.

### 5.8 Riesgos de la fase

| Riesgo | Mitigación |
|---|---|
| Listas de catálogos oficiales incompletas (P-06) | Seeders versionables; sembrar lo conocido; los vacíos no bloquean (P-06 documentado) |
| Complejidad latente de vigencias (solapamientos) | Regla resuelta por TDD antes de exponer API; constraint en BD como última línea |

---

## 6. Fase 2 — Estructura organizacional y base legal

**Sprint 4 · Semanas 7-8 · Módulos: Organizations, LegalBasis**

### 6.1 Objetivo

Modelar el mapa organizacional del Estado que interviene en cada expediente: entidades con jerarquías acíclicas, oficinas geográficamente coherentes, cargos y firmas autorizadas; y el corpus de base legal que respalda cada resolución de pensión.

### 6.2 Precondiciones (gate de entrada)

- [ ] Gate 1 superado y registrado.
- [ ] Estructura organizacional real (organismos, entidades, oficinas territoriales) disponible en formato consultable.

### 6.3 Alcance funcional

| Módulo | Requisitos | Notas |
|---|---|---|
| Organizations | RF-ENT-001..004 (M), RF-ENT-005 (S) | Entidades, oficinas, firmas, coherencia |
| LegalBasis | RF-LEG-001..003 (M), RF-LEG-004 (S) | Tipos, bases, vigencias |

### 6.4 Tareas del sprint

**Estructura organizacional (S4.1-S4.3)**
- [x] Migraciones y dominio de entidades y oficinas con jerarquías auto-referenciadas. *(ADR-22: migraciones `entities`/`offices` con FK compuesta RN-04 y claves naturales UNIQUE reservadas por soft delete)*
- [x] Estructura territorial de oficinas (corrección de usuario sobre RF-ENT-002): una sola nacional, una provincial por provincia, una municipal por provincia y municipio (entre activas); parent derivado del tipo con prerrequisitos de existencia del superior y nacional sembrada al arranque. *(ADR-31: `OfficeStructurePolicy` de dominio puro + puerto `findActiveOfType` en el repositorio — unicidad semántica porque MySQL no tiene índice parcial; 422 por campo ante contradicciones de parent y prerrequisitos; guard de hijas activas ante re-tipo/re-ubicación; `NationalOfficeSeeder` idempotente — regla 7 — y fumiga HTTP `scripts/smoke_office_structure.php` con 25 comprobaciones; 32 tests nuevos, suite 975/3100)*
- [x] Regla de aciclicidad como test de dominio puro (RN-03): dataset con árboles válidos e inválidos; el algoritmo de detección de ciclos se implementa en `Domain`, no en BD, y se ejecuta antes de persistir. *(ADR-22: `HierarchyPolicy::wouldCreateCycle` + HierarchyPolicyTest con 12 datasets: raíz, cadena, árbol ancho, re-enraizado válido, self, 2-ciclo, 3-ciclo, cadena de 6 niveles, padre desconocido, pureza)*
- [x] Coherencia geográfica (RN-04, RF-ENT-004): toda entidad/oficina con provincia exige municipio perteneciente; validación en dominio + constraint compuesto en BD. *(ADR-22: 422 semántico por campo contra el estado resultante + FK compuesta, backstop probado con QueryException)*
- [x] Cargos y firmas autorizadas por entidad (RF-ENT-003): unicidad activa de firma (persona+cargo+entidad), historial de revocación. *(ADR-22: terna UNIQUE cubriendo revocadas — la revocación es soft delete auditado que preserva el historial y mantiene la terna reservada; ventana RN-006 y estado derivado `active`/`future`/`expired`)*
- [x] Consulta de estructura (RF-ENT-005): árbol de jerarquía paginado y búsqueda por nombre/NIT. *(ADR-22: `GET /entities/tree` y `/offices/tree` con 5 niveles y corte anunciado `deeper`; búsqueda `q` sobre código/nombre/NIT/objeto social — la columna `name` VARCHAR(120) NOT NULL llegó con la corrección de usuario de Task 31: nombre denominativo obligatorio en el alta (422 sin él), devuelto en listado/detalle/árbol; el conteo de expedientes por oficina llegó con la F3 ya en main: ADR-28 — `cases_count`/`scope_cases_count` en árbol y detalle vía `HierarchyTotals` puro + puerto implementado por PensionCases)*

- [x] Nombre denominativo de la entidad y código universal de catálogos (corrección de usuario, Task 31): `entities.name` VARCHAR(120) obligatorio (migración `2026_10_01_100000`, backfill con el propio código en filas preexistentes, búsqueda del listado ampliada) y columna `code` VARCHAR(10) UNIQUE en los siete catálogos antes solo-nombre (migración `2026_10_01_110000`, códigos de referencia sembrados por el `CatalogsSeeder`) — todos los listados de catálogo devuelven el campo código. *(suite 1040/3442 contra MySQL real; fumiga HTTP `scripts/smoke_task31_catalogs_entity.php` con 25 comprobaciones)*
✅ 2026-10-02 AMPLIADA por la corrección de usuario de Task 38 (SGP-32): columnas propias de los catálogos de pensión servidas por la maquinaria genérica de `extraRules` — `pension_regimes.sector` INT NULL opcional (migración `2026_10_02_120100`) y `pension_types.deceased_person` TINYINT(1) NOT NULL DEFAULT 0 (migración `2026_10_02_120200`, con el default espejado en memoria para que el 201 proyecte false sin recarga) devueltos por TODOS los endpoints del recurso genérico, y el PATCH relajado a `sometimes` para las columnas propias — más el FIX del listado de entidades: `GET /entities` devuelve los DATOS del director general y el económico como proyecciones completas de Persona (`director`/`economic_director`, null sin directores, `PersonResource` reutilizado con carga anticipada en el WITH del repositorio); suite 1069/3635, fumiga de expedientes con 54 comprobaciones

**Base legal (S4.4-S4.5)**
- [x] Tipos de base legal (catálogo sembrado) y registro de bases legales con organismo emisor, número, fecha y texto referencia. *(tipos ya servidos por el catálogo genérico ADR-15 desde el Sprint 2; `legal_bases` con terna tipo-número-año única e inmutable, año derivado de la emisión H-11 — ADR-23)*
- [x] Control de vigencias legales (RF-LEG-003, RN-006): `effective_from` ≤ `effective_to`, resolución de bases vigentes a una fecha. *(RN-006 como orden `effective_date ≥ issue_date` y `derogation_date ≥ effective_date` según el modelo 5.6, validado contra el estado resultante + CHECKs de BD; vigencia derivada al leer `effective`/`derogated`/`future` con cortes inclusivos y filtro SQL `status=effective` como selector de vigentes; la advertencia de «forzar una derogada» aterriza con la transición de aprobación de F3)*
- [x] Consulta documental filtrable (RF-LEG-004). *(búsqueda por año, tipo, organismo emisor y texto del número/referencia, paginada)*

### 6.5 Entregables

API de entidades/oficinas/firmas con reglas estructurales inviolables; corpus de bases legales con control de vigencia; tests de aciclicidad y coherencia geográfica como activos permanentes de regresión.

### 6.6 Definition of Done

- [ ] Tests de dominio de aciclicidad (RN-03) y coherencia (RN-04) en verde con datasets documentados.
- [ ] Carga de estructura organizacional real demostrada en staging.
- [ ] Una base legal puede crearse, caducar y consultarse como vigente/no vigente según fecha de corte.
- [ ] deptrac sigue en verde: Organizations solo depende de Shared/Catalogs/People.

### 6.7 Gate de salida

1. Jerarquías y coherencia geográfica probadas (RN-03/RN-04).
2. Bases legales con vigencias operativas.

### 6.8 Riesgos de la fase

| Riesgo | Mitigación |
|---|---|
| Estructura org. real con duplicidades o datos sucios | Importación validada con reporte de rechazos; corrección con el analista, no en código |

---

## 7. Fase 3 — Expedientes y flujo de resolución

**Sprints 5-6 · Semanas 9-12 · Módulos: PensionCases**

### 7.1 Objetivo

Construir el corazón administrativo del sistema: el expediente de pensión con sus subregistros (salarios, servicios, ciclos de trabajo), su máquina de estados completa y su historial inmutable. Al cierre, un expediente debe poder nacer, completarse, revisarse, aprobarse o denegarse —sin generar aún pensionado— con evidencia auditable de cada transición.

### 7.2 Precondiciones (gate de entrada)

- [ ] Gate 2 superado y registrado.
- [ ] RF-EXP-001..011 revisados con el analista: campos del expediente y evidencias exigibles por transición (nota, base legal).

### 7.3 Alcance funcional

| Módulo | Requisitos | Notas |
|---|---|---|
| PensionCases | RF-EXP-001..008, RF-EXP-011 (M); RF-EXP-009 (M); RF-EXP-010 (S) | Expediente, subregistros, estados, historial, búsquedas |
| Auditoría | RF-AUD-002 (M) | Historial de estados como bitácora especializada |

### 7.4 Tareas por sprint

**Sprint 5 — Expediente y subregistros (S5.1-S5.5)**
- [x] Migraciones del bloque PensionCases conforme al modelo de datos: `pension_cases`, `salary_records`, `service_records` (con marcador coletilla), `work_cycles`, relación única expediente↔persona (corrección H-02). ✅ 2026-09-28 (migraciones 2026_09_28_150000..150003; la unicidad de «un expediente abierto por persona» es física: columna generada `open_case_key` NULL en estados terminales + UNIQUE — los casos resueltos no ocupan lugar)
- [x] Creación del expediente (RF-EXP-001): número generado vía `SequenceGeneratorInterface` (consumo del entregable de fase 1), persona viva, oficina y régimen válidos, constraint único de expediente abierto por persona. ✅ 2026-09-28 (número de la secuencia `pension_case` emitido tras toda validación y antes de la transacción de negocio — un 422 no quema nada, un fallo de insert sí (hueco RN-009 aceptado); elegibilidad por `PeopleService::canStartNewProcess` con 422 accionable fallecido/desactivado; sondas de oficina/entidad/catálogos activos; 409 con el expediente abierto) ✅ 2026-09-30 AMPLIADO por las reglas de usuario 0-5 (ADR-32/33): número compuesto PP-YYYY-CCCCC (provincia de la oficina registrante + año en curso + consecutivo ANUAL por scope `pension_case:{año}` con `nextForYear` — el rodaje anual nace en 1 solo), la oficina ASUMIDA del usuario que registra (prohibida en el POST por el puerto Shared `CurrentUserOfficeProviderInterface`, actor sin oficina 422), clasificación de pensión obligatoria (tipo/régimen), par de Ejército Rebelde coherente (CHECKs directo e inverso), serie salarial de máximo 15 filas vivas (`SalarySeries::MAX_RECORDS`) y conceptos de ingreso como subregistro (UNIQUE caso-concepto, tabla `income_concept_records`, anidados en la creación atómica + endpoints propios); el listado carga el promovente COMPLETO (`PersonResource` reutilizado) — suite 1023/3279, fumiga HTTP `smoke_case_registration.php` de 20 comprobaciones ✅ 2026-10-01 AMPLIADO por ADR-34 (corrección de usuario sobre la regla 2 del número): PPMMAACCCCC — once dígitos contiguos: provincia (2) y municipio (2) de la oficina registrante, últimos dos dígitos del año en curso (2) y consecutivo (5) por el trío año/provincia/municipio — emitido por `nextForTerritory` sobre el scope `pension_case:{año}:{provincia}:{municipio}` que nace en 1 a su primera emisión (el `SettingsSeeder` ya no pre-declara scopes de expediente); suite 1032/3299, fumiga HTTP actualizada ✅ 2026-10-01 AMPLIADO por la corrección de usuario de Task 34: persona por del expediente — `persona_por` VARCHAR(120) NULL (migración `2026_10_01_130000`), texto libre opcional de quién presenta o gestiona el caso (422 con 121 caracteres; omisión = NULL) recibido en el alta y devuelto en el 201/detalle/listado — y revisión de la búsqueda del listado de entidades: `q` cubre código, nombre, NIT y objeto social (descripción OA del endpoint corregida); suite 1049/3485, fumiga de expedientes ampliada a 26 comprobaciones ✅ 2026-10-01 REDEFINIDA por la corrección de usuario de Task 35: la persona por pasa de texto libre a REFERENCIA a una persona REGISTRADA — `persona_por_id` FK → `people` (migración `2026_10_01_140000`), sonda sobre la superficie ACTIVA (desconocida/desactivada = 422, omisión = NULL) y 201/detalle/listado devolviendo el id más la proyección completa bajo `persona_por` (misma forma que `applicant`); suite 1050/3495, fumiga de expedientes con la persona por en 4 comprobaciones ✅ 2026-10-02 RENOMBRADA a columnas inglesas por la corrección de usuario de Task 36 (SGP-30, patrón ADR-03 vinculante para todo el desarrollo): forma_declaracion → declaration_form y persona_por_id → filed_by_person_id (migraciones `2026_10_02_100000`/`100100` con CHECK y FK renombrados a inglés; wire, proyección `filed_by`, OA y fumiga actualizados; los valores Documental|Testifical no cambian); suite 1050/3495 ✅ 2026-10-02 AMPLIADA por la corrección de usuario de Task 37 (SGP-31): internacionalista del promovente (`internationalist` TINYINT(1) NOT NULL DEFAULT 0, booleana OBLIGATORIA en el wire — 422 si se omite, paralelo de `rebel_army_member`) y par de contacto (`phone` VARCHAR(30) / `popular_council` VARCHAR(120), opcionales con techo, omisión = NULL) recibidos en el alta y devueltos en 201/detalle/listado (migración `2026_10_02_110000`); suite 1062/3562, fumiga con 4 comprobaciones propias ✅ 2026-10-02 AMPLIADA por la corrección de usuario de Task 40 (SGP-34): ciclo de vida del agregado — PUT de edición con el PROMOVENTE INMUTABLE (todo campo de la esfera de la persona y los de ciclo de vida responden 422 prohibido; semántica PATCH sobre los campos propios con probes espejo del alta) y DELETE de eliminación LÓGICA solo en `submitted` (409 fuera, con el estado actual) con liberación de la reservación de un-abierto-por-persona — la migración `2026_10_02_130000` re-crea la columna generada `open_case_key` con la expresión que también anula la clave en filas soft-deleted (el trait SoftDeletes ya estaba en el modelo y `deleted_at` en la tabla desde el Sprint 5) —; suite 1108/3788, fumiga lifecycle propia (`smoke_case_lifecycle.php`) de 28 comprobaciones TODO OK ✅ 2026-10-03 AMPLIADA por la corrección de usuario de Task 41 (SGP-35): el GET del listado con ALCANCE TERRITORIAL — solo cargan los expedientes cuya oficina coincide con la del usuario autenticado, `office_id` prohibido en la query (422 conversacional) porque el controlador lo deriva del puerto Shared `CurrentUserOfficeProviderInterface` (el mismo seam de la regla 0 del alta) y el servicio responde una página VACÍA fail-closed sin criterio de oficina (actor sin oficina o caller que olvida el scope: jamás el directorio sin alcance), con los filtros restantes angulando DENTRO del scope — suite 1113/3829 contra MySQL real, Pint/PHPStan 8/deptrac en verde, fumiga de expedientes ampliada a 61 comprobaciones (query prohibida, reasignación provincial/municipal moviendo el scope de punta a punta, actor sin oficina con página vacía) TODO OK
 ✅ 2026-10-02 AMPLIADA por la corrección de usuario de Task 38 (SGP-32): fecha de desvinculación del promovente — `termination_date` DATE NULL (migración `2026_10_02_120000`), opcional en el wire con regla de forma Y-m-d única (422 con formato inválido; sin sonda semántica), omisión/null/'' persisten NULL y el 201, el detalle y el listado la devuelven; suite 1069/3635, fumiga con 3 comprobaciones propias
- [x] Subregistros con validaciones RN-005/RN-006 (importes `DECIMAL(12,2)`, periodos coherentes) y solapamiento de servicios detectado y advertido. ✅ 2026-09-28 (Money de Shared en todos los importes; techo del año = año actual+1 contra `ClockInterface`; orden de fechas sondeado antes del CHECK; `SalarySeries` advierte huecos interiores y `ServicePeriods` solapes con días inclusivos y vínculos abiertos — advertencia, nunca bloque, viajan como objeto `warnings` junto a `data`) ✅ 2026-10-02 CORREGIDO por el usuario (Task 37/SGP-31): los períodos de servicio son CERRADOS y DISJUNTOS — `end_date` DATE NOT NULL OBLIGATORIA y ESTRICTAMENTE posterior al inicio (CHECK `end_date > start_date`, migración `2026_10_02_110100`; 422 si falta, si es igual o anterior al inicio) y NINGÚN par de subregistros comparte un día (`ServicePeriods::overlappingPairs` para las filas anidadas del alta con 422 sobre `service_records` nombrando los pares, `idsOverlappingWith` para el alta individual con 422 sobre `end_date` nombrando los registros cruzados; días inclusivos: el día siguiente al fin arranca limpio) — el vínculo vigente no existe y `warnings` queda solo con los años salariales interiores ausentes; suite 1062/3562, fumiga con 9 comprobaciones de período
- [x] API de subregistros con altas/bajas dentro del expediente en estados editables únicamente (borrador/submitted). ✅ 2026-09-28 (solo `submitted` — sin estado borrador en la matriz 2.4; fuera de él 409 con el estado actual; las bajas son físicas y auditadas con valores previos — borrado por instancia, nunca en masa) ✅ 2026-10-01 AMPLIADO por la corrección de usuario, Task 32: el alta de servicios gana `forma_declaracion` opcional (`in:Documental,Testifical`; migración `2026_10_01_120000` con VARCHAR(20) NOT NULL DEFAULT 'Documental' + CHECK de los dos valores legales — la omisión cae en Documental y el campo viaja en el 201 y el detalle); la suite no se ejecutó en esta sesión (toolchain del sandbox caído, precedente Task 29) — el CI del PR queda como válvula. ✅ 2026-10-01 AMPLIADO por Task 33: la forma de declaración viaja también POR FILA en el payload anidado de creación (`service_records.*.forma_declaracion`, `in:Documental,Testifical`; omisión = Documental, desconocido 422 todo-o-nada) — el hueco de la Task 32 (descarte silencioso del valor declarado en la creación anidada) queda cerrado; suite local completa en verde (1046 tests / 3467 aserciones contra MySQL real) y fumiga de expedientes ampliada a 23 comprobaciones ✅ 2026-10-02 RENOMBRADA a columna inglesa por Task 36 (SGP-30): forma_declaracion → declaration_form (migración `2026_10_02_100000`, CHECK `chk_service_records_declaration_form`); mismo comportamiento con el wire y el OA en inglés
- [x] Feature tests transaccionales: creación de expediente con subregistros atómica (todo o nada). ✅ 2026-09-28 (puerto `TransactionManager` de Shared materializado con `DatabaseTransactionManager`; el caso y sus subregistros declarados insertan en una sola transacción)
- [x] **Corrección de usuario (Task 42, SGP-36) — IMPLEMENTADA 2026-10-03** (la documentación se ajustó primero y el usuario validó con «implemnta»; migraciones `2026_10_03_100000`..`100300`, spec OpenAPI 1.4.0, suite 1133/3945 contra MySQL real, Pint/PHPStan 8/deptrac en verde, fumigas TODO OK): cuatro ajustes de modelo refinados por las respuestas del usuario — enum en MINÚSCULAS unificadas (`tarjeta magnetica` / `nomina electronica`), todos los campos nuevos obligatorios SALVO la cuenta bancaria (exigida cuando la forma de pago del tipo de agencia de cobro es `tarjeta magnetica`), el campo del catálogo obligatorio con DEFAULT `tarjeta magnetica`, el porciento obligatorio con rango 0–100 y 2 decimales, y el grupo nuevo del promovente MODIFICABLE por PUT.
- [x] (a) ELIMINAR el modelo Tipo de pago (migración `2026_10_03_100000`): caída de la tabla `payment_types`, retiro del `CatalogRegistry` (quedan 15 tipos uniformes), del `CatalogsSeeder`, de la enumeración de tipos válidos en la descripción OA del `CatalogController` y de los tres tests que lo nombran (`CatalogRegistryTest`, `CatalogCrudTest`, `CatalogSeedingTest`); `payment-types` pasa a responder 404 de catálogo desconocido (`UnknownCatalogException`); sin dependientes — la propuesta `pension_payments` no está construida —, baja limpia.
- [x] (b) `agency_types.payment_form` VARCHAR(20) NOT NULL DEFAULT 'tarjeta magnetica' + CHECK del enum (migración `2026_10_03_100100`): enum PHP `PaymentForm` en el dominio de Catalogs con los valores en minúsculas unificadas tal como los fijó el usuario; wire por la maquinaria `extraRules` (patrón Task 38): `in:tarjeta magnetica,nomina electronica` con la omisión del alta cayendo en el DEFAULT y el PATCH relajado a `sometimes`; devuelto por TODOS los endpoints de `agency-types` (201/detalle/listado/PATCH) con espejo `CatalogItem`; seeder de tipos de agencia con `payment_form` explícito.
- [x] (c) Grupo de DOMICILIO y COBRO del promovente en `pension_cases` (migración `2026_10_03_100200`): `current_address` VARCHAR(255) NOT NULL, `residence_province_id`/`residence_municipality_id` FK NOT NULL con coherencia RN-004 sondeada (422; P-09 para el municipio especial), `collection_agency_type_id` FK NOT NULL, `collection_agency_id` FK NOT NULL (sondas ACTIVA y de-ese-tipo) y `bank_account` VARCHAR(34) NULL OBLIGATORIA CONDICIONADA a la forma de pago `tarjeta magnetica` del tipo de agencia de cobro resuelto (422 sobre `bank_account` si falta; opcional con `nomina electronica`); recibidos en el POST (todos obligatorios salvo la cuenta), devueltos en 201/detalle/listado con proyecciones (provincia, municipio, tipo de agencia con `payment_form`, agencia) y EDITABLES por PUT con probes espejo del alta y la exigencia condicional re-evaluada contra el estado RESULTANTE; `StorePensionCaseRequest`/`UpdatePensionCaseRequest`/`PensionCaseService`/`PensionCaseResource` + OA del POST/PUT + anclas nuevas de `ApiDocsTest`; fumiga `smoke_case_registration.php` ampliada (cuenta exigida con `tarjeta magnetica`, opcional con `nomina electronica`, coherencias de municipio-provincia y agencia-tipo, PUT del grupo).
- [x] (d) `income_concept_records.applied_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00 + CHECK 0–100 (migración `2026_10_03_100300`): porciento a aplicar Double OBLIGATORIO en el wire con rango 0–100 y 2 decimales exactos (cadena decimal por la doctrina RN-005, jamás float); viaja por fila en el alta anidada y en el endpoint propio (`StoreIncomeConceptRecordRequest` + OA + anclas de ApiDocsTest); DEFAULT 0.00 solo para escrituras fuera del wire.
- [x] Plan de entrega (ejecutado): TDD rojo primero en cada frente (ApiDocsTest + suites de feature: alta sin el grupo → 422, cuenta condicional, coherencias municipio-provincia y agencia-tipo, porciento fuera de rango o con más decimales, PATCH del catálogo con `payment_form`, `payment-types` → 404), migraciones en el orden (a)-(d), spec OpenAPI 1.3.0 → 1.4.0 como señal de frescura, QA completo (Pest + Pint + PHPStan 8 + deptrac 0) y regresión sobre main; la propuesta `pension_payments` pierde el clasificador `payment_type_id` (UNIQUE de período ajustado a pensionado-año-mes).

**Sprint 6 — Máquina de estados, historial y búsqueda (S6.1-S6.5)**
- [ ] Enum respaldado de estados `CaseStatus` con transiciones codificadas primero como **dataset de Pest** (la matriz completa de la sección 2.4 de requisitos): cada fila es un caso transición-permitida o transición-rechazada con su error esperado. La máquina de estados nace de ese dataset (estrategia TDD de la sección 15.2 de arquitectura).
- [ ] Transiciones como subrecurso POST (ADR-07): `POST /pension-cases/{id}/submit`, `/review`, `/approve`, `/reject`, cada uno con su evidencia exigible (nota, base legal vigente en approval/denial).
- [ ] Revisión con validación de completitud (RF-EXP-006): checklist de datos mínimos que bloquea el avance si falta.
- [ ] Denegación (RF-EXP-008) con base legal obligatoria; reapertura administrativa (RF-EXP-010) solo para rol Administrador, con motivo y bloqueo derivado.
- [ ] Historial de estados append-only (RF-EXP-009, RF-AUD-002): tabla de transiciones con estado previo/nuevo, usuario, fecha, nota; inmutable y consultable por roles de auditoría.
- [ ] Búsqueda y filtros (RF-EXP-011): por estado, oficina, persona, rango de fechas y número, con índices compuestos y paginación estricta; exportación CSV delegada a fase 6 (se registra la dependencia).

### 7.5 Entregables

Flujo completo de expediente en staging: `draft → submitted → under_review → approved/rejected` (+ reapertura) con número secuencial, subregistros validados, historial inmutable y búsquedas operativas; matriz de transiciones 100 % testeada como activo de regresión permanente.

### 7.6 Definition of Done

- [ ] La suite de la matriz de transiciones cubre el 100 % de celdas de la matriz (permitidas y prohibidas) sin excepciones.
- [ ] Dos aprobaciones concurrentes del mismo expediente no pueden ocurrir (test de carrera con transacción/lock).
- [ ] Toda transición deja fila en historial; intentar mutar historial falla en test de inmutabilidad.
- [ ] El número de expediente es único, secuencial y sin huecos bajo concurrencia (reuso del test de fases 1).
- [ ] Cobertura ≥ 90 % en dominio de PensionCases.
- [ ] Demo con el analista: expediente completo de prueba caminado por los 5 estados.

### 7.7 Gate de salida

1. Flujo submitted→rejected/approved (sin pensionado) con matriz de transiciones 100 % testeada.
2. Historial de estados operativo e inmutable.
3. Búsquedas de expedientes con rendimiento razonable en volumen sembrado (~50k expedientes sintéticos).

### 7.8 Riesgos de la fase

| Riesgo | Mitigación |
|---|---|
| Descubrimiento de estados/transiciones no previstos al validar con el analista | La matriz es un dataset declarativo: agregar una fila + ajustar el enum es un cambio local y barato |
| Rendimiento de búsquedas con datos sintéticos | Seeders de volumen desde ya; EXPLAIN en el DoD; índices compuestos por diseño del modelo |

---

## 8. Fase 4 — Motor de cálculo de pensiones

**Sprints 7-8 · Semanas 13-16 · Módulos: PensionCalculation**

### 8.1 Objetivo

Implementar el dominio financiero del sistema: verificación de elegibilidad, cómputo de años de servicio, salario promedio y cuantía con topes e incrementos, con estrategias por régimen, simulación sin persistencia y persistencia congelada de resultados. Es el corazón TDD del proyecto (sección 15.2 de arquitectura): cada regla de cálculo existe primero como dataset firmado.

### 8.2 Precondiciones (gate de entrada)

- [ ] Gate 3 superado y registrado.
- [ ] Datasets de cálculo elaborados por el equipo y **pendientes de firma** por el analista (P-01..P-03). El gate de salida exige la firma; el trabajo no se detiene mientras tanto (regla de contingencia de la sección 3).

### 8.3 Alcance funcional

| Módulo | Requisitos | Notas |
|---|---|---|
| PensionCalculation | RF-CAL-001..004, RF-CAL-006, RF-CAL-007, RF-CAL-008 (M); RF-CAL-005 (S) | Elegibilidad, servicio, promedio, cuantía, simulación, trazabilidad |

### 8.4 Tareas por sprint

**Sprint 7 — Elegibilidad, servicio y promedio (S7.1-S7.5)**
- [ ] `EligibilityChecker` por TDD (RF-CAL-001): edad mínima por sexo y años mínimos contra configuración vigente a la fecha de solicitud; resultado booleano por criterio con explicación legible (dataset: casos borde de cumpleaños y años justos).
- [ ] Cómputo de años de servicio (RF-CAL-002, corrección H-15): sumatoria de intervalos de `service_records` (abierto = hasta Clock fijo), conversión de `work_cycles` con meses-por-año del régimen, integración de coletilla conforme al parámetro del régimen (P-03 parametrizado).
- [ ] Salario promedio (RF-CAL-003) con `Money` y redondeo bancario; política de selección de años como estrategia intercambiable (P-01: todos / últimos N / mejores N) seleccionable por configuración del régimen.
- [ ] Test de contrato compartido entre políticas de promedio (Liskov): mismo dataset de entrada produce resultados documentados por política.
- [ ] Conceptos de ingreso (RF-CAL-005, S): los conceptos marcados `aplica_salario_base` participan del salario base de referencia, como invariante testeable.

**Sprint 8 — Cuantía, simulación y persistencia congelada (S8.1-S8.5)**
- [ ] Cuantía base (RF-CAL-004) por TDD: cuantía = promedio × (base + incremento × años excedentes), topeada por máximo; todo parámetro desde `general_settings` vigente, jamás hardcodeado. Datasets con topes exactos, excedentes cero y valores límite.
- [ ] `RegimeCalculationStrategyInterface` + estrategias por régimen con test de contrato común (misma interfaz, mismos datasets base + específicos por régimen).
- [ ] Simulación sin persistencia (RF-CAL-006): `PreviewCalculationAction` para expedientes en submitted/under_review que devuelve elegibilidad, años, promedio, cuantía y advertencias sin escribir resultados.
- [ ] Persistencia del cálculo al aprobar (RF-CAL-007): cuantía, parámetros usados, **versión de configuración** y usuario en la misma transacción que la aprobación; el expediente aprobado congela inmutable su cálculo.
- [ ] Trazabilidad del motor (RF-CAL-008, RNF-010): cada ejecución persistente guarda entradas + versión de `general_settings`; test de re-ejecución histórica reproduce exactamente la cuantía original (dataset congelado).
- [ ] Infection (mutación) ejecutado sobre el paquete de cálculo: score mínimo acordado (≥ 80 % en dominio de cálculo) como termómetro de la calidad de la suite (sección 12.4 de arquitectura).

### 8.5 Entregables

Motor de cálculo completo con estrategias por régimen; simulación operativa en staging; persistencia congelada y reproducible; datasets de cálculo firmados por el analista como artefacto de aceptación versionado en el repositorio.

### 8.6 Definition of Done

- [ ] Todos los datasets de cálculo (elegibilidad, servicio, promedio, cuantía, re-ejecución histórica) en verde y firmados por el analista.
- [ ] Las tres políticas de promedio (P-01) implementadas y seleccionables por régimen, con la elegida por defecto documentada.
- [ ] La simulación no escribe absolutamente nada (verificado por test de transacción vacía).
- [ ] Un expediente aprobado con configuración antigua recalcula hoy y produce la cuantía original exacta (test de RNF-010).
- [ ] Cobertura ≥ 95 % en dominio de PensionCalculation (estándar reforzado por criticidad financiera).
- [ ] Ningún literal numérico de política de pensión en el código (revisión de PR explícita de este punto; parámetros solo desde Settings).

### 8.7 Gate de salida

1. Cálculo end-to-end demostrado con datasets aprobados por el analista.
2. Trazabilidad RF-CAL-008 verificada: re-ejecución histórica reproduce resultados.
3. Simulación disponible para uso en revisión de expedientes.

### 8.8 Riesgos de la fase

| Riesgo | Mitigación |
|---|---|
| Ambigüedad de reglas de cálculo (P-01..P-03) | Parametrización + validación temprana con datasets firmados; sprint de contingencia definido |
| Errores financieros silenciosos | Infection sobre cálculo; `Money` inmutable; revisión cruzada obligatoria de todo PR del paquete |

---

## 9. Fase 5 — Pensionados y pagos

**Sprints 9-10 · Semanas 17-20 · Módulos: Pensioners, Payments**

### 9.1 Objetivo

Cerrar el ciclo de negocio: la aprobación de un expediente genera el pensionado (atómicamente), el pensionado recibe control bancario con número de secuencia único, y la nómina electrónica se exporta por agencia. Se añade el registro de pagos periódicos como propuesta controlada (RF-PAG-005, C, pendiente de validación funcional H-16).

### 9.2 Precondiciones (gate de entrada)

- [ ] Gate 4 superado (datasets firmados) — requisito para las tareas dependientes de cuantía.
- [ ] Formatos de nómina por agencia recabados (P-05); si no están, la exportación se construye con plantilla configurable y una agencia piloto.

### 9.3 Alcance funcional

| Módulo | Requisitos | Notas |
|---|---|---|
| Pensioners | RF-PEN-001..003 (M), RF-PEN-004 (S) | Alta atómica, ficha, ciclo de vida, reclasificación |
| Payments | RF-PAG-001..003, RF-PAG-006 (M), RF-PAG-004 (S), RF-PAG-005 (C) | Control bancario, catálogos, secuencias, nómina, pagos (propuesta) |

### 9.4 Tareas por sprint

**Sprint 9 — Pensionados (S9.1-S9.4)**
- [ ] Alta automática desde aprobación (RF-PEN-001, corrección H-04): en la MISMA transacción que la aprobación se crea el pensionado con persona, tipo y régimen de pensión y cuantía calculada; referencia única al expediente de origen (`origin_case_id`); fallo si la persona ya tiene pensionado activo.
- [ ] Feature test transaccional end-to-end: aprobar expediente → verificar pensionado creado con exactamente la cuantía congelada; rollback simulado no deja pensionado huérfano.
- [ ] Ficha del pensionado (RF-PEN-002): datos de persona, tipo/régimen, cuantía, expediente de origen, historial y controles bancarios; listados filtrables por provincia, tipo, régimen y estado.
- [ ] Ciclo de vida de la pensión (RF-PEN-003): suspensión, reanudación, extinción (fallecimiento enlaza con RF-PER-003) con transiciones auditadas; reclasificación (RF-PEN-004, S) con nuevo cálculo versionado.

**Sprint 10 — Control bancario y nómina (S10.1-S10.5)**
- [ ] Catálogos del pago (RF-PAG-003): tipos de pago, formas, estados según modelo de datos.
- [ ] Registro de control bancario (RF-PAG-001): agencia, número de control generado vía `numbering_sequences` con **bloqueo pesimista** (corrección H-06/RF-PAG-006); unicidad garantizada por constraint de BD.
- [ ] Asignación al pensionado (RF-PAG-002): un control activo por pensionado, historial de reasignaciones auditado.
- [ ] Test de concurrencia real de secuencias de control bancario: N procesos paralelos generando controles sin duplicados ni huecos, con medición de tiempo de espera (riesgo de contención de la sección 15.3 de arquitectura).
- [ ] Exportación de nómina electrónica (RF-PAG-004): por agencia y período, en cola (`queue`), con plantilla de formato configurable por agencia (P-05) y archivo de verificación de conteo.
- [ ] Pagos periódicos (RF-PAG-005, C — propuesta H-16): implementación mínima controlada detrás de feature flag, marcada "pendiente de validación funcional" en la documentación viva; no bloquea el gate.

### 9.5 Entregables

Ciclo completo expediente→pensionado→control bancario→nómina en staging; secuencias bancarias a prueba de concurrencia; exportación de nómina con plantillas por agencia; esqueleto de pagos periódicos acotado.

### 9.6 Definition of Done

- [ ] La aprobación end-to-end (expediente completo → pensionado con control bancario) funciona en staging con la suite de integración en verde.
- [ ] Test de concurrencia de secuencias bancarias en verde con evidencia de tiempos.
- [ ] La nómina exportada pasa validación de conteo y suma contra la BD (archivo de control).
- [ ] RF-PAG-005 claramente señalizado como propuesta en código y documentación.
- [ ] Cobertura ≥ 90 % en dominio de Pensioners/Payments.

### 9.7 Gate de salida

1. Aprobación end-to-end con control bancario demostrada.
2. Concurrencia de secuencias testeada y monitoreada.
3. Nómina electrónica exportada para la agencia piloto.

### 9.8 Riesgos de la fase

| Riesgo | Mitigación |
|---|---|
| Contención de la secuencia de control bancario | Bloqueo pesimista + tests de concurrencia real + monitoreo de espera; alternativa de rango reservado documentada si el tiempo de espera supera el umbral |
| Formato de nómina desconocido (P-05) | Plantillas configurables + agencia piloto; el formato no bloquea el resto del módulo |

---

## 10. Fase 6 — Reportes, API completa y seguridad fina

**Sprints 11-12 · Semanas 21-24 · Módulos: Reporting + endurecimiento transversal**

### 10.1 Objetivo

Elevar el sistema funcional a plataforma auditable y de alto rendimiento: reportes estadísticos con exportaciones, documentación OpenAPI completa de la API, y el endurecimiento de seguridad (políticas por rol y territorio, bloqueos, rate limiting integral). También es la fase de "pago de deudas menores": los RF tipo S aplazados se completan o se descartan explícitamente con registro.

### 10.2 Precondiciones (gate de entrada)

- [ ] Gate 5 superado y registrado.
- [ ] Volúmenes objetivo confirmados para pruebas de rendimiento (RNF-002: 500k personas, 200k expedientes) o el acuerdo del volumen real disponible.

### 10.3 Alcance funcional

| Módulo | Requisitos | Notas |
|---|---|---|
| Reporting | RF-REP-001, RF-REP-002, RF-REP-004 (M), RF-REP-003 (S), RF-REP-005 (C) | Reportes, exportaciones, tablero |
| API | RF-API-001..003 (M), RF-API-004 (S) | Versionado, convenciones, idempotencia, docs |
| Seguridad | refuerzo RF-SEG-001..004 + RNF-004 | Hardening OWASP, matriz de permisos completa |

### 10.4 Tareas por sprint

**Sprint 11 — Reportes y exportaciones (S11.1-S11.5)**
- [ ] Reportes de expedientes (RF-REP-001): por estado, oficina, período y régimen, agregados en SQL con índices compuestos; paginación estricta; reportes pesados en cola.
- [ ] Reportes de pensionados (RF-REP-002): activos/suspendidos/extintos por provincia y agencia; de pagos (RF-REP-003, S).
- [ ] Exportaciones CSV de listados filtrados (RF-REP-004, cierra la dependencia delegada desde fase 3): streaming para volúmenes grandes, en cola con notificación de finalización.
- [ ] Tablero de indicadores (RF-REP-005, C): versión mínima con contadores cacheados; se marca como ampliable post go-live.
- [ ] Pruebas de rendimiento preliminares de reportes sobre volumen sembrado; EXPLAIN de las 10 consultas más pesadas y ajuste de índices con evidencia.

**Sprint 12 — OpenAPI, idempotencia y hardening (S12.1-S12.6)**
- [ ] Documentación OpenAPI 3 generada desde el código (RF-API-004) y publicada internamente; validación de convenciones de respuesta (RF-API-002) en todos los endpoints.
- [ ] Idempotencia y concurrencia de API (RF-API-003): cabeceras `If-Match`/ETag o versión optimista en escrituras críticas; retry seguro de transiciones con Idempotency-Key.
- [x] Gestión de roles (RF-SEG-002, parte de administración): CRUD de roles con el directorio completo — los cinco institucionales inmutables (`is_system`, permisos en la matriz) y los personalizados como subconjuntos del catálogo, asignables a cuentas de inmediato — con bitácora de concesiones y 409 conversacional al borrar roles en uso, más el catálogo de permisos de solo lectura que el editor consume (`GET /api/v1/permissions` con descomposición módulo/acción, tenedores institucionales y personalizados, y cuentas con acceso efectivo). ✅ 2026-09-29 (ADR-26/ADR-27, ejecutadas antes de la Fase 6 por decisión del usuario: el personal técnico necesita perfilar roles territoriales antes de los reportes; ADR-27 completa los endpoints de permisos que el CRUD necesita — sin rutas de escritura, la matriz es la única fuente de verdad — y el dataset de la matriz unit se completa con las celdas `cases.*` pendientes; la matriz completa endpoint × rol de S12.4 sigue pendiente y deberá cubrir también los roles personalizados)
- [ ] Matriz completa de permisos por rol (extensión del dataset de fase 1): cada endpoint × cada rol = permitido/denegado, testeada por Pest (miles de casos generados declarativamente).
- [ ] Ámbito territorial: usuarios de oficina solo ven/actúan sobre expedientes de su territorio (política de dominio, no filtro de UI).
- [ ] Hardening RNF-004: OWASP Top 10 revisado punto por punto (headers de seguridad, validación estricta de entrada, rate limiting por rol y endpoint, política de contraseñas, bloqueo por intentos).
- [ ] Revisión de seguridad interna (checklist en equipo + tech lead) con hallazgos registrados y corregidos; los no críticos pasan al backlog post go-live con severidad asignada.

### 10.5 Entregables

Reportes operativos con rendimiento medido; exportaciones robustas; OpenAPI publicada; matriz de permisos 100 % testeada; reporte de revisión de seguridad con hallazgos cerrados.

### 10.6 Definition of Done

- [ ] RNF-002 verificado: listados y reportes paginados ≤ 2 s (p95) sobre volúmenes objetivo en staging.
- [ ] La matriz de permisos cubre todos los endpoints publicados sin celdas sin test.
- [ ] OpenAPI refleja el 100 % de endpoints de `/api/v1` y pasa validación de esquema.
- [ ] Ningún hallazgo de seguridad crítico o alto abierto.
- [ ] RF-S aplazados completados o descartados con registro explícito en la matriz de trazabilidad.

### 10.7 Gate de salida

1. Reportes con volúmenes objetivo ≤ RNF-002 demostrado con evidencia.
2. Revisión de seguridad interna sin hallazgos críticos/altos abiertos.
3. API documentada y con convenciones uniformes verificadas.

### 10.8 Riesgos de la fase

| Riesgo | Mitigación |
|---|---|
| Rendimiento de reportes histórico/creciente | Índices compuestos por diseño, cola para pesados, caché de agregados del tablero |
| Explosión de casos de la matriz de permisos | Generación declarativa de casos (producto cartesiano rol×endpoint), no redacción manual |

---

## 11. Fase 7 — Estabilización, UAT y despliegue

**Sprint 13 · Semanas 25-26 · Módulos: transversal**

### 11.1 Objetivo

Demostrar que el sistema está listo para producción: verificación final de todos los RNF, prueba de carga formal, pruebas de aceptación con usuarios reales del Ministerio (UAT), manuales de operación y el plan de despliegue on-premise ejecutable. La fase termina con el acta UAT firmada y la decisión Go/No-Go.

### 11.2 Precondiciones (gate de entrada)

- [ ] Gate 6 superado y registrado.
- [ ] Usuarios UAT designados por el Ministerio (mínimo: 2 tramitadores, 1 revisor, 1 administrador, 1 auditor).
- [ ] Plan de migración de datos históricos acotado (P-07: si hay migración, es proyecto aparte; el go-live no la bloquea).

### 11.3 Tareas del sprint

**Verificación de RNF y carga (S13.1-S13.2)**
- [ ] Prueba de carga formal (RNF-001): 100 usuarios concurrentes, p95 ≤ 500 ms en APIs transaccionales, con reporte de resultados.
- [ ] Prueba de disponibilidad y observabilidad: healthcheck, logs estructurados con `request_id` correlacionable con bitácora, slow query log revisado.
- [ ] Simulacro de respaldo y restauración (RNF-009): dump diario + binlog, RPO ≤ 24 h / RTO ≤ 4 h cronometrados y documentados.

**UAT y cierre (S13.3-S13.5)**
- [ ] Guion de UAT por rol basado en los RF (recorridos completos: expediente de vida, cálculo, aprobación, nómina, auditoría).
- [ ] Ejecución de UAT con usuarios reales; hallazgos clasificados (bloqueante/menor) y los bloqueantes corregidos dentro del sprint o replanificados explícitamente.
- [ ] Manuales de operación: instalación on-premise (docker compose), respaldo/restauración, gestión de usuarios y roles, resolución de incidencias básicas.
- [ ] Plan de despliegue: checklist paso a paso, estrategia de rollback, verificación post-instalación.
- [ ] Reunión Go/No-Go con acta firmada por el Ministerio; en Go: despliegue a producción, tag `v1.0.0` y monitoreo intensivo la primera semana.

### 11.4 Entregables

Reportes de carga y restauración; guion y resultados de UAT; manuales de operación; plan de despliegue con rollback; acta UAT; producción desplegada (si Go).

### 11.5 Definition of Done

- [ ] RNF-001..010 verificados individualmente con evidencia archivada en el repositorio.
- [ ] Acta UAT firmada sin hallazgos bloqueantes abiertos.
- [ ] Simulacro de restauración ejecutado en el propio entorno de producción (o equivalente) dentro de los tiempos RPO/RTO.
- [ ] Manuales revisados por alguien ajeno a su redacción (prueba de "seguir los pasos tal cual").
- [ ] Tag `v1.0.0` y CHANGELOG de release publicado.

### 11.6 Gate de salida (final)

1. Acta UAT firmada.
2. RNF verificados.
3. Go-live aprobado y ejecutado con monitoreo de la primera semana sin incidentes críticos.

### 11.7 Riesgos de la fase

| Riesgo | Mitigación |
|---|---|
| Hallazgos bloqueantes de UAT tardíos | Guion de UAT ensayado en staging por el equipo antes de la sesión formal; presupuesto de corrección dentro del sprint |
| Infraestructura del Ministerio no lista | Plan de despliegue con checklist de prerrequisitos verificados 2 semanas antes; go-live diferido no penaliza el resto del sistema ya aceptado |
| Datos históricos sorpresivos (P-07) | Alcance de go-live sin migración; proyecto de migración aparte posterior |

---

## 12. Gobernanza del plan

### 12.1 Ceremonias y cadencia

Sprint de 2 semanas con: planning (primer día, 1 h), daily (15 min), review/demo al cierre con checklist de RFs tocados y participación del analista, y retrospective (30 min). Los gates de fase se revisan en la review del último sprint de cada fase y su superación se registra en el repositorio (carpeta `docs/gates/` con fecha, evidencias y firmante), quedando como actas verificables del avance.

### 12.2 Gestión de cambios de alcance

Todo cambio de alcance entra como issue etiquetado `scope-change` y se evalúa en weekly de control: si desplaza gates o fechas, se decide explícitamente entre aplazar (backlog por prioridad MoSCoW), sustituir (quitar equivalente) o ampliar (re-planificación formal con nueva fecha aprobada por el Ministerio). Ningún cambio entra "por la puerta de atrás": la matriz de trazabilidad (sección 13) es el espejo donde se refleja el alcance real en todo momento.

### 12.3 Bloqueos y re-planificación

Si un gate no se supera al cierre de su fase: (1) se documenta la causa raíz en `docs/gates/`; (2) se activa el plan de contingencia de la fase si existe (p. ej. Gate 4, sección 3); (3) si no existe, se convoca re-planificación dentro de las 48 h siguientes con decisión explícita: extender la fase (máximo +1 sprint), reducir su alcance (los RF-C/S pasan al backlog) o aceptar deuda con plan de pago fechado. Las tres opciones dejan rastro documentado.

### 12.4 Métricas de seguimiento

| Métrica | Umbral de alerta | Fuente |
|---|---|---|
| Velocidad del sprint (puntos) | -20 % frente a media de 3 sprints | Board del repositorio |
| Cobertura global / dominio | < 80 % / < 90 % | CI en cada PR |
| Deuda de gates abiertos | > 0 tras 1 semana de cierre nominal | `docs/gates/` |
| PRs abiertos > 3 días | > 20 % del total | Board del repositorio |
| Hallazgos de seguridad abiertos | cualquier crítico/alto | Revisión de fase 6 |
| RNF pendientes de verificar | > 3 en semana 24 | Checklist de fase 7 |

### 12.5 Gestión de riesgos global

La tabla de riesgos por fase (secciones 4.7 a 11.7) se consolida y re-evalúa en cada retrospective: probabilidad e impacto se recalifican, se añaden riesgos emergentes y se cierran los mitigados. El owner de cada riesgo es siempre una persona concreta, nunca "el equipo". Los riesgos de nivel Alto-Alto se reportan al Ministerio en la demo siguiente.

---

## 13. Matriz de trazabilidad del plan

### 13.1 Cobertura de RF por fase

| Fase | RF Must (M) | RF Should (S) | RF Could (C) |
|---|---|---|---|
| 0 | RF-SEG-001 (esqueleto) | — | — |
| 1 | RF-CAT-001..005, RF-PER-001..004, RF-SEG-002..004, RF-AUD-001/003/004 | RF-CAT-006, RF-PER-005 | — |
| 2 | RF-ENT-001..004, RF-LEG-001..003 | RF-ENT-005, RF-LEG-004 | — |
| 3 | RF-EXP-001..009, RF-EXP-011, RF-AUD-002 | RF-EXP-010 | — |
| 4 | RF-CAL-001..004, RF-CAL-006..008 | RF-CAL-005 | — |
| 5 | RF-PEN-001..003, RF-PAG-001..003, RF-PAG-006 | RF-PEN-004, RF-PAG-004 | RF-PAG-005 (propuesta H-16) |
| 6 | RF-REP-001/002/004, RF-API-001..003 | RF-REP-003, RF-API-004 | RF-REP-005 |
| 7 | (verificación integral) | — | — |

Los 66 RF del catálogo quedan asignados: 54 M (todos con fase), 10 S distribuidos y 2 C acotados con condición. Cualquier RF sin fase es un error de este plan que debe corregirse en la primera weekly de control.

### 13.2 Cobertura de RNF por fase

| RNF | Verificado en | Nota |
|---|---|---|
| RNF-001, RNF-002 | Fase 6 (preliminar) + Fase 7 (formal) | Pruebas de carga |
| RNF-003 | Fase 7 | Monitoreo de primera semana post go-live |
| RNF-004 | Fases 0 (básico) + 6 (completo) | Revisión OWASP |
| RNF-005 | Fase 1 | Bitácora transversal desde el primer CRUD |
| RNF-006 | Fase 7 | Despliegue limpio on-premise |
| RNF-007 | Fase 0 en adelante (continuo) | CI en cada PR |
| RNF-008 | Fases 1-4 (continuo) | Money + DECIMAL en cada migración |
| RNF-009 | Fase 7 (simulacro) | Respaldos configurados desde fase 0 |
| RNF-010 | Fase 4 | Re-ejecución histórica congelada |

### 13.3 Referencias cruzadas

- Requisitos, prioridades y preguntas abiertas: `Requisitos funcionales.md` (secciones 4-7).
- Módulos, capas, patrones, TDD y ADR: `Diseño de arquitectura.md` (secciones 3-16).
- Tablas, constraints, índices, migraciones y seeders: `Modelo de datos.md`.
- Este plan expande la sección 15 de `Diseño de arquitectura.md`; ante conflicto de criterio, prevalecen los ADR de arquitectura y las decisiones se reconcilian por gestión de cambios (sección 12.2 de este documento).

## 14. Control de versiones del documento

| Versión | Fecha | Cambios | Autor |
|---|---|---|---|
| 1.0 | 2026-09-26 | Versión inicial: 8 fases, 13 sprints, gates, DoD, trazabilidad completa | Arquitectura Backend |
| 1.1 | 2026-09-27 | Sprint 3: nota de orden de ejecución dependencia-conducente (RBAC y bitácora antes de People para cerrar RF-PER-002 dentro del slice de personas); sin cambios de alcance, fechas ni gates | Arq. Backend |
| 1.2 | 2026-09-27 | Sprint 3: S3.1-S3.3 (Personas, ADR-20) marcados completos junto a S3.4 (RBAC, ADR-18/PR #9) y auditoría base (ADR-19/PR #10); queda pendiente del sprint únicamente S3.5 (usuario↔persona + restricción por estado) | Arq. Backend |
| 1.3 | 2026-09-28 | Sprint 3: S3.5 marcado completo (ADR-21, PR #13) — asociación usuario↔persona con unicidad en BD y 409 conversacional, `/auth/me` enriquecido con la persona vinculada y regla de estado `canStartNewProcess`; Sprint 3 cerrado al 100% | Arq. Backend |
| 1.4 | 2026-09-28 | Sprint 4: S4.1-S4.3 (Estructura organizacional, ADR-22) marcados completos — entidades/oficinas/firmas con jerarquías acíclicas por dominio puro, coherencia geográfica RN-04 doble, firmas con terna reservada y estado derivado, árboles de 5 niveles; permisos `organizations.*` en la matriz; queda pendiente del sprint la base legal (S4.4-S4.5) | Arq. Backend |
| 1.5 | 2026-09-28 | Sprint 4: S4.4-S4.5 (Base legal, ADR-23) marcados completos — `legal_bases` con terna única e inmutable, año derivado (H-11), vigencias derivadas con selector `status=effective`, derogación como edición auditada y consulta documental; permisos `legalbases.*` en la matriz (15 permisos); Sprint 4 cerrado al 100% | Arq. Backend |
| 1.6 | 2026-09-28 | S3.6 (gestión de usuarios, ADR-24) añadida y completada — orden ajustado por decisión del usuario ANTES de abrir la Fase 3: bloqueo por intentos fallidos con desbloqueo del Administrador, política de contraseñas con caducidad opcional, CRUD administrativo con roles y revocación de sesiones, renovación propia y restablecimiento; permiso `users.view` (16 permisos) | Arq. Backend |
| 1.7 | 2026-09-28 | Sprint 5 de la Fase 3 (S5.1-S5.5, ADR-25) — expediente con número secuencial y unicidad física de expediente abierto por persona (columna generada), creación atómica con subregistros vía TransactionManager, altas/bajas de subregistros solo en submitted, advertencias de evidencia (huecos salariales, solapes, vínculos abiertos) como objeto warnings; matriz a 19 permisos (cases.view/create/edit) | Arq. Backend |
| 1.8 | 2026-09-29 | Gestión de roles (RF-SEG-002, ADR-26, adelantada desde la Fase 6 por decisión del usuario) — CRUD `/api/v1/roles`: institucionales `is_system` inmutables + personalizados con subconjuntos del catálogo, asignación de personalizados a cuentas, bitácora de concesiones con set previo, 409 al borrar roles en uso; matriz a 21 permisos (roles.view/roles.manage) | Arq. Backend |
| 1.9 | 2026-09-29 | Catálogo de permisos de solo lectura (RF-SEG-002, ADR-27, extensión de la gestión de roles) — `GET /api/v1/permissions` + detalle por clave natural `modulo.accion` con descomposición módulo/acción, tenedores institucionales desde la matriz, roles personalizados desde los pivotes y `users_count` con desactivadas incluidas; gate `roles.view`, sin rutas de escritura; dataset de la matriz completado con las 15 celdas `cases.*` que el Sprint 5 dejó sin cubrir y test de convención de nombres | Arq. Backend |
| 1.10 | 2026-09-30 | Conteo de expedientes por oficina «en su ámbito» (RF-ENT-005 segunda parte, ADR-28 — la pieza que ADR-22 dejó «diferida a F3», desbloqueada por el Sprint 5) — `cases_count`/`scope_cases_count` en el árbol y el detalle de oficinas vía `HierarchyTotals` de dominio puro y el puerto `OfficeCaseCountQueryInterface` implementado por PensionCases; y pertenencia del usuario a una oficina (ADR-29, base del ámbito territorial de S6) — `users.office_id` nullable validado contra el directorio activo, PATCH con semántica de presencia, `/auth/me` y el directorio de cuentas portan la oficina, guard de desactivación 422 mientras queden usuarios activos asignados y `Security → Organizations` en deptrac | Arq. Backend |
| 1.11 | 2026-09-30 | Validación del carné de identidad corregida (RN-001, ADR-30, corrección de usuario): 11 dígitos, mes (dígitos 3-4) y día (dígitos 5-6) — año y consecutivo sin validar — y el sexo codificado en el dígito 10 (par masculino, impar femenino) contrastado contra el declarado al alta y contra el número inmutable en PATCH; eliminados el prefijo siglo/sexo 1-6 y la fecha real del calendario del value object (ítem S2.1 anotado) | Arq. Backend |
| 1.12 | 2026-09-30 | Estructura territorial de oficinas (ADR-31, corrección de usuario sobre RF-ENT-002, ítem S4.1 ampliado): unicidad nacional/provincial-por-provincia/municipal-por-municipio entre activas, `parent_office_id` derivado del tipo, prerrequisitos del superior, guard de hijas activas ante re-tipo/re-ubicación y `NationalOfficeSeeder` al arranque — suite 975/3100, fumiga HTTP de 25 comprobaciones | Arq. Backend |
| 1.13 | 2026-09-30 | Reglas de usuario 0-5 del expediente (ADR-32/33, ítem S5.2 ampliado): oficina asumida del usuario que registra (puerto Shared `CurrentUserOfficeProviderInterface`), número PP-YYYY-CCCCC con consecutivo anual (`nextForYear` sobre scope `pension_case:{año}`), tope de 15 salarios vivos, clasificación de pensión y par de Ejército Rebelde, conceptos de ingreso como subregistro (tabla `income_concept_records` + endpoints) y listado con promovente completo — suite 1023/3279, fumiga HTTP de 20 comprobaciones | Arq. Backend |
| 1.14 | 2026-10-01 | Numeración territorial del expediente (ADR-34, corrección de usuario sobre la regla 2, ítem S5.2 ampliado): el número pasa a PPMMAACCCCC — once dígitos contiguos: provincia y municipio de la oficina registrante, últimos dos dígitos del año en curso y consecutivo de 5 por el trío año/provincia/municipio (`nextForTerritory` sobre scope `pension_case:{año}:{provincia}:{municipio}` que nace en 1 a su primera emisión, sin pre-declaración del seeder) — suite 1032/3299 | Arq. Backend |
| 1.15 | 2026-10-01 | Corrección de usuario (Task 31, ítem S4.1 ampliado): nombre denominativo obligatorio de la entidad (`entities.name` VARCHAR(120) NOT NULL, migración `2026_10_01_100000`, búsqueda `q` ampliada) y código como clave natural de TODOS los catálogos uniformes (las siete tablas solo-nombre ganan `code` VARCHAR(10) UNIQUE con semilla de referencia, migración `2026_10_01_110000`; todos los listados devuelven el campo) — suite 1040/3442, fumiga HTTP `scripts/smoke_task31_catalogs_entity.php` de 25 comprobaciones | Arq. Backend |
| 1.16 | 2026-10-01 | Corrección de usuario (Task 32, ítem S5.4 ampliado): forma de declaración del tiempo de servicio — `service_records.forma_declaracion` VARCHAR(20) NOT NULL DEFAULT 'Documental' con CHECK de los dos valores legales (migración `2026_10_01_120000`); el POST de servicios acepta el campo opcional (`in:Documental,Testifical`, omisión = Documental) y lo devuelve en el 201 y el detalle — suite no ejecutada en esta sesión (toolchain del sandbox caído, precedente Task 29): el CI del PR queda como válvula | Arq. Backend |
| 1.17 | 2026-10-01 | Task 33 (ítem S5.4 ampliado, cierre del hueco de Task 32): forma de declaración en el alta anidada — `service_records.*.forma_declaracion` en `POST /pension-cases` (`in:Documental,Testifical`; omisión = Documental; desconocido 422 todo-o-nada), normalizada por `serviceRows` y persistida por `createServiceRecords`; suite local completa en verde (1046 tests / 3467 aserciones contra MySQL real), Pint/PHPStan 8/deptrac en verde y fumiga de expedientes ampliada a 23 comprobaciones | Arq. Backend |
| 1.18 | 2026-10-01 | Corrección de usuario (Task 34, ítem S5.2 ampliado): persona por del expediente — `pension_cases.persona_por` VARCHAR(120) NULL (migración `2026_10_01_130000`), texto libre opcional recibido en el alta (422 sobre 121 caracteres, omisión = NULL) y devuelto en el 201, el detalle y el listado; revisión de la búsqueda `q` del listado de entidades (código, nombre, NIT y objeto social; descripción OA corregida) — suite 1049/3485 contra MySQL real, Pint/PHPStan 8/deptrac en verde y fumiga de expedientes a 26 comprobaciones | Arq. Backend |
| 1.19 | 2026-10-01 | Corrección de usuario (Task 35, sobre la Task 34; ítem S5.2 ampliado): la persona por pasa de texto libre a REFERENCIA a una persona registrada — `persona_por_id` BIGINT UNSIGNED NULL FK → `people` (migración `2026_10_01_140000` que sustituye el VARCHAR(120)), sonda `assertPersonaPorIsRegistered` sobre la superficie ACTIVA (desconocida/desactivada = 422 sobre `persona_por_id`, omisión = NULL normalizada), proyección `persona_por_id` + `persona_por` completa (`PersonResource` reutilizado) en 201/detalle/listado y schemas OA del requestBody y del response actualizados — suite 1050/3495 contra MySQL real, Pint/PHPStan 8/deptrac en verde y fumiga de expedientes con la persona por en 4 comprobaciones | Arq. Backend |
| 1.20 | 2026-10-02 | Corrección de usuario (Task 36, SGP-30; ítems S5.2 y S5.4 ampliados): las columnas añadidas en español por las correcciones Task 32-35 pasan al patrón INGLÉS de todas las columnas previas (ADR-03, vinculante para todo el desarrollo) — `forma_declaracion` → `declaration_form` (migración `2026_10_02_100000`, CHECK renombrado) y `persona_por_id` → `filed_by_person_id` (migración `2026_10_02_100100`, FK renombrada); wire, proyección `filed_by`, OA y fumiga en inglés; los valores Documental\|Testifical no cambian — suite 1050/3495 contra MySQL real, Pint/PHPStan 8/deptrac en verde y fumiga TODO OK | Arq. Backend |
| 1.21 | 2026-10-02 | Corrección de usuario (Task 37, SGP-31; ítems S5.2 y S5.3 ampliados): internacionalista del promovente (`internationalist` TINYINT(1) NOT NULL DEFAULT 0, booleana OBLIGATORIA en el wire) y par de contacto `phone`/`popular_council` (VARCHAR(30)/(120) opcionales; migración `2026_10_02_110000`) recibidos en el alta y devueltos en 201/detalle/listado; subregistros de servicio con períodos CERRADOS y DISJUNTOS — `end_date` NOT NULL estrictamente posterior (CHECK `end_date > start_date`, migración `2026_10_02_110100`) y solapamiento rechazado con 422 en ambos puntos de entrada por `ServicePeriods` (sin vínculos abiertos; `warnings` reducido a los años salariales ausentes) — suite 1062/3562 contra MySQL real, Pint/PHPStan 8/deptrac en verde y fumiga de expedientes ampliada TODO OK | Arq. Backend |
| 1.22 | 2026-10-02 | Corrección de usuario (Task 38, SGP-32; ítems S4.1 y S5.2 ampliados): fecha de desvinculación del promovente (`termination_date` DATE NULL, migración `2026_10_02_120000`) opcional con regla de forma Y-m-d y devuelta en 201/detalle/listado; columnas propias de los catálogos de pensión (`pension_regimes.sector` INT NULL, `2026_10_02_120100`; `pension_types.deceased_person` DEFAULT false, `2026_10_02_120200`) devueltas por TODOS los endpoints del catálogo genérico con el PATCH relaxado a `sometimes`; FIX del listado de entidades devolviendo los DATOS del director general y el económico como proyecciones completas de Persona — suite 1069/3635 contra MySQL real, Pint/PHPStan 8/deptrac en verde y fumiga de expedientes ampliada a 54 comprobaciones TODO OK | Arq. Backend |
| 1.23 | 2026-10-02 | Corrección de usuario (Task 40, SGP-34; ítem S5.2 ampliado): ciclo de vida del expediente — `PUT /pension-cases/{id}` edita los campos propios en `submitted` con semántica PATCH y probes espejo del alta mientras el PROMOVENTE queda INMUTABLE (todo campo de la esfera de la persona y los de ciclo de vida responden 422 prohibido), y `DELETE /pension-cases/{id}` soft-delete SOLO en `submitted` (la fila sobrevive con `deleted_at`, los subregistros quedan físicos, el detalle responde 404) con la reservación de un-abierto-por-persona LIBERADA (migración `2026_10_02_130000`: `open_case_key` NULL también en filas borradas) — suite 1108/3788 contra MySQL real, Pint/PHPStan 8/deptrac en verde y fumiga HTTP del ciclo de vida de 28 comprobaciones TODO OK | Arq. Backend |
| 1.24 | 2026-10-03 | Corrección de usuario (Task 41, SGP-35; ítem S5.2 ampliado): el listado de expedientes queda con ALCANCE TERRITORIAL — solo cargan los expedientes cuya oficina coincide con la del usuario autenticado; la oficina NO viaja en la petición (`office_id` prohibido en la query, 422) y el scope se deriva del puerto Shared `CurrentUserOfficeProviderInterface` con guard fail-closed en el servicio (sin criterio de oficina → página VACÍA, jamás el directorio sin alcance); spec OpenAPI 1.3.0 anclada por ApiDocsTest — suite 1113/3829 contra MySQL real, Pint/PHPStan 8/deptrac en verde y fumiga de expedientes ampliada a 61 comprobaciones TODO OK | Arq. Backend |
| 1.25 | 2026-10-03 | Corrección de usuario (Task 42, SGP-36; IMPLEMENTADA tras la validación del usuario): la documentación de desarrollo incorpora los cuatro ajustes — eliminación del catálogo `payment_types`, `agency_types.payment_form` (enum en minúsculas unificadas `tarjeta magnetica`/`nomina electronica`, OBLIGATORIO con DEFAULT `tarjeta magnetica`), grupo de DOMICILIO y COBRO del promovente en el expediente (`current_address`, provincia y municipio de residencia con RN-004, tipo de agencia y agencia de cobro, `bank_account` OBLIGATORIA CONDICIONADA a `tarjeta magnetica`, todo el grupo editable por PUT) y `income_concept_records.applied_percent` (DECIMAL(5,2) obligatorio 0–100 con 2 decimales exactos) — con ADR-35, los ítems de implementación preparados al cierre del Sprint 5 y los changelogs de Modelo de datos (1.26) y Arquitectura (1.34) alineados; IMPLEMENTADA tras la validación del usuario (migraciones `2026_10_03_100000`..`100300`, spec 1.4.0, suite 1133/3945 contra MySQL real, Pint/PHPStan 8/deptrac en verde, fumigas TODO OK) | Arq. Backend |

