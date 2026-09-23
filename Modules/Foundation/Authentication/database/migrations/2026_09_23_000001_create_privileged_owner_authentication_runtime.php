<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('B5A1B1 requires PostgreSQL.');
        }

        $invalidEmails = DB::table('users')
            ->whereRaw('email IS DISTINCT FROM lower(btrim(email))')
            ->exists();
        $duplicateEmails = DB::table('users')
            ->selectRaw('lower(btrim(email)) AS normalized_email')
            ->groupByRaw('lower(btrim(email))')
            ->havingRaw('count(*) > 1')
            ->exists();

        if ($invalidEmails || $duplicateEmails) {
            throw new RuntimeException('B5A1B1_USER_EMAIL_NORMALIZATION_PREFLIGHT_FAILED');
        }

        DB::statement('ALTER TABLE users ALTER COLUMN password DROP NOT NULL');
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('auth_epoch')->default(0);
        });
        DB::statement('ALTER TABLE users ADD CONSTRAINT chk_users_email_canonical CHECK (email = lower(btrim(email)))');
        DB::statement('CREATE UNIQUE INDEX uq_users_email_canonical ON users (lower(email))');

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->unsignedBigInteger('auth_epoch')->default(0);
            $table->char('property_id', 26)->nullable()->index();
        });
        Schema::table('user_sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('auth_epoch')->default(0);
            $table->char('web_session_digest', 64)->nullable()->unique();
            $table->string('channel', 10)->default('api');
        });

        $this->createOrValidateSessionsTable();

        Schema::create('owner_activations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('status', 32);
            $table->string('environment', 20);
            $table->string('installation_id', 100);
            $table->foreignUlid('first_trust_run_id')->constrained('property_bootstrap_provisioning_runs')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignUlid('user_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignUlid('company_id')->constrained('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete()->restrictOnUpdate();
            $table->string('canonical_email');
            $table->string('pending_role_name', 64)->default('installation-owner');
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamp('invited_at');
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('password_established_at')->nullable();
            $table->timestamp('mfa_enrollment_started_at')->nullable();
            $table->timestamp('mfa_enrolled_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->unique('first_trust_run_id', 'uq_owner_activation_first_trust_run');
            $table->unique('user_id', 'uq_owner_activation_user');
            $table->unique(['environment', 'installation_id'], 'uq_owner_activation_installation');
            $table->unique(['property_id', 'canonical_email'], 'uq_owner_activation_property_email');
        });

        Schema::create('owner_activation_tokens', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('activation_id')->constrained('owner_activations')->restrictOnDelete()->restrictOnUpdate();
            $table->string('environment', 20);
            $table->string('installation_id', 100);
            $table->foreignUlid('user_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignUlid('company_id')->constrained('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete()->restrictOnUpdate();
            $table->string('purpose', 32);
            $table->char('digest', 64)->unique();
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('owner_mfa_factors', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('activation_id')->constrained('owner_activations')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignUlid('user_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->text('encrypted_secret');
            $table->string('state', 16);
            $table->bigInteger('last_accepted_counter')->nullable();
            $table->timestamp('enrollment_started_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('owner_recovery_codes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('factor_id')->constrained('owner_mfa_factors')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignUlid('user_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->unsignedInteger('generation');
            $table->unsignedSmallInteger('ordinal');
            $table->char('digest', 64)->unique();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['factor_id', 'generation', 'ordinal'], 'uq_owner_recovery_factor_generation_ordinal');
        });

        Schema::create('identity_challenges', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('activation_id')->nullable()->constrained('owner_activations')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignUlid('user_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignUlid('company_id')->constrained('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete()->restrictOnUpdate();
            $table->string('purpose', 24);
            $table->string('channel', 10);
            $table->char('digest', 64)->unique();
            $table->char('guest_session_digest', 64)->nullable();
            $table->timestamp('password_verified_at')->nullable();
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(5);
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('credential_issued_at')->nullable();
            $table->timestamps();
        });

        Schema::create('identity_security_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('event_type', 64);
            $table->string('outcome', 16);
            $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignUlid('subject_user_id')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignUlid('company_id')->nullable()->constrained('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignUlid('property_id')->nullable()->constrained('properties')->restrictOnDelete()->restrictOnUpdate();
            $table->ulid('activation_id')->nullable();
            $table->ulid('challenge_id')->nullable();
            $table->string('correlation_id', 100)->nullable();
            $table->string('reason_code', 100)->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();
        });

        DB::statement("ALTER TABLE owner_activations ADD CONSTRAINT chk_owner_activation_status CHECK (status IN ('INVITED','EMAIL_VERIFIED','PASSWORD_ESTABLISHED','MFA_ENROLLING','MFA_ENROLLED','ACTIVE'))");
        DB::statement("ALTER TABLE owner_activations ADD CONSTRAINT chk_owner_activation_environment CHECK (environment IN ('operational','rehearsal'))");
        DB::statement('ALTER TABLE owner_activations ADD CONSTRAINT chk_owner_activation_email CHECK (canonical_email = lower(btrim(canonical_email)))');
        DB::statement("ALTER TABLE owner_activations ADD CONSTRAINT chk_owner_activation_role CHECK (pending_role_name = 'installation-owner')");
        DB::statement("ALTER TABLE owner_activation_tokens ADD CONSTRAINT chk_owner_activation_token_purpose CHECK (purpose IN ('verify_email','resume_activation'))");
        DB::statement("ALTER TABLE owner_activation_tokens ADD CONSTRAINT chk_owner_activation_token_digest CHECK (digest ~ '^[a-f0-9]{64}$')");
        DB::statement("ALTER TABLE owner_mfa_factors ADD CONSTRAINT chk_owner_mfa_factor_state CHECK (state IN ('PENDING','ACTIVE','REVOKED'))");
        DB::statement("ALTER TABLE identity_challenges ADD CONSTRAINT chk_identity_challenge_purpose CHECK (purpose IN ('ACTIVATION','LOGIN_MFA'))");
        DB::statement("ALTER TABLE identity_challenges ADD CONSTRAINT chk_identity_challenge_channel CHECK (channel IN ('web','api','activation'))");
        DB::statement('ALTER TABLE identity_challenges ADD CONSTRAINT chk_identity_challenge_attempts CHECK (max_attempts = 5 AND failed_attempts <= max_attempts)');
        DB::statement("ALTER TABLE identity_security_events ADD CONSTRAINT chk_identity_security_event_outcome CHECK (outcome IN ('SUCCESS','FAILURE'))");

        DB::statement('CREATE UNIQUE INDEX uq_owner_activation_token_outstanding ON owner_activation_tokens (activation_id, purpose) WHERE consumed_at IS NULL AND revoked_at IS NULL');
        DB::statement("CREATE UNIQUE INDEX uq_owner_mfa_factor_current ON owner_mfa_factors (user_id) WHERE state IN ('PENDING','ACTIVE')");
        DB::statement('CREATE INDEX idx_identity_challenge_lookup ON identity_challenges (digest, purpose)');

        // PostgreSQL functions survive `migrate:fresh` because that command
        // drops tables rather than invoking migration down() methods. Remove
        // only these package-owned helpers so repeated canonical test runs are
        // deterministic.
        DB::statement('DROP FUNCTION IF EXISTS owner_activation_lifecycle_guard() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS identity_security_events_immutable_guard() CASCADE');

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION owner_activation_lifecycle_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'OWNER_ACTIVATION_DELETE_REJECTED' USING ERRCODE = 'P0001';
                END IF;
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'INVITED' OR NEW.invited_at IS NULL THEN
                        RAISE EXCEPTION 'OWNER_ACTIVATION_INITIAL_STATE_INVALID' USING ERRCODE = 'P0001';
                    END IF;
                    RETURN NEW;
                END IF;
                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.environment IS DISTINCT FROM OLD.environment
                    OR NEW.installation_id IS DISTINCT FROM OLD.installation_id OR NEW.first_trust_run_id IS DISTINCT FROM OLD.first_trust_run_id
                    OR NEW.user_id IS DISTINCT FROM OLD.user_id OR NEW.company_id IS DISTINCT FROM OLD.company_id
                    OR NEW.property_id IS DISTINCT FROM OLD.property_id OR NEW.canonical_email IS DISTINCT FROM OLD.canonical_email
                    OR NEW.pending_role_name IS DISTINCT FROM OLD.pending_role_name OR NEW.invited_at IS DISTINCT FROM OLD.invited_at
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'OWNER_ACTIVATION_IDENTITY_IMMUTABLE' USING ERRCODE = 'P0001';
                END IF;
                IF NOT ((OLD.status = 'INVITED' AND NEW.status = 'EMAIL_VERIFIED')
                    OR (OLD.status = 'EMAIL_VERIFIED' AND NEW.status = 'PASSWORD_ESTABLISHED')
                    OR (OLD.status = 'PASSWORD_ESTABLISHED' AND NEW.status = 'MFA_ENROLLING')
                    OR (OLD.status = 'MFA_ENROLLING' AND NEW.status = 'MFA_ENROLLED')
                    OR (OLD.status = 'MFA_ENROLLED' AND NEW.status = 'ACTIVE')) THEN
                    RAISE EXCEPTION 'OWNER_ACTIVATION_TRANSITION_INVALID' USING ERRCODE = 'P0001';
                END IF;
                IF (OLD.status = 'INVITED' AND (NEW.email_verified_at IS NULL
                        OR NEW.password_established_at IS NOT NULL OR NEW.mfa_enrollment_started_at IS NOT NULL
                        OR NEW.mfa_enrolled_at IS NOT NULL OR NEW.activated_at IS NOT NULL))
                    OR (OLD.status = 'EMAIL_VERIFIED' AND (NEW.email_verified_at IS DISTINCT FROM OLD.email_verified_at
                        OR NEW.password_established_at IS NULL OR NEW.mfa_enrollment_started_at IS NOT NULL
                        OR NEW.mfa_enrolled_at IS NOT NULL OR NEW.activated_at IS NOT NULL))
                    OR (OLD.status = 'PASSWORD_ESTABLISHED' AND (NEW.email_verified_at IS DISTINCT FROM OLD.email_verified_at
                        OR NEW.password_established_at IS DISTINCT FROM OLD.password_established_at
                        OR NEW.mfa_enrollment_started_at IS NULL OR NEW.mfa_enrolled_at IS NOT NULL OR NEW.activated_at IS NOT NULL))
                    OR (OLD.status = 'MFA_ENROLLING' AND (NEW.email_verified_at IS DISTINCT FROM OLD.email_verified_at
                        OR NEW.password_established_at IS DISTINCT FROM OLD.password_established_at
                        OR NEW.mfa_enrollment_started_at IS DISTINCT FROM OLD.mfa_enrollment_started_at
                        OR NEW.mfa_enrolled_at IS NULL OR NEW.activated_at IS NOT NULL))
                    OR (OLD.status = 'MFA_ENROLLED' AND (NEW.email_verified_at IS DISTINCT FROM OLD.email_verified_at
                        OR NEW.password_established_at IS DISTINCT FROM OLD.password_established_at
                        OR NEW.mfa_enrollment_started_at IS DISTINCT FROM OLD.mfa_enrollment_started_at
                        OR NEW.mfa_enrolled_at IS DISTINCT FROM OLD.mfa_enrolled_at OR NEW.activated_at IS NULL)) THEN
                    RAISE EXCEPTION 'OWNER_ACTIVATION_MILESTONE_INVALID' USING ERRCODE = 'P0001';
                END IF;
                IF NEW.version <> OLD.version + 1 THEN
                    RAISE EXCEPTION 'OWNER_ACTIVATION_VERSION_INVALID' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER trg_owner_activation_lifecycle_guard
            BEFORE INSERT OR UPDATE OR DELETE ON owner_activations
            FOR EACH ROW EXECUTE FUNCTION owner_activation_lifecycle_guard();

            CREATE FUNCTION identity_security_events_immutable_guard() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'IDENTITY_SECURITY_EVENT_IMMUTABLE' USING ERRCODE = 'P0001';
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER trg_identity_security_events_immutable
            BEFORE UPDATE OR DELETE ON identity_security_events
            FOR EACH ROW EXECUTE FUNCTION identity_security_events_immutable_guard();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_identity_security_events_immutable ON identity_security_events');
        DB::statement('DROP FUNCTION IF EXISTS identity_security_events_immutable_guard()');
        DB::statement('DROP TRIGGER IF EXISTS trg_owner_activation_lifecycle_guard ON owner_activations');
        DB::statement('DROP FUNCTION IF EXISTS owner_activation_lifecycle_guard()');
        Schema::dropIfExists('identity_security_events');
        Schema::dropIfExists('identity_challenges');
        Schema::dropIfExists('owner_recovery_codes');
        Schema::dropIfExists('owner_mfa_factors');
        Schema::dropIfExists('owner_activation_tokens');
        Schema::dropIfExists('owner_activations');
        Schema::table('user_sessions', function (Blueprint $table): void {
            $table->dropUnique(['web_session_digest']);
            $table->dropColumn(['auth_epoch', 'web_session_digest', 'channel']);
        });
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropColumn(['auth_epoch', 'property_id']);
        });
        DB::statement('DROP INDEX IF EXISTS uq_users_email_canonical');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_users_email_canonical');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('auth_epoch'));
    }

    private function createOrValidateSessionsTable(): void
    {
        if (! Schema::hasTable('sessions')) {
            Schema::create('sessions', function (Blueprint $table): void {
                $table->string('id')->primary();
                $table->foreignUlid('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->text('payload');
                $table->integer('last_activity')->index();
            });
            DB::statement("COMMENT ON TABLE sessions IS 'IVORQ_B5A1B1_LARAVEL_SESSION_SCHEMA'");

            return;
        }

        $required = ['id', 'user_id', 'ip_address', 'user_agent', 'payload', 'last_activity'];
        $columns = collect(Schema::getColumns('sessions'))->keyBy('name');
        $actual = $columns->keys()->all();
        if (array_diff($required, $actual) !== []) {
            throw new RuntimeException('B5A1B1_DATABASE_SESSION_SCHEMA_CONFLICT');
        }
        $indexedColumns = collect(Schema::getIndexes('sessions'))->pluck('columns')->flatten()->all();
        $typesAreCompatible = str_contains((string) $columns->get('id')['type_name'], 'varchar')
            && str_contains((string) $columns->get('user_id')['type_name'], 'char')
            && str_contains((string) $columns->get('ip_address')['type_name'], 'varchar')
            && str_contains((string) $columns->get('user_agent')['type_name'], 'text')
            && str_contains((string) $columns->get('payload')['type_name'], 'text')
            && str_contains((string) $columns->get('last_activity')['type_name'], 'int');
        if (! $typesAreCompatible || ! in_array('user_id', $indexedColumns, true)) {
            throw new RuntimeException('B5A1B1_DATABASE_SESSION_SCHEMA_CONFLICT');
        }
    }
};
