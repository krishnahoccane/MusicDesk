<?php

namespace LabelDesk\Addons\RestApi\Http\Controllers;

use App\Enums\ArtistRole;
use App\Enums\ArtistStatus;
use App\Enums\ArtistType;
use App\Enums\ContributorRole;
use App\Enums\ReleaseStatus;
use App\Enums\ReleaseType;
use App\Models\Genre;
use App\Models\Language;
use App\Services\Catalog\ReleaseWorkflow;
use App\Support\ArtistAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LabelDesk\Addons\RestApi\Models\ApiToken;

/** /api/v1/me and /api/v1/reference: who is calling, and the values the catalog accepts. */
class MetaController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        $user = $this->user();
        /** @var ApiToken $token */
        $token = $request->attributes->get('api_token');

        return $this->item([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames()->values()->all(),
                'artist_account' => ArtistAccess::restricted(),
            ],
            'token' => [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => array_values((array) $token->abilities),
                'expires_at' => $token->expires_at?->toIso8601ZuluString(),
            ],
        ]);
    }

    public function reference(): JsonResponse
    {
        $values = fn (string $enum) => array_map(fn ($case) => $case->value, $enum::cases());

        return $this->item([
            'genres' => Genre::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->toArray(),
            'languages' => Language::query()->orderBy('name')->get(['id', 'code', 'name'])->toArray(),
            'artist_types' => $values(ArtistType::class),
            'artist_statuses' => $values(ArtistStatus::class),
            'artist_roles' => $values(ArtistRole::class),
            'release_types' => $values(ReleaseType::class),
            'release_statuses' => $values(ReleaseStatus::class),
            'contributor_roles' => $values(ContributorRole::class),
            'workflow_actions' => array_keys(ReleaseWorkflow::labels()),
        ]);
    }
}
