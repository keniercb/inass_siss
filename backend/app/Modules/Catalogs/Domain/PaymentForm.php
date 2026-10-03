<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Domain;

/**
 * Forma de pago del cobro (Task 42, corrección de usuario, SGP-36):
 * el enum fija los DOS valores legales escritos exactamente como el
 * usuario los fijó — minúsculas unificadas, sin tildes — porque la
 * columna viaja respaldada por CHECK y el wire viaja `in:` con los
 * mismos literales.
 *
 * El valor decide la exigencia de la cuenta bancaria del cobro del
 * expediente (RF-EXP-001): con TarjetaMagnetica la cuenta es
 * OBLIGATORIA (422 sobre bank_account cuando falta), con
 * NominaElectronica queda OPCIONAL (la omisión persiste NULL;
 * proveerla se permite).
 */
enum PaymentForm: string
{
    case TarjetaMagnetica = 'tarjeta magnetica';

    case NominaElectronica = 'nomina electronica';
}
