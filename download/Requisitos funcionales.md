# Requisitos Funcionales — Sistema de Gestión de Pensionados (SGP)

| Campo | Valor |
|---|---|
| Proyecto | Sistema de Gestión de Pensionados (SGP) |
| Cliente | Ministerio de Trabajo |
| Documento | Requisitos Funcionales |
| Versión | 1.0 |
| Fecha | 2026-09-22 |
| Estado | Borrador para revisión del equipo de desarrollo |
| Documentos relacionados | `Diseño de arquitectura.md`, `Modelo de datos.md` |
| Documento de origen | `mdeditor.a8RzJZB8.md` (modelos propuestos) |

---

## 1. Introducción

### 1.1 Propósito

Este documento especifica los requisitos funcionales y no funcionales del **Sistema de Gestión de Pensionados (SGP)** para el Ministerio de Trabajo. Es la fuente primaria de verdad del alcance funcional y la base contractual del ciclo de desarrollo: cada requisito posee un identificador único, una prioridad MoSCoW y una lista verificable de criterios de aceptación que servirá, a su vez, como inventario mínimo de pruebas de aceptación en la estrategia TDD descrita en el documento de arquitectura. El documento parte del análisis crítico del modelo conceptual entregado por el cliente (`mdeditor.a8RzJZB8.md`), cuyos hallazgos se documentan en la sección 3 y justifican los requisitos derivados.

### 1.2 Alcance

El SGP cubre el ciclo de vida completo de la atención a pensionados: gestión de catálogos y datos maestros (geografía, organismos, entidades, oficinas, clasificadores), registro de personas y solicitantes, tramitación de expedientes de pensión con máquina de estados (Solicitud → Revisión → Aprobado/Denegado), motor de cálculo de cuantía parametrizado, alta y seguimiento de pensionados, gestión del control bancario y de pagos, así como los servicios transversales de seguridad (autenticación, RBAC), auditoría, reportes estadísticos y una API REST versionada.

Quedan fuera del alcance de esta versión: integración en línea con sistemas bancarios externos (la exportación de nómina electrónica se entrega como archivo), interoperabilidad con otras plataformas estatales, trámites en línea para el ciudadano (autogestión) y migración masiva de sistemas legados, que se aborda como proyecto aparte.

### 1.3 Audiencia

Equipo de desarrollo backend (arquitectos, desarrolladores Laravel, QA). El lenguaje es técnico directo; los identificadores de código y base de datos se expresan en inglés conforme a la convención acordada, con glosario de equivalencias ES↔EN en el documento `Modelo de datos.md`.

### 1.4 Convenciones del documento

- **Identificación**: `RF-<MOD>-NNN` para requisitos funcionales, `RNF-NNN` para no funcionales, `RN-NNN` para reglas de negocio y `H-NN` para hallazgos del análisis del modelo.
- **Módulos**: CAT (catálogos), PER (personas), ENT (entidades/oficinas), LEG (base legal), EXP (expedientes), CAL (cálculo), PEN (pensionados), PAG (pagos), SEG (seguridad), AUD (auditoría), REP (reportes), API (interfaz REST).
- **Prioridad MoSCoW**: `M` (Must, obligatorio MVP), `S` (Should, deseable MVP), `C` (Could, backlog).
- **Criterios de aceptación**: checklist verificable por requisito (`[ ]`). El conjunto de checklists constituye el inventario de pruebas de aceptación.
- **Origen**: cada requisito indica si proviene del modelo original (`MO`) o es derivado del análisis (`DA`).

### 1.5 Referencias

- Modelo conceptual de partida: `mdeditor.a8RzJZB8.md`.
- Marco legal de referencia cubano de Seguridad Social (Ley 105/2008 y complementarias): los parámetros específicos no se presuponen; se parametrizan vía `general_settings` y se listan como preguntas abiertas (sección 7).

---

## 2. Contexto del sistema

### 2.1 Descripción general

El SGP automatiza la atención a pensionados en la estructura territorial del Ministerio de Trabajo. Las oficinas territoriales capturan solicitudes de pensión, consolidan la información laboral del proponente (historial de servicios, salarios devengados y ciclos de trabajo), verifican la elegibilidad y calculan la cuantía conforme a la configuración general vigente. La aprobación genera el expediente resuelto con su base legal de respaldo y da de alta al pensionado con su tipo y régimen de pensión. Finalmente, el sistema administra los controles bancarios (agencia, cuenta, nómina electrónica) necesarios para el pago periódico de la pensión, con numeración secuencial controlada y reportes estadísticos para la toma de decisiones.

### 2.2 Actores y roles

| Actor | Descripción | Uso principal del sistema |
|---|---|---|
| Administrador | Personal técnico del Ministerio; gestiona usuarios, roles, catálogos y configuración | Todos los módulos |
| Director | Dirección de oficina (provincial/municipal); aprueba o deniega expedientes y consulta reportes de su ámbito | EXP, CAL, REP |
| Especialista | Tramitador de expedientes; revisa, valida y calcula | EXP, CAL, PEN |
| Operador | Personal de captura; registra personas, entidades y expedientes en estado Solicitud | PER, ENT, EXP |
| Auditor | Personal de control interno; acceso de solo lectura y bitácoras | AUD, REP |
| Servicio integrador | Cliente de la API REST para futuras integraciones (SSC, bancarios) | API |

La correspondencia entre actores y roles técnicos de la aplicación se detalla en la matriz de permisos del documento de arquitectura (sección 10).

### 2.3 Restricciones tecnológicas

- Stack: PHP 8.3+, Laravel 12.x, MySQL 8.4 LTS (InnoDB, `utf8mb4`).
- Arquitectura: monolito modular con TDD y patrones SOLID (ver `Diseño de arquitectura.md`).
- Despliegue on-premise en infraestructura del Ministerio; sin dependencia de servicios en la nube pública.
- Interfaz primaria: API REST JSON consumida por el frontend del Ministerio (fuera del alcance de este documento) y por los servicios integradores.

### 2.4 Estados del expediente y transiciones

La máquina de estados del expediente es requisito normativo del dominio: solo se permiten las transiciones de la tabla siguiente; cualquier intento fuera de esta matriz debe ser rechazado por el sistema y registrado en bitácora.

| Estado (ES) | Estado (EN / código) | Transiciones permitidas |
|---|---|---|
| Solicitud | `submitted` | → `under_review`, → `rejected` |
| Revisión | `under_review` | → `approved`, → `rejected`, → `submitted` (devolución) |
| Aprobado | `approved` | terminal (genera pensionado) |
| Denegado | `rejected` | terminal (motivo obligatorio) |

Los estados terminales admiten reapertura exclusivamente por el rol Administrador, como excepción auditada (requisito RF-EXP-010).

---

## 3. Análisis del modelo conceptual propuesto

### 3.1 Metodología

Se revisó el documento de modelos contra tres criterios: (1) completitud funcional del flujo de negocio (solicitud → cálculo → aprobación → pago), (2) integridad y tipado de datos para un dominio financiero-jurídico y (3) preparación para un desarrollo TDD con reglas verificables. Cada hallazgo produce una decisión de diseño que se refleja como requisito (DA) y se materializa en el modelo de datos corregido.

### 3.2 Hallazgos y decisiones

| ID | Hallazgo en el modelo original | Severidad | Decisión adoptada |
|---|---|---|---|
| H-01 | `Persona` clasificada como catálogo: es una entidad de negocio con ciclo de vida, no un dato de referencia | Alta | Mover a entidad de negocio del módulo People (RF-PER-*) |
| H-02 | Dinero como `Double` (`ultimo salario`, `salario devengado`, `cuantía`) | Alta | Tipar todo importe como `DECIMAL(12,2)`; punto flotante prohibido para dinero (RN-005) |
| H-03 | `cuantia: int` (entero) pierde los centavos de la pensión | Alta | `amount DECIMAL(12,2)` en `pensioners` (RF-PEN-001) |
| H-04 | Falta la relación Expediente → Pensionado: la aprobación no origina al pensionado en el modelo | Alta | Agregar `origin_case_id` en `pensioners`; alta automática al aprobar (RF-PEN-001) |
| H-05 | `Base legal` no se relaciona con ninguna entidad: la resolución aprobatoria queda huérfana | Alta | Agregar `approval_legal_basis_id` en `pension_cases` (RF-EXP-008) |
| H-06 | `ultimo control bancario` (contador de secuencia) embebido en `Configuracion general`: mezcla parámetros con estado transaccional | Media | Crear tabla de secuencias centralizada con bloqueo pesimista (RF-PAG-006) |
| H-07 | `Tipo de beneficiario` es catálogo sin entidad que lo consuma | Media | Mantener catálogo sembrado y abrir pregunta de negocio para el módulo de beneficiarios (sección 7) |
| H-08 | Catálogos sin unicidad explícita (`codigo`, `nombre`, número de identidad, número de expediente, NIT) | Alta | Definir constraints UNIQUE obligatorios en todas las tablas (RN-008) |
| H-09 | Sin trazabilidad: no existen campos de auditoría, bitácoras ni borrado lógico | Alta | Agregar `created_by/updated_by`, soft deletes, `activity_log` e historial de estados (RF-AUD-*) |
| H-10 | `estado` del expediente como texto libre | Media | Enum PHP respaldado por `VARCHAR(20)` + `CHECK` y matriz de transiciones (sección 2.4) |
| H-11 | `año: string(4)` en Base legal redundante con `fecha de emision` | Baja | Mantener como columna derivada e indexada para búsqueda documental |
| H-12 | Catálogos heterogéneos: algunos con `codigo`, otros solo `nombre` (Nivel educacional, Raza, Cargo, Régimen) | Baja | Estandarizar: PK surrogate + `UNIQUE(name)`; `code` solo donde exista en el dominio |
| H-13 | Redundancia geográfica: Oficina/Entidad/Agencia guardan provincia y municipio (el municipio ya determina la provincia) | Baja | Mantener ambas columnas por conveniencia de consulta, con validación de coherencia (RN-004) |
| H-14 | Errores tipográficos de nomenclatura (`propovente`, `Firma atorizada`, `Cliclo`, `clicos`, `nivel eduacional`) | Baja | Corregir semántica en español y aplicar naming inglés consistente (glosario) |
| H-15 | `Registro de servicio` sin control de solapamientos ni orden cronológico | Media | Validación de rangos y solapamientos a nivel de aplicación (RF-EXP-004) |
| H-16 | Falta entidad de pago periódico: `Tipo de pago` y `Conceptos de ingreso` no tienen transacciones asociadas | Media | Proponer `pension_payments` (marcado como propuesta, RF-PAG-005, pendiente de validación) |

### 3.3 Brechas funcionales derivadas

Del análisis anterior se derivan requisitos nuevos que el modelo original no contemplaba: numeración secuencial concurrente de controles bancarios (H-06), historial de estados del expediente (H-09/H-10), alta automática del pensionado con trazabilidad al expediente de origen (H-04), vinculación de la resolución legal a la aprobación (H-05), validación del número de identidad cubano (H-08), coherencia geográfica y jerárquica (H-13/H-15) y el subconjunto transversal de seguridad, auditoría, reportes y API REST que estructura los módulos SEG, AUD, REP y API. Estos requisitos se integran en el catálogo de la sección 4 con origen `DA`.

---

## 4. Requisitos funcionales por módulo

### 4.1 Módulo CAT — Catálogos y datos maestros

**RF-CAT-001 (M) — Gestión de catálogos simples (MO)**
El sistema permite crear, consultar, modificar y desactivar los catálogos simples con código y nombre: provincias, tipos de agencia, organismos, categorías científicas, categorías ocupacionales, tipos de pensión, tipos de entidad, tipos de oficina y tipos de base legal.
- [ ] Alta, edición, listado paginado y detalle por catálogo con los campos del modelo original.
- [ ] El código es único por catálogo y no modificable tras la creación (identificador estable de integración).
- [ ] La eliminación es lógica (desactivación) y queda bloqueada cuando existan referencias activas.

**RF-CAT-002 (M) — Gestión de municipios (MO)**
- [ ] Cada municipio pertenece a una provincia, salvo el municipio especial (Isla de la Juventud) con provincia nula.
- [ ] El par código-provincia es único; dos municipios de distintas provincias pueden compartir código.
- [ ] Listado filtrable por provincia y búsqueda por nombre.

**RF-CAT-003 (M) — Gestión de agencias (MO)**
- [ ] Agencia con código único, nombre, provincia, municipio y tipo de agencia obligatorios.
- [ ] El municipio debe pertenecer a la provincia declarada (RN-004).

**RF-CAT-004 (M) — Carga inicial de catálogos (MO/DA)**
- [ ] Seeders idempotentes con 15 provincias y 168 municipios de Cuba (incluido el municipio especial Isla de la Juventud).
- [ ] Seeders de organismos de la Administración Central del Estado y catálogos clasificatorios de referencia (razas, niveles educacionales, categorías ocupacionales y científicas, tipos de pensión, regímenes, tipos de pago, conceptos de ingreso).
- [ ] Los seeders son actualizables sin duplicar filas (upsert por clave natural).

**RF-CAT-005 (M) — Configuración general versionada (MO + DA H-06)**
El sistema administra los parámetros de cálculo: años mínimos de trabajo, edad mínima por sexo, por ciento de cálculo base, por ciento máximo e incremento anual.
- [ ] Cada conjunto de parámetros tiene fecha de entrada en vigor (`effective_from`).
- [ ] No se permiten dos configuraciones vigentes en el mismo rango; la edición crea una nueva versión, no muta la histórica.
- [ ] Cualquier cálculo registra qué versión de configuración usó (trazabilidad, RF-CAL-008).
- [ ] El contador de control bancario se gestiona fuera de la configuración, vía secuencias (RF-PAG-006).

**RF-CAT-006 (S) — Búsqueda y filtrado transversal (DA)**
- [ ] Todos los listados soportan paginación, ordenamiento por columnas clave y filtro por texto.
- [ ] El tiempo de respuesta de listados de catálogos no supera lo exigido en RNF-002.

### 4.2 Módulo PER — Personas

**RF-PER-001 (M) — Registro de personas (MO)**
- [ ] Campos obligatorios: número de identidad, primer nombre, primer apellido, sexo, fecha de nacimiento y dirección.
- [ ] El número de identidad valida 11 dígitos, mes (dígitos 3-4) y día (dígitos 5-6); el sexo declarado se contrasta con la paridad del dígito 10 del carné (par masculino, impar femenino). Corregido 2026-09-30 (ADR-30, regla `CubanIdentityNumber`): el dígito verificador sigue diferido a P-08.
- [ ] El número de identidad es único en el sistema.
- [ ] Sexo restringido a `M`/`F`; fecha de muerte, si existe, posterior a la de nacimiento.

**RF-PER-002 (M) — Modificación con auditoría (MO + DA H-09)**
- [ ] Toda modificación registra usuario, fecha y valores previos en bitácora (RF-AUD-001).
- [ ] El número de identidad no es editable tras la creación (identidad estable).

**RF-PER-003 (M) — Registro de fallecimiento (MO)**
- [ ] Al fijar la fecha de muerte se bloquean nuevos trámites activos de la persona.
- [ ] El sistema advierte si la persona tiene pensión activa y orienta la baja (RF-PEN-003).
- [ ] El fallecimiento es datable y auditable (quién y cuándo lo registró).

**RF-PER-004 (M) — Búsqueda de personas (MO)**
- [ ] Búsqueda por prefijo de número de identidad (patrón ci_buscado%, 1 a 11 dígitos: acota con cada dígito tecleado y 11 dígitos equivale a la búsqueda exacta) y por combinación de nombres/apellidos.
- [ ] Resultados paginados con datos suficientes para desambiguar homónimos (fecha de nacimiento, padres).

**RF-PER-005 (S) — Control de duplicados (DA H-08)**
- [ ] El sistema detecta intentos de alta con número de identidad existente y devuelve la persona registrada.
- [ ] La ficha única de ciudadano, si se declara, es única y opcional.

### 4.3 Módulo ENT — Entidades, oficinas y firmas

**RF-ENT-001 (M) — Gestión de entidades (MO)**
- [ ] Entidad con código y NIT únicos, organismo, tipo de entidad, ubicación geográfica, dirección y datos de contacto.
- [ ] Directores (general y económico) referencian personas registradas; son modificables con auditoría.
- [ ] Jerarquía opcional `entidad superior` autorreferenciada.

**RF-ENT-002 (M) — Gestión de oficinas (MO)**
- [ ] Oficina con tipo, provincia, municipio, dirección y `oficina superior` opcional.
- [ ] Las jerarquías (entidades y oficinas) no pueden contener ciclos (RN-003).
- [x] Estructura territorial (ADR-31, corrección de usuario): solo se puede adicionar UNA oficina de tipo nacional.
- [x] Solo se puede adicionar una oficina de tipo provincial por provincia.
- [x] Solo se puede adicionar una oficina de tipo municipal por provincia y municipio.
- [x] Las oficinas de tipo municipal llevan `parent_office_id` igual al de la oficina provincial de la misma provincia (derivado; una contradicción responde 422).
- [x] Las oficinas de tipo provincial llevan `parent_office_id` igual al de la oficina de tipo nacional (derivado; una contradicción responde 422).
- [x] Para insertar una oficina provincial debe existir la nacional; para insertar una municipal debe existir la provincial de la provincia correspondiente (422 si falta).
- [x] Seeder que inserta la oficina de tipo nacional al inicio de la aplicación (`NationalOfficeSeeder`, idempotente).

**RF-ENT-003 (M) — Firmas autorizadas (MO)**
- [ ] Firma autorizada vincula entidad, persona y cargo; la terna es única.
- [ ] Vigencia opcional por fechas; el sistema permite versionar firmas históricas de la entidad.
- [ ] No se puede eliminar una firma con registros que la referencian (soft delete).

**RF-ENT-004 (M) — Coherencia geográfica (DA H-13)**
- [ ] En entidades, oficinas y agencias, el municipio debe pertenecer a la provincia declarada.

**RF-ENT-005 (S) — Consulta de estructura (DA)**
- [ ] Vista de árbol de la jerarquía de entidades y de oficinas con profundidad razonable (máx. 5 niveles).
- [ ] Conteo de expedientes tramitados por oficina en su ámbito.

### 4.4 Módulo LEG — Base legal

**RF-LEG-001 (M) — Gestión de tipos de base legal (MO)**
- [ ] CRUD de tipos con código único (Ley, Decreto, Resolución, Indicación, etc.).

**RF-LEG-002 (M) — Registro de bases legales (MO)**
- [ ] Base legal con tipo, número, fechas de emisión y puesta en vigor, organismo emisor, año y referencia.
- [ ] La fecha de derogación es opcional; si existe, es posterior a la puesta en vigor.
- [ ] La terna tipo-número-año es única; el año se deriva de la fecha de emisión (H-11).

**RF-LEG-003 (M) — Control de bases vigentes (DA)**
- [ ] Al aprobar un expediente, el selector de base legal solo ofrece las vigentes (no derogadas).
- [ ] Si un usuario con permiso elevado fuerza una derogada, el evento queda en bitácora con advertencia.

**RF-LEG-004 (S) — Consulta documental (MO)**
- [ ] Búsqueda por año, tipo, organismo emisor y texto de referencia; listado paginado.

### 4.5 Módulo EXP — Expedientes

**RF-EXP-001 (M) — Creación del expediente (MO) — reglas de usuario 0-5, ADR-32/33/34**
- [ ] Expediente con número único COMPUESTO generado por el sistema: once dígitos CONTIGUOS en cuatro secciones — dos del código de provincia de la oficina que registra, dos del código de su municipio, dos de los últimos dígitos del año en curso y cinco del consecutivo del año, provincia y municipio, rellenado con ceros a la izquierda (PPMMAACCCCC) —, con el consecutivo persistido en una tabla propia por AÑO, PROVINCIA Y MUNICIPIO que se incrementa con cada número emitido.
- [ ] El expediente ASUME la oficina del usuario que lo registra: `office_id` no se envía en el POST (rechazado con 422 si llega; 422 si el actor no tiene oficina o su oficina está desactivada).
- [ ] Datos del proponente (persona), oficina tramitadora, centro de trabajo, cargo, categoría ocupacional, nivel educacional, categoría científica, TIPO de pensión, RÉGIMEN de pensión y último salario.
- [ ] Clasificación de Ejército Rebelde: booleano «pertenece» y fecha de alta, la cual es OBLIGATORIA cuando el booleano es true y se rechaza cuando es false.
- [ ] El último salario es no negativo y se expresa con dos decimales.
- [ ] El listado de expedientes devuelve la proyección COMPLETA de la persona promovente (todos sus campos), no un resumen.

**RF-EXP-002 (M) — Subregistro de salarios (MO, regla de usuario 1)**
- [ ] Registro anual de salario devengado por expediente; el par expediente-año es único.
- [ ] Se registran como máximo QUINCE salarios por expediente (el 16º responde 422; eliminar una fila libera el cupo).
- [ ] El año es válido (rango configurable, p. ej. 1950–actual+1) y el importe no negativo.
- [ ] Se advierte ante años consecutivos ausentes en la serie declarada.

**RF-EXP-002b (M) — Subregistro de conceptos de ingreso (MO, regla de usuario 5)**
- [ ] Cada expediente declara conceptos de ingreso del catálogo con un VALOR decimal exacto (dos decimales, no negativo).
- [ ] El par expediente-concepto es único: un valor por concepto (422 semántico).
- [ ] El concepto debe existir y estar activo; las altas y bajas solo en `submitted` como el resto de los subregistros.
- [ ] Los conceptos viajan anidados en la creación del expediente (todo o nada) y con endpoints propios de alta/baja.

**RF-EXP-003 (M) — Subregistro de servicios (MO)**
- [ ] Cada servicio declara entidad, fecha de inicio, fecha de fin opcional y marcador de coletilla.
- [ ] La fecha de fin, si existe, es posterior o igual a la de inicio.
- [ ] El sistema detecta solapamientos de períodos dentro del expediente y servicios sin cerrar con fecha de fin.

**RF-EXP-004 (M) — Subregistro de ciclos (MO)**
- [ ] Ciclo con días plan, días reales y cantidad de ciclos, todos enteros no negativos.
- [ ] Los ciclos participan en el cómputo de años de servicio según el régimen (RF-CAL-002).

**RF-EXP-005 (M) — Máquina de estados (MO + DA H-10)**
- [ ] Solo se permiten las transiciones de la sección 2.4; cualquier otra recibe error 422 y queda en bitácora.
- [ ] Cada transición exige permiso del rol correspondiente (el paso a aprobado es exclusivo del rol Director).
- [ ] Cada cambio de estado persiste `from_status`, `to_status`, usuario, fecha y nota en el historial.

**RF-EXP-006 (M) — Revisión con validación de completitud (MO)**
- [ ] El paso a `under_review` valida la existencia de: persona, centro de trabajo, al menos un servicio, la serie salarial y último salario.
- [ ] El sistema calcula la elegibilidad preliminar y la muestra al especialista sin persistir (RF-CAL-006).

**RF-EXP-007 (M) — Aprobación (MO + DA H-05)**
- [ ] El paso a `approved` exige base legal vigente y resultado de cálculo persistido.
- [ ] Se registran fecha de decisión, usuario decisor y nota de resolución.
- [ ] La aprobación dispara la alta del pensionado en la misma transacción de base de datos (RF-PEN-001).

**RF-EXP-008 (M) — Denegación (MO)**
- [ ] El paso a `rejected` exige motivo obligatorio de longitud mínima.
- [ ] La denegación registra usuario, fecha y motivo, y es estado terminal.

**RF-EXP-009 (M) — Historial de estados (DA H-09)**
- [ ] El expediente expone su bitácora completa de transiciones con usuario, fecha, estado previo/nuevo y notas.
- [ ] El historial es inmutable (append-only) y consultable por roles con permiso de auditoría.

**RF-EXP-010 (S) — Reapertura administrativa (DA)**
- [ ] El rol Administrador puede revertir `approved`/`rejected` a `under_review` como excepción auditada.
- [ ] La reapertura exige motivo y bloquea pagos nuevos del pensionado afectado hasta re-resolución.

**RF-EXP-011 (M) — Búsqueda y filtros (MO)**
- [ ] Listado filtrable por estado, oficina, persona, rango de fechas de solicitud y número.
- [ ] Exportación CSV del resultado filtrado (ver RF-REP-004).

### 4.6 Módulo CAL — Cálculo de pensión

**RF-CAL-001 (M) — Verificación de elegibilidad (MO)**
- [ ] Validación de edad mínima por sexo contra la configuración vigente a la fecha de solicitud.
- [ ] Validación de años mínimos de trabajo computados desde servicios y ciclos (RF-CAL-002).
- [ ] El resultado de elegibilidad es booleano por criterio, con explicación legible de cada fallo.

**RF-CAL-002 (M) — Cómputo de años de servicio (MO + DA H-15)**
- [ ] Los años de servicio se derivan de la sumatoria de intervalos de `service_records` (fecha fin o fecha actual si está abierto).
- [ ] Los `work_cycles` se convierten a tiempo efectivo mediante los meses por año del régimen del expediente.
- [ ] Los servicios marcados como coletilla se integran al cómputo conforme al criterio parametrizado del régimen.
- [ ] El cómputo es determinista y reproducible: mismas entradas, mismo resultado.

**RF-CAL-003 (M) — Salario promedio (MO)**
- [ ] Promedio de los salarios devengados registrados en el expediente, en `DECIMAL(12,2)` y redondeo bancario a 2 decimales.
- [ ] La política de selección de años a promediar es parámetro del régimen (ver supuestos, sección 7).

**RF-CAL-004 (M) — Cuantía base con topes e incrementos (MO)**
- [ ] Cuantía = salario promedio × (por ciento base + incremento anual × años excedentes), topeada por el por ciento máximo.
- [ ] Los años excedentes son el tiempo de servicio que supera el mínimo configurado.
- [ ] El tope y el incremento provienen de la configuración vigente; nunca se hardcodean.

**RF-CAL-005 (S) — Conceptos de ingreso (MO)**
- [ ] Los conceptos con `aplica salario base` participan del cálculo del salario base de referencia.
- [ ] El detalle de aplicación de conceptos se documenta como invariante de cálculo y es testeable.

**RF-CAL-006 (M) — Simulación sin persistencia (DA)**
- [ ] Vista previa del cálculo para un expediente en `submitted`/`under_review` sin escribir resultados.
- [ ] La simulación muestra elegibilidad, años de servicio, promedio, cuantía y advertencias.

**RF-CAL-007 (M) — Persistencia del cálculo (MO)**
- [ ] Al aprobar, el expediente persiste cuantía, parámetros usados, versión de configuración, usuario y fecha.
- [ ] Un expediente aprobado conserva inmutable el cálculo que lo resolvió.

**RF-CAL-008 (M) — Trazabilidad del motor (DA H-05/H-09)**
- [ ] Cada ejecución persistente registra las entradas y la versión de `general_settings` utilizada.
- [ ] La re-ejecución histórica sobre los mismos datos reproduce exactamente la cuantía original.

### 4.7 Módulo PEN — Pensionados

**RF-PEN-001 (M) — Alta automática desde aprobación (MO + DA H-04)**
- [ ] La aprobación del expediente crea el pensionado en la misma transacción: persona, tipo y régimen de pensión y cuantía calculada.
- [ ] El pensionado referencia el expediente de origen (`origin_case_id`) de forma única.
- [ ] Una persona solo puede tener un pensionado activo; el alta falla si ya existe.

**RF-PEN-002 (M) — Consulta y ficha del pensionado (MO)**
- [ ] Ficha con datos de persona, tipo y régimen, cuantía, expediente de origen, historial de estados y controles bancarios.
- [ ] Listados filtrables por provincia del control bancario, tipo de pensión, régimen y estado.

**RF-PEN-003 (M) — Ciclo de vida de la pensión (DA)**
- [ ] Estados del pensionado: activo, suspendido y terminado, con motivo obligatorio en cada transición.
- [ ] El registro de fallecimiento (RF-PER-003) sugiere la terminación de la pensión activa.
- [ ] Toda transición queda en bitácora con usuario, fecha y motivo.

**RF-PEN-004 (S) — Reclasificación (DA)**
- [ ] El rol Director puede reclasificar tipo/regimen de un pensionado activo dejando constancia del expediente o resolución que la respalda.

### 4.8 Módulo PAG — Pagos y control bancario

**RF-PAG-001 (M) — Registro de control bancario (MO)**
- [ ] Control bancario con número secuencial único, agencia, cuenta y marcador de nómina electrónica.
- [ ] El número se asigna desde la secuencia centralizada, segura ante concurrencia (RF-PAG-006).
- [ ] La cuenta valida el formato bancario declarado por el catálogo de agencias.

**RF-PAG-002 (M) — Asignación al pensionado (MO)**
- [ ] Un pensionado tiene exactamente un control bancario activo; los anteriores permanecen como histórico.
- [ ] El cambio de agencia o cuenta desactiva el control previo y crea uno nuevo con nuevo número.
- [ ] El histórico de controles es consultable en la ficha del pensionado.

**RF-PAG-003 (M) — Catálogos del pago (MO)**
- [ ] Gestión de tipos de pago y conceptos de ingreso conforme al modelo original.

**RF-PAG-004 (S) — Exportación de nómina electrónica (MO)**
- [ ] Generación del archivo de nómina electrónica por agencia y período para el consumo del banco.
- [ ] El formato del archivo es configurable y queda registrado como entregable pendiente de especificación bancaria (sección 7).

**RF-PAG-005 (C) — Registro de pagos periódicos (DA H-16, propuesta)**
- [ ] Generación mensual de pagos por pensionado activo con estado pendiente/pagado/anulado.
- [ ] Conciliación básica de pagos por período y agencia. Marcado como propuesta a validar con el área funcional.

**RF-PAG-006 (M) — Secuencias centralizadas (DA H-06)**
- [ ] Tabla genérica de secuencias con incremento transaccional y bloqueo pesimista.
- [ ] Los números emitidos nunca se reutilizan, ni siquiera tras rollback de negocio (huecos permitidos).

### 4.9 Módulo SEG — Seguridad y control de acceso

**RF-SEG-001 (M) — Autenticación (MO implícito)**
- [ ] Login con email y contraseña hasheada (bcrypt/argon2id) y sesiones o tokens Sanctum.
- [ ] Bloqueo temporal tras N intentos fallidos y desbloqueo por el Administrador.
- [ ] Política de contraseñas: longitud mínima, complejidad, caducidad opcional y renovación.

**RF-SEG-002 (M) — RBAC (MO implícito + DA)**
- [ ] Roles del sistema: administrador, director, especialista, operador y auditor.
- [ ] Permisos por módulo y acción (ver, crear, editar, aprobar, exportar) asignables a roles.
- [ ] Las políticas se aplican en capa de dominio, no solo en la interfaz (defense in depth).

**RF-SEG-003 (M) — Restricción por estado (DA)**
- [ ] Las acciones sobre expedientes se habilitan según estado y rol (matriz de la sección 2.4).
- [ ] El ámbito territorial del usuario (oficina) filtra los expedientes visibles según su jerarquía.

**RF-SEG-004 (M) — Asociación usuario-persona (DA)**
- [ ] Todo usuario del sistema puede vincularse a una persona registrada para la trazabilidad de acciones.

### 4.10 Módulo AUD — Auditoría y trazabilidad

**RF-AUD-001 (M) — Bitácora de acciones (DA H-09)**
- [ ] Registro automático de crear/editar/eliminar en entidades críticas con usuario, fecha, valores previos y nuevos.
- [ ] La bitácora es append-only: ningún rol, incluido el Administrador, puede modificarla.

**RF-AUD-002 (M) — Historial del expediente (DA H-10)**
- [ ] Bitácora de transiciones de estado conforme RF-EXP-009, integrada a la consulta de auditoría.

**RF-AUD-003 (M) — Consulta de bitácoras (DA)**
- [ ] Filtros por usuario, entidad, acción, módulo y rango de fechas; exportación CSV para el Auditor.

**RF-AUD-004 (M) — Borrado lógico y restauración (DA)**
- [ ] Soft deletes en entidades de negocio; restauración exclusiva del Administrador y auditada.

### 4.11 Módulo REP — Reportes

**RF-REP-001 (M) — Reportes de expedientes (DA)**
- [ ] Expedientes por estado y oficina en un rango de fechas, con totales y desglose por oficina subordinada.

**RF-REP-002 (M) — Reportes de pensionados (DA)**
- [ ] Pensionados por provincia (según control bancario activo), tipo de pensión y régimen.
- [ ] Monto total de cuantías por los mismos ejes de análisis.

**RF-REP-003 (S) — Reportes de pagos (DA)**
- [ ] Controles bancarios por agencia y marcador de nómina electrónica; pagos del período si RF-PAG-005 se aprueba.

**RF-REP-004 (M) — Exportaciones (DA)**
- [ ] Todo reporte exporta a CSV y Excel; PDF para fichas de expediente y pensionado.

**RF-REP-005 (C) — Tablero de indicadores (DA)**
- [ ] Indicadores de carga por oficina, tiempo medio de tramitación y tasa de aprobación.

### 4.12 Módulo API — Interfaz REST

**RF-API-001 (M) — API REST versionada (DA)**
- [ ] Recursos JSON bajo `/api/v1` con autenticación por token (Sanctum).
- [ ] Cobertura completa de las operaciones de los módulos PER, ENT, LEG, EXP, CAL, PEN y PAG.

**RF-API-002 (M) — Convenciones de respuesta (DA)**
- [ ] Colecciones paginadas con metadatos de paginación estándar.
- [ ] Errores estructurados conforme a RFC 9457 (problem+json) con código, título y detalle.

**RF-API-003 (M) — Idempotencia y concurrencia (DA)**
- [ ] Creación de recursos protegida contra doble envío (llave de idempotencia opcional).
- [ ] Actualizaciones concurrentes detectadas mediante `updated_at` (optimistic locking).

**RF-API-004 (S) — Documentación (DA)**
- [ ] Especificación OpenAPI generada y publicada en el entorno de desarrollo.

---

## 5. Requisitos no funcionales

| ID | Categoría | Requisito | Criterio de verificación |
|---|---|---|---|
| RNF-001 | Rendimiento | Respuesta de APIs transaccionales ≤ 500 ms (p95) con 100 usuarios concurrentes | Prueba de carga en staging |
| RNF-002 | Rendimiento | Listados y reportes paginados ≤ 2 s (p95) sobre volúmenes objetivo (500k personas, 200k expedientes) | Prueba de carga + EXPLAIN de consultas |
| RNF-003 | Disponibilidad | Disponibilidad ≥ 99,5 % en horario laboral (7×12) | Monitoreo de uptime |
| RNF-004 | Seguridad | Hashing Argon2id/bcrypt, rate limiting, validación de entrada en servidor, protección OWASP Top 10 | Revisión de seguridad + suite de tests |
| RNF-005 | Auditoría | Ningún dato crítico se modifica sin registro de autor, fecha y valores previos | Tests de integración de bitácora |
| RNF-006 | Portabilidad | Desplegable on-premise vía Docker/Compose sin servicios externos de internet | Despliegue limpio en VM del Ministerio |
| RNF-007 | Mantenibilidad | Cobertura ≥ 90 % en dominio/aplicación y ≥ 80 % global; PHPStan nivel 8 sin errores | Pipeline de CI |
| RNF-008 | Integridad | Dinero siempre `DECIMAL(12,2)`; ninguna operación financiera con coma flotante | Inspección de esquema + tests |
| RNF-009 | Respaldos | Respaldo diario completo + binlog; RPO ≤ 24 h, RTO ≤ 4 h | Simulacro de restauración mensual |
| RNF-010 | Trazabilidad | Reproducibilidad del cálculo de cualquier pensión histórica | Tests de regresión con datasets congelados |

## 6. Reglas de negocio transversales

| ID | Regla |
|---|---|
| RN-001 | El número de identidad cubano (11 dígitos) valida 11 dígitos, mes (dígitos 3-4) y día (dígitos 5-6); el sexo se codifica en el dígito 10 (par masculino, impar femenino) y se contrasta con el declarado; es único e inmutable (dígito verificador: P-08) |
| RN-002 | Sexo restringido a `M`/`F`; toda regla sensible al sexo deriva de la configuración vigente |
| RN-003 | Las jerarquías de entidades y oficinas son acíclicas; un nodo no puede ser ascendiente de sí mismo |
| RN-004 | En toda entidad con provincia y municipio, el municipio pertenece a la provincia |
| RN-005 | Todo importe monetario se almacena y calcula en `DECIMAL(12,2)` con redondeo bancario |
| RN-006 | Toda fecha de fin es posterior o igual a su fecha de inicio (servicios, vigencias, vigencias legales) |
| RN-007 | La configuración general vigente es única por fecha; los cálculos congelan la versión usada |
| RN-008 | Toda clave natural declarada única (códigos, NIT, número de expediente, número de control) se garantiza con constraint de base de datos, no solo en aplicación |
| RN-009 | Los números de secuencia (expediente, control bancario) jamás se reutilizan |
| RN-010 | La bitácora y el historial de estados son append-only |

## 7. Supuestos y preguntas abiertas

Durante el análisis se identificaron puntos que requieren validación del área funcional del Ministerio antes o durante la fase de cálculo. Ninguno bloquea el arranque del desarrollo porque el diseño los parametriza, pero sí condiciona los datos de prueba de aceptación.

| ID | Pregunta abierta | Impacto si cambia | Estrategia de contención |
|---|---|---|---|
| P-01 | Política exacta de promedio salarial (todos los años registrados, últimos N, o mejores N) | Motor de cálculo (estrategia) | Estrategia intercambiable por configuración/regla del régimen |
| P-02 | Semántica de `meses por año` del régimen: valores > 12 posibles (regímenes especiales) y dirección del ajuste | Cómputo de años de servicio | CHECK amplio y validación funcional con datasets reales |
| P-03 | Tratamiento del marcador coletilla en el cómputo (sí suma tiempo o solo lo documenta) | Cómputo de años de servicio | Bandera paramétrica por régimen hasta confirmación |
| P-04 | Funcionalidad futura del catálogo `Tipo de beneficiario` (pensión de sobrevivencia a terceros) | Nuevo submódulo de beneficiarios y pagos repartidos | Catálogo sembrado y modelo preparado para extensión |
| P-05 | Formato del archivo de nómina electrónica exigido por cada agencia | Exportación (RF-PAG-004) | Generador de archivos con plantillas configurables |
| P-06 | Catálogos oficiales definitivos (razas, niveles educacionales, categorías, tipos de pensión, regímenes vigentes) | Seeders y pruebas de aceptación | Seeders versionables en el repositorio, ajustables sin migraciones |
| P-07 | Volumen real de datos históricos a migrar y fuente autorizada | Plan de fases 7 | Migración como proyecto aparte, acotada al inicio de UAT |
| P-08 | Algoritmo oficial del dígito verificador del carnet de identidad (posición 11); no existe fuente pública verificable | Validación de identidad en People (RN-001) | Validación estructural corregida 2026-09-30 (ADR-30: 11 dígitos, mes/día y sexo por paridad del dígito 10 — año y consecutivo sin validar); política de checksum intercambiable cuando el Ministerio confirme la regla |

## 8. Matriz de trazabilidad

Resumen de correspondencia requisito → módulo arquitectónico → tablas principales. El detalle completo de columnas reside en `Modelo de datos.md`.

| Grupo de requisitos | Módulo Laravel | Tablas principales |
|---|---|---|
| RF-CAT-* | Catalogs, Settings | `provinces`, `municipalities`, `agency_types`, `agencies`, `organizations`, `pension_regimes`, `income_concepts`, `general_settings`, `numbering_sequences` |
| RF-PER-* | People | `people`, `races` |
| RF-ENT-* | Organizations | `entity_types`, `entities`, `office_types`, `offices`, `positions`, `authorized_signatures` |
| RF-LEG-* | LegalBasis | `legal_basis_types`, `legal_bases` |
| RF-EXP-* | PensionCases | `pension_cases`, `salary_records`, `service_records`, `work_cycles`, `pension_case_histories` |
| RF-CAL-* | PensionCalculation | consume `pension_cases`, `general_settings`, `pension_regimes`; produce cálculo persistido |
| RF-PEN-* | Pensioners | `pensioners`, `pension_types` |
| RF-PAG-* | Payments | `bank_controls`, `agencies`, `payment_types`, `pension_payments` (propuesta) |
| RF-SEG-* | Security | `users`, `roles`, `permissions` y tablas de relación |
| RF-AUD-* | Security (Auditing) | `activity_log`, `pension_case_histories` |
| RF-REP-* | Reporting | vistas sobre todas las anteriores |
| RF-API-* | Presentation (transversal) | n/a (capa de interfaz) |

## 9. Cambios y control de versiones

| Versión | Fecha | Cambios | Autor |
|---|---|---|---|
| 1.0 | 2026-09-22 | Versión inicial derivada del análisis del modelo conceptual `mdeditor.a8RzJZB8.md` | Arquitectura Backend |


