<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 60)->nullable()->after('name')->unique();
        });

        $usedUsernames = [];

        DB::transaction(function () use (&$usedUsernames): void {
            DB::table('users')
                ->select(['id', 'email'])
                ->orderBy('id')
                ->chunkById(500, function (Collection $users) use (&$usedUsernames): void {
                    $users->each(function (object $user) use (&$usedUsernames): void {
                        $baseUsername = Str::of(Str::before((string) $user->email, '@'))
                            ->ascii()
                            ->lower()
                            ->replaceMatches('/[^a-z0-9]+/', '_')
                            ->trim('_')
                            ->substr(0, 60)
                            ->toString();

                        $baseUsername = $baseUsername === '' ? 'usuario' : $baseUsername;
                        $username = $baseUsername;
                        $suffix = 2;

                        while (in_array($username, $usedUsernames, true)) {
                            $suffixText = (string) $suffix;
                            $username = substr($baseUsername, 0, 60 - strlen($suffixText) - 1).'_'.$suffixText;
                            $suffix++;
                        }

                        DB::table('users')
                            ->where('id', $user->id)
                            ->update(['username' => $username]);

                        $usedUsernames[] = $username;
                    });
                });
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 60)->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_username_unique');
            $table->dropColumn('username');
        });
    }
};
