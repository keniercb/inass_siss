## Intruccion

El presente documento tiene como objetivo definir los modelos necesarios para el desarrollo de un sistema de atencion a pensionados para el Ministerio de trabajo.

## Catalogos:

- Provincia

  - codigo: string
  - nombre: string

- Municipio

  - codigo: string
  - nombre:string
  - provincia: Provincia
    
- Tipo de agencia
  - codigo: string
  - nombre: string
    
- Agencia
  - codigo: string
  - nombre: string
  - provincia: Provincia
  - municipio: Municipio
  - tipo de agencia: Tipo de agencia

- Organismo
  - codigo: string
  - nombre: string

- Categoria cientifica
  - codigo: string
  - nombre: string
  - 
- Nivel educacional
  - nombre
    
- Categoria ocupacional
  - codigo: string
  - nombre: string
    
- Tipo de pension
  - codigo: string
  - nombre: string
    
- Tipo de beneficiario
  - nombre: string
  - descripcion: string

- Raza
  - nombre : string
  
- Persona
  - numero de identidad: string
  - primer nombre: string
  - segundo nombre: string
  - primer apellido: string
  - segundo apellido: string
  - sexo: string
  - raza: Raza
  - direccion: string
  - fecha de nacimiento: Date
  - fecha de muerte: Date
  - nombre del padre: string
  - nombre de la madre: string
  - Identificador de ficha unica de ciudadano: string

- Cargo
 - nombre: string
 - descripcion: string

- Regimen de pension
  - nombre: string
  - meses por año: Integer

- Tipo de pago 
  - nombre: string
  - descripcion: string

- Conceptos de ingreso
  - nombre: string
  - descripcion: string
  - aplica salario base: Boolean

- Configuracion general:
  - años de trabajo minimos: Integer
  - edad minima hombres: Integer
  - edad minima mujeres: Integer
  - por ciento de calculo base: Integer
  - por ciento de calculo maximo: Integer
  - por ciento de incremento anual: Integer
  - ultimo control bancario: Big Integer

## Entidades
- Tipo de entidad
  - codigo : string
  - nombre : string

- Tipo de oficina
  - codigo: string
  - nombre: string

- Oficina
  - tipo de oficina: Tipo de oficina
  - provincia: Provincia
  - municipio: Municipio
  - direccion: string
  - oficina superior: Oficina(Admite nulo)

- Entidad
 - codigo: string
 - numero de identificacion tributaria: string
 - organismo: Organismo
 - provincia: Provincia
 - municipio: Municipio
 - tipo de entidad: Tipo de entidad 
 - direccion: string
 - telefono: string
 - fax: string
 - email: string
 - director: Persona
 - director economico: Persona
 - entidad superior: (Entidad admite nulo)
 - objeto social: string

- Firma atorizada
  - entidad: Entidad
  - persona: Persona
  - cargo: Cargo

## Base legal
- Tipo de base legal
 - codigo: string
 - nombre: string

- Base legal
  - tipo: Tipo de base legal
  - numero: string
  - fecha de emision: Date
  - fecha de puesta en vigor: Date
  - fecha de derogacion: Date
  - organismo emisor: Organismo
  - año: string(4)
  - referencia: string

## Pensiones
- Expediente
  - numero: string
  - fecha de solicitud: Date
  - estado :  Enum(Solicitud, Revision, Aprobado, Denegado) 
  - propovente: Persona
  - oficina: Oficina
  - centro de trabajo: Entidad
  - cargo: Cargo
  - categoria ocupacional: Categoria ocupacional
  - nivel eduacional: Nivel educacional
  - categoria cientifica: Categoria cientifica
  - ultimo salario : Double
  
- Cliclo
  - expediente: Expediente
  - dias plan: Integer
  - dias reales: Integer
  - cantidad de clicos: Integer

- Registro de salarios
  - expediente: Expediente
  - año: Integer(4)
  - salario devengado: Double

- Registro de servicio
 - expediente: Expediente
 - entidad: Entidad
 - fecha de inicio: Date
 - fecha de fin: Date
 - coletilla: Boolean

- Pensionado
 - persona: Persona
 - tipo de pension: Tipo de pension
 - regimen de pension: Regimen de pension
 - cuantia: int

- Control Bancario
  - numero: Big Integer
  - agencia: Agencia
  - cuenta: string
  - nomina electronica: Boolean
  - pensionado: Pensionado