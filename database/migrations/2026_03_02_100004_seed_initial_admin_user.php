<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;

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

        // Create admin user for first email if no user row exists
        $firstEmail = $adminEmails[0];
        if (!User::where('email', $firstEmail)->exists()) {
            User::create([
                'name' => 'Admin',
                'email' => $firstEmail,
                'role' => 'admin',
                'email_verified_at' => now(),
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
