<?php

use App\Enums\PaymentGateway;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\WalletEntryType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The rider-money schema (P6-T1): payment methods (gateway tokens only), transactions, the wallet and its append-only ledger, and the
 * webhook events already applied. Every amount is an integer in minor units next to its own currency column. Nothing here can hold a
 * card number or a CVC: a card is typed into the gateway's own form in the app and only its token, brand, last four digits and expiry
 * reach us.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('gateway', 32);
            // The gateway's own ids (a Stripe customer and payment method, an Authorize.Net profile and payment profile): tokens, not card data.
            $table->string('gateway_customer_id', 191)->nullable();
            $table->string('gateway_payment_method_id', 191);
            $table->string('brand', 32)->nullable();
            // Only the last four digits and the expiry, for the "Visa •••• 4242, 04/28" line.
            $table->char('last4', 4);
            $table->unsignedSmallInteger('exp_month');
            $table->unsignedSmallInteger('exp_year');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            // Soft deletes: past transactions keep pointing at a card the rider removed.
            $table->softDeletes();

            $table->index('user_id');
        });
        $this->enumCheck('payment_methods', 'gateway', [PaymentGateway::Stripe->value, PaymentGateway::AuthorizeNet->value]);
        DB::statement("ALTER TABLE payment_methods ADD CONSTRAINT payment_methods_last4_check CHECK (last4 ~ '^[0-9]{4}\$')");
        DB::statement('ALTER TABLE payment_methods ADD CONSTRAINT payment_methods_exp_month_check CHECK (exp_month BETWEEN 1 AND 12)');
        DB::statement('ALTER TABLE payment_methods ADD CONSTRAINT payment_methods_exp_year_check CHECK (exp_year BETWEEN 2000 AND 2200)');
        // One live default card per rider, and one row per gateway token.
        DB::statement('CREATE UNIQUE INDEX payment_methods_one_default_per_user ON payment_methods (user_id) WHERE is_default AND deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX payment_methods_gateway_token_unique ON payment_methods (gateway, gateway_payment_method_id) WHERE deleted_at IS NULL');

        // The trip's saved-card reference (a plain column until now) points at a real row.
        Schema::table('trips', function (Blueprint $table) {
            $table->foreign('payment_method_id')->references('id')->on('payment_methods')->nullOnDelete();
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->nullable()->constrained('trips')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->restrictOnDelete();
            // A capture, void or refund points at the transaction it acts on.
            $table->foreignId('parent_transaction_id')->nullable()->constrained('transactions')->restrictOnDelete();
            $table->string('gateway', 32);
            $table->string('gateway_reference', 191)->nullable();
            // Required on every attempt: the same key never produces a second row for a gateway (unique index below).
            $table->string('idempotency_key', 100);
            $table->string('type', 32);
            $table->string('status', 32);
            // Minor units (1 AMD = 1, 1 USD = 100 cents) and the currency they are in.
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_message', 500)->nullable();
            $table->jsonb('metadata')->nullable();
            // The admin who recorded a manual entry.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            // The DB, not only the application, refuses a second attempt with the same key on the same gateway.
            $table->unique(['gateway', 'idempotency_key']);
            $table->index(['gateway', 'gateway_reference']);
            $table->index(['trip_id', 'type']);
            $table->index(['user_id', 'created_at']);
            $table->index('parent_transaction_id');
            $table->index(['status', 'created_at']);
        });
        $this->enumCheck('transactions', 'gateway', PaymentGateway::values());
        $this->enumCheck('transactions', 'type', TransactionType::values());
        $this->enumCheck('transactions', 'status', TransactionStatus::values());
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_amount_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_currency_check CHECK (currency ~ '^[A-Z]{3}\$')");
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_idempotency_key_check CHECK (idempotency_key <> '')");
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_parent_check CHECK (type NOT IN ('capture', 'void', 'refund') OR parent_transaction_id IS NOT NULL)");
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_manual_created_by_check CHECK (gateway <> 'manual' OR created_by IS NOT NULL)");
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_not_own_parent_check CHECK (parent_transaction_id IS NULL OR parent_transaction_id <> id)');

        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->char('currency', 3);
            // A cached total of the ledger: changed only in the transaction that inserts the ledger row, and re-checked every night.
            $table->unsignedBigInteger('balance')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'currency']);
        });
        DB::statement('ALTER TABLE wallets ADD CONSTRAINT wallets_balance_check CHECK (balance >= 0)');
        DB::statement("ALTER TABLE wallets ADD CONSTRAINT wallets_currency_check CHECK (currency ~ '^[A-Z]{3}\$')");

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->string('type', 16);
            $table->unsignedBigInteger('amount');
            $table->string('reason', 64);
            // What caused it (a refund transaction, a trip): lets a retry of the same cause be recognised.
            $table->string('reference_type', 64)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Append-only: there is no updated_at, because a row is never changed.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['wallet_id', 'id']);
            $table->index(['reference_type', 'reference_id']);
        });
        $this->enumCheck('wallet_transactions', 'type', WalletEntryType::values());
        DB::statement('ALTER TABLE wallet_transactions ADD CONSTRAINT wallet_transactions_amount_check CHECK (amount > 0)');
        DB::statement('ALTER TABLE wallet_transactions ADD CONSTRAINT wallet_transactions_reference_check CHECK ((reference_type IS NULL) = (reference_id IS NULL))');
        // The same cause credits (or debits) a wallet once: a retried refund cannot pay out twice.
        DB::statement('CREATE UNIQUE INDEX wallet_transactions_one_per_reference ON wallet_transactions (wallet_id, type, reference_type, reference_id) WHERE reference_id IS NOT NULL');
        // The ledger can only grow: the database refuses to change or remove a row, whatever code tries it.
        DB::statement("CREATE OR REPLACE FUNCTION wallet_transactions_append_only() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'wallet_transactions is append-only'; END \$\$");
        DB::statement('CREATE TRIGGER wallet_transactions_no_change BEFORE UPDATE OR DELETE ON wallet_transactions FOR EACH ROW EXECUTE FUNCTION wallet_transactions_append_only()');

        Schema::create('processed_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 32);
            $table->string('event_id', 191);
            $table->timestamp('received_at')->useCurrent();

            // A delivery the app has already applied is found by this key and discarded.
            $table->unique(['gateway', 'event_id']);
            $table->index('received_at');
        });
        $this->enumCheck('processed_webhook_events', 'gateway', PaymentGateway::values());
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_webhook_events');
        DB::statement('DROP TRIGGER IF EXISTS wallet_transactions_no_change ON wallet_transactions');
        Schema::dropIfExists('wallet_transactions');
        DB::statement('DROP FUNCTION IF EXISTS wallet_transactions_append_only()');
        Schema::dropIfExists('wallets');
        Schema::dropIfExists('transactions');
        Schema::table('trips', function (Blueprint $table) {
            $table->dropForeign(['payment_method_id']);
        });
        Schema::dropIfExists('payment_methods');
    }

    /**
     * @param  list<string>  $values
     */
    private function enumCheck(string $table, string $column, array $values): void
    {
        $list = implode(', ', array_map(fn (string $value) => "'{$value}'", $values));
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_check CHECK ({$column} IN ({$list}))");
    }
};
