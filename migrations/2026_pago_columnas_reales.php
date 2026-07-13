<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
auth_require_login();
require_once __DIR__ . '/../lib/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = db_connect();
    $pdo->beginTransaction();

    // 1. Agregar nuevas columnas
    $pdo->exec('
        ALTER TABLE "pago"
            ADD COLUMN IF NOT EXISTS "dias_programado" INTEGER DEFAULT 30,
            ADD COLUMN IF NOT EXISTS "dias_real"        INTEGER,
            ADD COLUMN IF NOT EXISTS "interes_real"     NUMERIC(15,2),
            ADD COLUMN IF NOT EXISTS "capital_real"     NUMERIC(15,2),
            ADD COLUMN IF NOT EXISTS "saldo_deudor_real" NUMERIC(15,2)
    ');

    // 2. dias_programado = 30 para todos los registros existentes
    $pdo->exec('UPDATE "pago" SET "dias_programado" = 30 WHERE "dias_programado" IS NULL');

    // 3. Backfill valores reales para cuotas pagadas mes=1
    //    Referencia: fecha_prestamo del prestamo
    $pdo->exec('
        UPDATE "pago" p
        SET
            dias_real          = (p.fecha_de_debito::date - pr.fecha_prestamo::date),
            interes_real       = ROUND(
                                    p.saldo_inicial
                                    * (pr.tasa_interes / 100.0 / 30.0)
                                    * (p.fecha_de_debito::date - pr.fecha_prestamo::date),
                                 2),
            capital_real       = ROUND(
                                    GREATEST(0,
                                        p.cuota_fija
                                        - (p.saldo_inicial * (pr.tasa_interes / 100.0 / 30.0)
                                           * (p.fecha_de_debito::date - pr.fecha_prestamo::date))
                                    ),
                                 2),
            saldo_deudor_real  = ROUND(
                                    p.saldo_inicial
                                    - GREATEST(0,
                                        p.cuota_fija
                                        - (p.saldo_inicial * (pr.tasa_interes / 100.0 / 30.0)
                                           * (p.fecha_de_debito::date - pr.fecha_prestamo::date))
                                      ),
                                 2)
        FROM "prestamo" pr
        WHERE p."prestamoIdPrestamo" = pr.id_prestamo
          AND p.estado               IN (\'pagado\', \'parcial\')
          AND p.mes                  = 1
          AND p.fecha_de_debito      IS NOT NULL
    ');

    // 4. Backfill valores reales para cuotas pagadas mes>1
    //    Referencia: fecha_de_debito de la cuota anterior
    $pdo->exec('
        UPDATE "pago" p
        SET
            dias_real          = (p.fecha_de_debito::date - prev.fecha_de_debito::date),
            interes_real       = ROUND(
                                    p.saldo_inicial
                                    * (pr.tasa_interes / 100.0 / 30.0)
                                    * (p.fecha_de_debito::date - prev.fecha_de_debito::date),
                                 2),
            capital_real       = ROUND(
                                    GREATEST(0,
                                        p.cuota_fija
                                        - (p.saldo_inicial * (pr.tasa_interes / 100.0 / 30.0)
                                           * (p.fecha_de_debito::date - prev.fecha_de_debito::date))
                                    ),
                                 2),
            saldo_deudor_real  = ROUND(
                                    p.saldo_inicial
                                    - GREATEST(0,
                                        p.cuota_fija
                                        - (p.saldo_inicial * (pr.tasa_interes / 100.0 / 30.0)
                                           * (p.fecha_de_debito::date - prev.fecha_de_debito::date))
                                      ),
                                 2)
        FROM "prestamo" pr
        JOIN "pago" prev
          ON prev."prestamoIdPrestamo" = p."prestamoIdPrestamo"
         AND prev.mes                  = p.mes - 1
        WHERE p."prestamoIdPrestamo" = pr.id_prestamo
          AND p.estado               IN (\'pagado\', \'parcial\')
          AND p.mes                  > 1
          AND p.fecha_de_debito      IS NOT NULL
          AND prev.fecha_de_debito   IS NOT NULL
    ');

    $pdo->commit();
    echo json_encode([
        'ok'      => true,
        'message' => 'Migración completada: columnas dias_programado, dias_real, interes_real, capital_real, saldo_deudor_real agregadas y backfill aplicado.',
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
