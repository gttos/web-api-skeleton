<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260506000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Event Sourcing Lab — Crear todos los schemas y tablas';
    }

    public function up(Schema $schema): void
    {
        // Extensión UUID
        $this->addSql('CREATE EXTENSION IF NOT EXISTS "uuid-ossp"');

        // =============================================
        // SCHEMAS
        // =============================================
        $this->addSql('CREATE SCHEMA IF NOT EXISTS order_ctx');
        $this->addSql('CREATE SCHEMA IF NOT EXISTS payment_ctx');
        $this->addSql('CREATE SCHEMA IF NOT EXISTS stock_ctx');
        $this->addSql('CREATE SCHEMA IF NOT EXISTS notification_ctx');
        $this->addSql('CREATE SCHEMA IF NOT EXISTS audit_ctx');
        $this->addSql('CREATE SCHEMA IF NOT EXISTS shared');

        // =============================================
        // ORDER CONTEXT
        // =============================================

        $this->addSql('
            CREATE TABLE order_ctx.event_store (
                sequence_number BIGSERIAL PRIMARY KEY,
                aggregate_id    UUID NOT NULL,
                aggregate_type  VARCHAR(255) NOT NULL,
                event_type      VARCHAR(255) NOT NULL,
                event_version   INTEGER NOT NULL,
                payload         JSONB NOT NULL,
                metadata        JSONB NOT NULL DEFAULT \'{}\'::jsonb,
                correlation_id  UUID NOT NULL,
                causation_id    UUID NOT NULL,
                occurred_at     TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_aggregate_version UNIQUE (aggregate_id, event_version)
            )
        ');
        $this->addSql('CREATE INDEX idx_event_store_aggregate ON order_ctx.event_store (aggregate_id, event_version)');
        $this->addSql('CREATE INDEX idx_event_store_correlation ON order_ctx.event_store (correlation_id)');
        $this->addSql('CREATE INDEX idx_event_store_type ON order_ctx.event_store (event_type)');

        $this->addSql('
            CREATE TABLE order_ctx.snapshots (
                aggregate_id   UUID PRIMARY KEY,
                aggregate_type VARCHAR(255) NOT NULL,
                version        INTEGER NOT NULL,
                state          JSONB NOT NULL,
                created_at     TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW()
            )
        ');

        $this->addSql('
            CREATE TABLE order_ctx.outbox (
                id             UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
                aggregate_id   UUID NOT NULL,
                event_type     VARCHAR(255) NOT NULL,
                payload        JSONB NOT NULL,
                metadata       JSONB NOT NULL DEFAULT \'{}\'::jsonb,
                correlation_id UUID NOT NULL,
                causation_id   UUID NOT NULL,
                created_at     TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW(),
                published_at   TIMESTAMP(6) WITH TIME ZONE NULL
            )
        ');
        $this->addSql('CREATE INDEX idx_outbox_unpublished ON order_ctx.outbox (created_at) WHERE published_at IS NULL');

        $this->addSql('
            CREATE TABLE order_ctx.order_projections (
                order_id    UUID PRIMARY KEY,
                status      VARCHAR(50) NOT NULL,
                customer_id UUID NOT NULL,
                items       JSONB NOT NULL,
                total       DECIMAL(12,2) NOT NULL,
                created_at  TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                updated_at  TIMESTAMP(6) WITH TIME ZONE NOT NULL
            )
        ');
        $this->addSql('CREATE INDEX idx_order_proj_status ON order_ctx.order_projections (status)');
        $this->addSql('CREATE INDEX idx_order_proj_customer ON order_ctx.order_projections (customer_id)');

        // =============================================
        // PAYMENT CONTEXT
        // =============================================

        $this->addSql('
            CREATE TABLE payment_ctx.payments (
                payment_id     UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
                order_id       UUID NOT NULL,
                amount         DECIMAL(12,2) NOT NULL,
                status         VARCHAR(50) NOT NULL,
                correlation_id UUID NOT NULL,
                created_at     TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW(),
                updated_at     TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW()
            )
        ');

        $this->addSql('
            CREATE TABLE payment_ctx.processed_messages (
                message_id     UUID NOT NULL,
                event_id       UUID,
                consumer_name  VARCHAR(255) NOT NULL,
                processed_at   TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW(),
                correlation_id UUID,
                PRIMARY KEY (message_id, consumer_name)
            )
        ');

        // =============================================
        // STOCK CONTEXT
        // =============================================

        $this->addSql('
            CREATE TABLE stock_ctx.stock_reservations (
                reservation_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
                order_id       UUID NOT NULL,
                items          JSONB NOT NULL,
                status         VARCHAR(50) NOT NULL,
                correlation_id UUID NOT NULL,
                created_at     TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW()
            )
        ');

        $this->addSql('
            CREATE TABLE stock_ctx.processed_messages (
                message_id     UUID NOT NULL,
                event_id       UUID,
                consumer_name  VARCHAR(255) NOT NULL,
                processed_at   TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW(),
                correlation_id UUID,
                PRIMARY KEY (message_id, consumer_name)
            )
        ');

        // =============================================
        // NOTIFICATION CONTEXT
        // =============================================

        $this->addSql('
            CREATE TABLE notification_ctx.notification_log (
                notification_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
                order_id        UUID NOT NULL,
                type            VARCHAR(100) NOT NULL,
                channel         VARCHAR(50) NOT NULL,
                status          VARCHAR(50) NOT NULL,
                correlation_id  UUID NOT NULL,
                created_at      TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW()
            )
        ');

        $this->addSql('
            CREATE TABLE notification_ctx.processed_messages (
                message_id     UUID NOT NULL,
                event_id       UUID,
                consumer_name  VARCHAR(255) NOT NULL,
                processed_at   TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW(),
                correlation_id UUID,
                PRIMARY KEY (message_id, consumer_name)
            )
        ');

        // =============================================
        // AUDIT CONTEXT
        // =============================================

        $this->addSql('
            CREATE TABLE audit_ctx.audit_log (
                id             BIGSERIAL PRIMARY KEY,
                event_type     VARCHAR(255) NOT NULL,
                aggregate_id   UUID,
                payload        JSONB NOT NULL,
                correlation_id UUID NOT NULL,
                causation_id   UUID NOT NULL,
                source_context VARCHAR(100) NOT NULL,
                occurred_at    TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                recorded_at    TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW()
            )
        ');
        $this->addSql('CREATE INDEX idx_audit_correlation ON audit_ctx.audit_log (correlation_id)');
        $this->addSql('CREATE INDEX idx_audit_event_type ON audit_ctx.audit_log (event_type)');
        $this->addSql('CREATE INDEX idx_audit_aggregate ON audit_ctx.audit_log (aggregate_id)');

        $this->addSql('
            CREATE TABLE audit_ctx.processed_messages (
                message_id     UUID NOT NULL,
                event_id       UUID,
                consumer_name  VARCHAR(255) NOT NULL,
                processed_at   TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW(),
                correlation_id UUID,
                PRIMARY KEY (message_id, consumer_name)
            )
        ');

        // =============================================
        // SHARED
        // =============================================

        $this->addSql('
            CREATE TABLE shared.dead_letter_store (
                message_id          UUID PRIMARY KEY,
                event_type          VARCHAR(255) NOT NULL,
                consumer_name       VARCHAR(255) NOT NULL,
                payload             JSONB NOT NULL,
                metadata            JSONB NOT NULL DEFAULT \'{}\'::jsonb,
                error_reason        TEXT NOT NULL,
                stack_trace         TEXT,
                correlation_id      UUID,
                causation_id        UUID,
                original_transport  VARCHAR(100) NOT NULL,
                attempts            INTEGER NOT NULL DEFAULT 1,
                failed_at           TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW(),
                last_retry_at       TIMESTAMP(6) WITH TIME ZONE NULL
            )
        ');
        $this->addSql('CREATE INDEX idx_dead_letter_correlation ON shared.dead_letter_store (correlation_id)');
        $this->addSql('CREATE INDEX idx_dead_letter_consumer ON shared.dead_letter_store (consumer_name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP SCHEMA IF EXISTS order_ctx CASCADE');
        $this->addSql('DROP SCHEMA IF EXISTS payment_ctx CASCADE');
        $this->addSql('DROP SCHEMA IF EXISTS stock_ctx CASCADE');
        $this->addSql('DROP SCHEMA IF EXISTS notification_ctx CASCADE');
        $this->addSql('DROP SCHEMA IF EXISTS audit_ctx CASCADE');
        $this->addSql('DROP SCHEMA IF EXISTS shared CASCADE');
    }
}
