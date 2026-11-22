<?php

namespace App\Http\Controllers;

use App\Models\MediaStorageTarget;
use App\Services\Activity\ActivityLogger;
use App\Services\MediaStorage\MediaStorageException;
use App\Services\MediaStorage\MediaStorageManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * OAuth 2.0 for Settings → Media storage. One redirect URI per storage type
 * (so several Google Drive accounts can share one OAuth app); the destination
 * being connected travels in the session with the state value.
 */
class MediaStorageOAuthController extends Controller
{
    public static function redirectUri(string $driver): string
    {
        return route('settings.media.callback', $driver);
    }

    public function connect(MediaStorageTarget $target, MediaStorageManager $manager): RedirectResponse
    {
        $drive = $manager->driver($target);
        abort_unless($drive::usesOAuth(), 404);

        if (! $drive->hasCredentials()) {
            return redirect()->route('settings.media')->with('error', __('Enter the client ID and secret for :name first.', ['name' => $target->name]));
        }

        $state = Str::random(48);
        session()->put('media_oauth', ['state' => $state, 'target' => $target->id, 'driver' => $target->driver]);

        return redirect()->away($drive->authorizeUrl(self::redirectUri($target->driver), $state));
    }

    public function callback(Request $request, string $driver, MediaStorageManager $manager, ActivityLogger $activity): RedirectResponse
    {
        $expected = session()->pull('media_oauth');
        $back = redirect()->route('settings.media');

        if (! is_array($expected) || ($expected['driver'] ?? null) !== $driver || ! hash_equals((string) ($expected['state'] ?? ''), (string) $request->query('state'))) {
            return $back->with('error', __('The connection request expired or did not come from this page. Try connecting again.'));
        }

        $target = MediaStorageTarget::query()->find($expected['target'] ?? 0);
        if (! $target || $target->driver !== $driver) {
            return $back->with('error', __('That storage destination no longer exists.'));
        }

        if ($request->filled('error')) {
            return $back->with('error', __('Access was not granted: :reason', ['reason' => Str::limit(strip_tags((string) ($request->query('error_description') ?: $request->query('error'))), 200)]));
        }

        $drive = $manager->driver($target);

        try {
            $result = $drive->exchangeCode((string) $request->query('code'), self::redirectUri($driver));
        } catch (MediaStorageException $e) {
            return $back->with('error', $e->getMessage());
        }

        $changedAccount = filled($target->account) && $target->account !== $result['account'];
        $target->setCredentials(['refresh_token' => $result['refresh_token']]);
        $target->fill([
            'account' => $result['account'],
            'connected_at' => now(),
            // A different account cannot see the old folder.
            'folder_id' => $changedAccount ? null : $target->folder_id,
            'folder_name' => $changedAccount ? null : $target->folder_name,
        ])->save();
        $drive->forgetAccessToken();

        $activity->log('settings.updated', __('Connected :name (:account)', ['name' => $target->name, 'account' => $result['account']]));

        return $back->with('success', __(':name is connected as :account. Now choose its folder.', ['name' => $target->name, 'account' => $result['account']]));
    }
}
