<?php

namespace APP\plugins\generic\blog\classes\migration\install;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PKP\install\DowngradeNotSupportedException;

/**
 * Install the JEDL Blog plugin database schema.
 *
 * OJS 3.5 does not install a legacy schema.xml automatically. This migration
 * is the canonical OJS plugin-install path; BlogPlugin::ensureDatabaseSchema()
 * additionally makes manual/cPanel uploads safe and idempotent.
 */
class SchemaMigration extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('blog_entries')) {
            Schema::create('blog_entries', function (Blueprint $table) {
                $table->bigInteger('entry_id')->autoIncrement();
                $table->bigInteger('context_id');
                $table->string('title', 255);
                $table->string('byline', 255);
                $table->longText('content');
                $table->dateTime('date_posted');
                $table->boolean('is_enabled')->default(true);
                $table->index('context_id');
                $table->index('date_posted');
            });
        } elseif (!Schema::hasColumn('blog_entries', 'is_enabled')) {
            Schema::table('blog_entries', function (Blueprint $table) {
                $table->boolean('is_enabled')->default(true);
            });
        }

        if (!Schema::hasTable('blog_keywords')) {
            Schema::create('blog_keywords', function (Blueprint $table) {
                $table->bigInteger('keyword_id')->autoIncrement();
                $table->string('keyword', 255);
                $table->index('keyword');
            });
        }

        if (!Schema::hasTable('blog_entries_keywords')) {
            Schema::create('blog_entries_keywords', function (Blueprint $table) {
                $table->bigInteger('entry_id');
                $table->bigInteger('keyword_id');
                $table->unique(['entry_id', 'keyword_id']);
                $table->index('keyword_id');
            });
        }
    }

    public function down(): void
    {
        throw new DowngradeNotSupportedException();
    }
}
