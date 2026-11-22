<?php

namespace App\Services\Activity;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Records audit entries. Sensitive values (passwords, tokens, secrets, license
 * keys) are stripped from properties before anything is stored, and a failure
 * to write the log never breaks the action being logged.
 */
class ActivityLogger
{
    /** Property keys containing any of these fragments are never stored. */
    protected const SENSITIVE = ['password', 'secret', 'token', 'license_key', 'api_key', 'remember'];

    /**
     * @param  array<string, mixed>  $properties
     */
    public function log(string $event, string $description, ?Model $subject = null, array $properties = [], ?User $causer = null): ?ActivityLog
    {
        try {
            $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
            $causer ??= auth()->user();

            return ActivityLog::query()->create([
                'user_id' => $causer?->getKey(),
                'event' => Str::limit($event, 64, ''),
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'description' => Str::limit($description, 255, ''),
                'properties' => $properties === [] ? null : $this->sanitize($properties),
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? Str::limit((string) $request->userAgent(), 255, '') : null,
            ]);
        } catch (Throwable $e) {
            Log::warning('Activity log entry could not be written.', ['event' => $event, 'error' => $e::class]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public function sanitize(array $properties): array
    {
        $clean = [];

        foreach ($properties as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                continue;
            }

            $clean[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $clean;
    }

    protected function isSensitive(string $key): bool
    {
        $key = Str::lower($key);

        return $key === 'key' || Str::contains($key, self::SENSITIVE);
    }

    /**
     * Human-readable labels for the event filter.
     *
     * @return array<string, string>
     */
    public static function events(): array
    {
        return [
            'auth.login' => __('Signed in'),
            'auth.logout' => __('Signed out'),
            'auth.failed' => __('Failed sign-in'),
            'auth.lockout' => __('Sign-in locked out'),
            'auth.password_reset' => __('Password reset'),
            'profile.updated' => __('Profile updated'),
            'profile.password_changed' => __('Password changed'),
            'user.created' => __('User created'),
            'user.updated' => __('User updated'),
            'user.activated' => __('User activated'),
            'user.deactivated' => __('User deactivated'),
            'user.deleted' => __('User deleted'),
            'user.restored' => __('User restored'),
            'user.reset_link_sent' => __('Password link sent'),
            'role.created' => __('Role created'),
            'role.updated' => __('Role updated'),
            'role.deleted' => __('Role deleted'),
            'settings.updated' => __('Settings updated'),
            'settings.mail_test' => __('Test email sent'),
            'artist.created' => __('Artist created'),
            'artist.updated' => __('Artist updated'),
            'artist.deleted' => __('Artist deleted'),
            'artist.restored' => __('Artist restored'),
            'artist.photo_updated' => __('Artist photo changed'),
            'artist.contact_added' => __('Contact added'),
            'artist.contact_updated' => __('Contact updated'),
            'artist.contact_removed' => __('Contact removed'),
            'artist.link_added' => __('Link added'),
            'artist.link_updated' => __('Link updated'),
            'artist.link_removed' => __('Link removed'),
            'contract.created' => __('Contract created'),
            'contract.updated' => __('Contract updated'),
            'contract.deleted' => __('Contract deleted'),
            'document.uploaded' => __('Document uploaded'),
            'document.downloaded' => __('Document downloaded'),
            'document.deleted' => __('Document deleted'),
            'release.created' => __('Release created'),
            'release.updated' => __('Release updated'),
            'release.deleted' => __('Release deleted'),
            'release.restored' => __('Release restored'),
            'release.artwork_updated' => __('Artwork changed'),
            'release.submit' => __('Release submitted'),
            'release.withdraw' => __('Submission withdrawn'),
            'release.start_review' => __('Review started'),
            'release.approve' => __('Release approved'),
            'release.reject' => __('Changes requested'),
            'release.mark_ready' => __('Ready for delivery'),
            'release.reopen' => __('Release reopened'),
            'track.created' => __('Track created'),
            'track.updated' => __('Track updated'),
            'track.deleted' => __('Track deleted'),
            'track.audio_uploaded' => __('Audio uploaded'),
            'track.master_downloaded' => __('Master downloaded'),
            'contributor.created' => __('Contributor created'),
            'contributor.updated' => __('Contributor updated'),
            'contributor.deleted' => __('Contributor deleted'),
            'distribution.added' => __('Stores added'),
            'distribution.removed' => __('Store removed'),
            'distribution.updated' => __('Store details updated'),
            'distribution.deliver' => __('Delivered to store'),
            'distribution.go_live' => __('Live in store'),
            'distribution.reject' => __('Rejected by store'),
            'distribution.request_takedown' => __('Takedown requested'),
            'distribution.confirm_takedown' => __('Taken down'),
            'distribution.cancel_takedown' => __('Takedown cancelled'),
            'dsp.created' => __('Store created'),
            'dsp.updated' => __('Store updated'),
            'dsp.deleted' => __('Store deleted'),
            'royalty.period_created' => __('Royalty period created'),
            'royalty.period_deleted' => __('Royalty period deleted'),
            'royalty.period_locked' => __('Royalty period locked'),
            'royalty.import_uploaded' => __('Statement uploaded'),
            'royalty.import_processed' => __('Statement imported'),
            'royalty.import_reverted' => __('Statement reverted'),
            'royalty.lines_matched' => __('Sales matched'),
            'royalty.lines_ignored' => __('Sales marked not ours'),
            'royalty.ledger_payment' => __('Payment recorded'),
            'royalty.ledger_advance' => __('Advance recorded'),
            'royalty.ledger_expense' => __('Expense recorded'),
            'royalty.ledger_adjustment' => __('Adjustment recorded'),
            'royalty.ledger_reversed' => __('Entry reversed'),
            'royalty.statement_exported' => __('Statement exported'),
        ];
    }
}
