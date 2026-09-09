-- Migración: soporte multi-agente para consulta_saldo.php / debitar.php
-- Contexto: antes, el puerto del tunel SSH reverso del agente estaba
-- hardcodeado a 127.0.0.1:8080 (un solo agente activo). Con dos agentes en
-- paralelo (cbb01, scz01) cada uno necesita su propio puerto remoto en este
-- servidor. Ejecutado manualmente via psql (no via HTTP/CLI PHP: el patron
-- de migrations/*.php existente usa auth_require_login(), que corta la
-- ejecucion bajo CLI por falta de sesion, y el .htaccess ya bloquea HTTP).
--
-- Aplicado: 2026-09-09.

-- UP
ALTER TABLE "Agent" ADD COLUMN IF NOT EXISTS "tunnelPort" integer;
UPDATE "Agent" SET "tunnelPort" = 8080 WHERE "agentId" = 'cbb01';
INSERT INTO "Agent" ("agentId","token","hostname","tunnelPort","updatedAt")
VALUES ('scz01', '<token generado con openssl rand -hex 32, no versionado>', 'scz01', 8081, now());

-- DOWN (rollback manual, no probado en produccion)
-- DELETE FROM "Agent" WHERE "agentId" = 'scz01';
-- ALTER TABLE "Agent" DROP COLUMN IF EXISTS "tunnelPort";
