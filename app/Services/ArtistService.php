<?php

namespace App\Services;

use App\Services\License\TrialLimits;
use App\Exceptions\CatalogException;
use App\Models\Artist;
use App\Models\ArtistContact;
use App\Models\ArtistLink;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Services\Media\ImageProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Artist records, photos, contacts and links. Authorization and license
 * checks happen in the caller (policies / Livewire); this class owns the data
 * rules and the audit trail.
 */
class ArtistService
{
    public const DISK = 'local';

    public function __construct(
        protected ActivityLogger $activity,
        protected ImageProcessor $images,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data): Artist
    {
        app(TrialLimits::class)->ensure('artists');

        $artist = new Artist;
        $artist->fill($this->clean($data));
        $artist->created_by = $actor->id;
        $artist->save();

        $this->activity->log('artist.created', __('Created artist :name', ['name' => $artist->name]), $artist);

        return $artist;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, Artist $artist, array $data): Artist
    {
        $artist->fill($this->clean($data));
        $dirty = $artist->getDirty();
        $original = collect($dirty)->mapWithKeys(fn ($v, $k) => [$k => $artist->getRawOriginal($k)])->all();
        $artist->save();

        if ($dirty) {
            $this->activity->log('artist.updated', __('Updated artist :name', ['name' => $artist->name]), $artist,
                collect($dirty)->except(['bio', 'notes'])->map(fn ($v, $k) => [$original[$k], $v])
                    ->when(array_key_exists('bio', $dirty), fn ($c) => $c->put('bio', 'changed'))
                    ->when(array_key_exists('notes', $dirty), fn ($c) => $c->put('notes', 'changed'))
                    ->all());
        }

        return $artist;
    }

    public function delete(User $actor, Artist $artist): void
    {
        if ($artist->isCredited()) {
            throw new CatalogException(__(':name is credited on releases or tracks and cannot be deleted. Set the status to Inactive instead.', ['name' => $artist->name]));
        }

        // Soft delete: contracts, documents and history stay intact and the
        // artist can be restored.
        $artist->delete();
        $this->activity->log('artist.deleted', __('Deleted artist :name', ['name' => $artist->name]), $artist);
    }

    public function restore(User $actor, Artist $artist): void
    {
        $artist->restore();
        $this->activity->log('artist.restored', __('Restored artist :name', ['name' => $artist->name]), $artist);
    }

    public function setPhoto(User $actor, Artist $artist, UploadedFile $file): void
    {
        $binary = $this->images->toJpeg($file, 1000);
        $path = 'artists/'.$artist->id.'/photo-'.Str::uuid().'.jpg';

        Storage::disk(self::DISK)->put($path, $binary);

        $old = $artist->photo_path;
        $artist->forceFill(['photo_path' => $path])->save();

        if ($old && $old !== $path) {
            Storage::disk(self::DISK)->delete($old);
        }

        $this->activity->log('artist.photo_updated', __('Updated the photo of :name', ['name' => $artist->name]), $artist);
    }

    public function removePhoto(User $actor, Artist $artist): void
    {
        if (! $artist->photo_path) {
            return;
        }

        Storage::disk(self::DISK)->delete($artist->photo_path);
        $artist->forceFill(['photo_path' => null])->save();

        $this->activity->log('artist.photo_updated', __('Removed the photo of :name', ['name' => $artist->name]), $artist);
    }

    // ------------------------------------------------------------ contacts

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveContact(User $actor, Artist $artist, ?ArtistContact $contact, array $data): ArtistContact
    {
        return DB::transaction(function () use ($artist, $contact, $data) {
            $contact ??= $artist->contacts()->make();
            abort_unless($contact->artist_id === $artist->id, 404);

            $contact->fill([
                'name' => trim($data['name']),
                'role' => $data['role'],
                'email' => filled($data['email'] ?? null) ? Str::lower(trim($data['email'])) : null,
                'phone' => filled($data['phone'] ?? null) ? trim($data['phone']) : null,
                'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
                'is_primary' => (bool) ($data['is_primary'] ?? false),
            ]);
            $created = ! $contact->exists;
            $contact->save();

            // Only one primary contact per artist.
            if ($contact->is_primary) {
                ArtistContact::query()->where('artist_id', $artist->id)->whereKeyNot($contact->id)->update(['is_primary' => false]);
            }

            $this->activity->log(
                $created ? 'artist.contact_added' : 'artist.contact_updated',
                ($created ? __('Added contact :contact to :artist', ['contact' => $contact->name, 'artist' => $artist->name])
                    : __('Updated contact :contact of :artist', ['contact' => $contact->name, 'artist' => $artist->name])),
                $artist,
            );

            return $contact;
        });
    }

    public function deleteContact(User $actor, Artist $artist, ArtistContact $contact): void
    {
        abort_unless($contact->artist_id === $artist->id, 404);
        $contact->delete();

        $this->activity->log('artist.contact_removed', __('Removed contact :contact from :artist', ['contact' => $contact->name, 'artist' => $artist->name]), $artist);
    }

    // ------------------------------------------------------------ links

    public function saveLink(User $actor, Artist $artist, ?ArtistLink $link, string $platform, string $url): ArtistLink
    {
        $link ??= $artist->links()->make();
        abort_unless($link->artist_id === $artist->id, 404);

        $created = ! $link->exists;
        $link->fill(['platform' => $platform, 'url' => trim($url)])->save();

        $this->activity->log(
            $created ? 'artist.link_added' : 'artist.link_updated',
            __(':platform link saved for :artist', ['platform' => $link->platform->label(), 'artist' => $artist->name]),
            $artist,
            ['url' => $link->url],
        );

        return $link;
    }

    public function deleteLink(User $actor, Artist $artist, ArtistLink $link): void
    {
        abort_unless($link->artist_id === $artist->id, 404);
        $link->delete();

        $this->activity->log('artist.link_removed', __(':platform link removed from :artist', ['platform' => $link->platform->label(), 'artist' => $artist->name]), $artist);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function clean(array $data): array
    {
        $out = [];
        foreach (['name', 'legal_name', 'type', 'status', 'country', 'primary_genre', 'email', 'phone', 'bio', 'notes'] as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $value = is_string($data[$key]) ? trim($data[$key]) : $data[$key];
            $out[$key] = $value === '' ? null : $value;
        }

        if (isset($out['email'])) {
            $out['email'] = Str::lower($out['email']);
        }
        if (isset($out['country'])) {
            $out['country'] = Str::upper($out['country']);
        }

        return $out;
    }
}
