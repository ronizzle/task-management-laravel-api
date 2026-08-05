<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// due_date was DATE-only, silently dropping any time-of-day a client sent.
// Widening to a timestamp keeps existing values (midnight) intact while
// allowing a specific time going forward. No doctrine/dbal dependency in
// this app, so Schema::table()->change() isn't available — driver-specific
// SQL instead. SQLite is dynamically typed and already accepts datetime
// strings in the existing column, so there's nothing to alter there.
return new class extends Migration
{
    public function up(): void
    {
        match (Schema::getConnection()->getDriverName()) {
            'pgsql' => DB::statement('ALTER TABLE tasks ALTER COLUMN due_date TYPE TIMESTAMP(0) USING due_date::timestamp'),
            'mysql' => DB::statement('ALTER TABLE tasks MODIFY due_date DATETIME NULL'),
            default => null,
        };
    }

    public function down(): void
    {
        match (Schema::getConnection()->getDriverName()) {
            'pgsql' => DB::statement('ALTER TABLE tasks ALTER COLUMN due_date TYPE DATE USING due_date::date'),
            'mysql' => DB::statement('ALTER TABLE tasks MODIFY due_date DATE NULL'),
            default => null,
        };
    }
};
