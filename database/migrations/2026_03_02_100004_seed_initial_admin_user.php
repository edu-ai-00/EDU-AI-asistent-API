<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $adminEmails = array_filter(array_map(
            'trim',
            explode(',', env('ADMIN_EMAILS', ''))
        ));

        if (empty($adminEmails)) {
            return;
        }

        // Set role=admin on existing users matching ADMIN_EMAILS
        User::whereIn('email', $adminEmails)->update(['role' => 'admin']);

        // Create admin user for first email if no user row exists. A plain
        // insert, not User::create: the model's saving hook touches columns
        // later migrations add (invited_at), so on a fresh database it fails.
        $firstEmail = $adminEmails[0];
        if (!User::where('email', $firstEmail)->exists()) {
            DB::table('users')->insert([
                'name' => 'Admin',
                'email' => $firstEmail,
                'role' => 'admin',
                'email_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Revert admin roles back to student
        $adminEmails = array_filter(array_map(
            'trim',
            explode(',', env('ADMIN_EMAILS', ''))
        ));

        if (!empty($adminEmails)) {
            User::whereIn('email', $adminEmails)->update(['role' => 'student']);
        }
    }
};
