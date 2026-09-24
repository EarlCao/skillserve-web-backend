<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Philippine National ID verification for customers and providers alike.
 *
 * This is deliberately *separate* from `provider_profiles.verification_status`,
 * which proves a provider is a legitimate tradesperson (documents, business
 * details) and gates publishing services. Identity verification proves who an
 * account holder is and gates transacting. The two answer different questions
 * and are reviewed independently, so existing verified providers are not
 * dragged back through a new queue.
 *
 * ## Storing the PhilSys Card Number
 *
 * Data minimisation: the number itself is never stored in the clear.
 *
 * - `id_number_hash` — HMAC-SHA256 of the normalised 16-digit number under a
 *   server-side pepper. This is the only column compared, and the only one a
 *   unique index can use: Laravel's encryption is randomised, so two rows
 *   holding the same number produce different ciphertext.
 * - `id_number_encrypted` — the number under Laravel's `encrypted` cast, so an
 *   administrator can confirm a disputed match. Cleared when the ID is
 *   released.
 * - `id_number_last4` — the only part ever shown in a list view.
 *
 * ## One National ID = one active account
 *
 * A partial unique index enforces it in the database, not just the
 * application. Both PostgreSQL and SQLite support partial indexes, so the rule
 * is exercised by the test suite as well as production.
 *
 * `released_at` is what makes reuse possible after an account is *permanently*
 * deleted. It is deliberately NOT set on soft deletion: an administrator can
 * restore a soft-deleted account for 30 days, and releasing the ID earlier
 * would allow a second account to claim it and produce two active accounts on
 * one ID after that restore.
 *
 * ## Data impact and deployment
 *
 * Three new tables; no existing table is read or written and no behaviour
 * changes until the submission endpoints are deployed, so this may run before
 * or after the application code. Nothing is backfilled: every existing account
 * simply has no identity record, which reads as "not verified" and — until
 * enforcement is switched on — is not blocked from anything.
 *
 * `user_id` is nullOnDelete rather than cascade *by design*: the verification
 * record and its decision history must outlive the account they belonged to,
 * because they are the audit trail for a decision the platform made. The
 * documents themselves are purged separately on a retention timer.
 *
 * Rollback drops all three tables and loses every verification decision; the
 * stored ID images must be removed from the private disk separately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_verifications', function (Blueprint $table): void {
            $table->id();

            // Null once the account is permanently deleted: the record and its
            // history survive as the audit trail for the decision.
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();

            // unverified · pending · verified · rejected
            $table->string('status')->default('unverified');

            // See the class docblock: hash is compared, ciphertext is for a
            // disputed match, last4 is all that is ever displayed in a list.
            $table->string('id_number_hash', 64)->nullable();
            $table->text('id_number_encrypted')->nullable();
            $table->string('id_number_last4', 4)->nullable();

            // As printed on the card, for the reviewer to match against the
            // image. Not a duplicate of users.name, which the holder can edit.
            $table->string('full_name')->nullable();
            $table->date('birthdate')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();

            // When the ID became reusable (permanent account deletion).
            $table->timestamp('released_at')->nullable();

            // When the stored images may be deleted; set once a decision lands.
            $table->timestamp('documents_purge_after')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('documents_purge_after');
        });

        // The rule: one National ID may back only one *live* verification
        // record. Released records (permanently deleted accounts) are
        // excluded, which is what frees the ID for reuse while keeping the
        // hash on file for fraud detection.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX identity_verifications_active_id_number
            ON identity_verifications (id_number_hash)
            WHERE id_number_hash IS NOT NULL AND released_at IS NULL
        SQL);

        Schema::create('identity_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('identity_verification_id')->constrained()->cascadeOnDelete();

            // id_front · id_back · selfie
            $table->string('document_type');
            $table->string('file_name');
            $table->string('file_path');
            $table->string('file_mime_type');
            $table->unsignedBigInteger('file_size');

            $table->timestamps();

            $table->index('identity_verification_id');
        });

        // Append-only: every submission and every decision, kept even after
        // the account is gone. Nothing in the application updates or deletes
        // a row here.
        Schema::create('identity_verification_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('identity_verification_id')->constrained()->cascadeOnDelete();

            // submitted · approved · rejected · released
            $table->string('action');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['identity_verification_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_verification_events');
        Schema::dropIfExists('identity_documents');
        Schema::dropIfExists('identity_verifications');
    }
};
