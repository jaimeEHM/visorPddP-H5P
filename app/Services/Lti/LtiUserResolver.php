<?php

namespace App\Services\Lti;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class LtiUserResolver
{
    public function resolveOrCreate(string $issuer, string $subject, ?string $name = null, ?string $email = null): User
    {
        $existing = $this->findExistingUser($issuer, $subject);
        if ($existing !== null) {
            $this->syncExistingUser($existing, $name);

            return $existing;
        }

        return DB::transaction(function () use ($issuer, $subject, $name): User {
            $desiredName = is_string($name) && trim($name) !== '' ? trim($name) : 'Usuario LTI';
            $scopedEmail = $this->nextAvailableLtiEmail($issuer, $subject);

            $attributes = [
                'name' => $desiredName,
                'email' => $scopedEmail,
                'password' => Str::password(32),
            ];

            if (Schema::hasColumn('users', 'lti_embed_only')) {
                $attributes['lti_embed_only'] = true;
            }

            $user = User::query()->create($attributes);
            $user->forceFill(['email_verified_at' => now()])->save();

            if (Schema::hasTable('lti_user_identities')) {
                DB::table('lti_user_identities')->updateOrInsert(
                    ['issuer' => $issuer, 'subject' => $subject],
                    [
                        'user_id' => $user->id,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }

            return $user;
        });
    }

    private function findExistingUser(string $issuer, string $subject): ?User
    {
        if (! Schema::hasTable('lti_user_identities')) {
            return null;
        }

        return User::query()
            ->whereIn('id', function ($query) use ($issuer, $subject): void {
                $query->from('lti_user_identities')
                    ->select('user_id')
                    ->where('issuer', $issuer)
                    ->where('subject', $subject);
            })
            ->first();
    }

    private function syncExistingUser(User $existing, ?string $name): void
    {
        $dirty = false;

        if (is_string($name) && trim($name) !== '' && $existing->name !== $name) {
            $existing->name = $name;
            $dirty = true;
        }

        if ($existing->email_verified_at === null) {
            $existing->email_verified_at = now();
            $dirty = true;
        }

        if ($dirty) {
            $existing->save();
        }
    }

    private function nextAvailableLtiEmail(string $issuer, string $subject): string
    {
        $base = $this->baseScopedLtiEmail($issuer, $subject);
        $candidate = $base;
        $attempt = 1;

        while (User::query()->where('email', $candidate)->exists()) {
            $candidate = preg_replace('/@/', sprintf('+%d@', $attempt), $base, 1) ?? $base;
            $attempt++;
        }

        return $candidate;
    }

    private function baseScopedLtiEmail(string $issuer, string $subject): string
    {
        $host = parse_url($issuer, PHP_URL_HOST);
        $hostPart = is_string($host) && $host !== '' ? Str::slug($host) : 'lms';
        $subjectHash = substr(sha1($issuer.'|'.$subject), 0, 16);

        return sprintf('lti-%s-%s@local.invalid', $hostPart, $subjectHash);
    }
}
