<?php

namespace Database\Seeders;

use App\Models\Genre;
use App\Models\Language;
use App\Models\Mood;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * Essential reference data: the genre and language lists used by releases
 * and tracks. Idempotent; never removes or renames existing rows.
 */
class CatalogReferenceSeeder extends Seeder
{
    public const GENRES = [
        'Afrobeats', 'Alternative', 'Ambient', 'Blues', 'Children\'s Music', 'Christian & Gospel', 'Classical',
        'Comedy', 'Country', 'Dance', 'Devotional', 'Electronic', 'Experimental', 'Fitness & Workout', 'Folk',
        'Funk', 'Hip-Hop/Rap', 'Holiday', 'House', 'Indian Classical', 'Indian Film (Bollywood)', 'Indie',
        'Instrumental', 'Jazz', 'J-Pop', 'K-Pop', 'Latin', 'Lo-Fi', 'Metal', 'New Age', 'Pop', 'Punk', 'R&B/Soul',
        'Reggae', 'Reggaeton', 'Rock', 'Singer/Songwriter', 'Soundtrack', 'Spoken Word', 'Techno', 'Trance', 'World',
    ];

    public const LANGUAGES = [
        'en' => 'English', 'es' => 'Spanish', 'fr' => 'French', 'de' => 'German', 'it' => 'Italian',
        'pt' => 'Portuguese', 'nl' => 'Dutch', 'sv' => 'Swedish', 'no' => 'Norwegian', 'da' => 'Danish',
        'fi' => 'Finnish', 'is' => 'Icelandic', 'pl' => 'Polish', 'cs' => 'Czech', 'sk' => 'Slovak',
        'hu' => 'Hungarian', 'ro' => 'Romanian', 'bg' => 'Bulgarian', 'hr' => 'Croatian', 'sr' => 'Serbian',
        'sl' => 'Slovenian', 'el' => 'Greek', 'ru' => 'Russian', 'uk' => 'Ukrainian', 'tr' => 'Turkish',
        'ar' => 'Arabic', 'he' => 'Hebrew', 'fa' => 'Persian', 'hi' => 'Hindi', 'bn' => 'Bengali',
        'pa' => 'Punjabi', 'ta' => 'Tamil', 'te' => 'Telugu', 'ml' => 'Malayalam', 'kn' => 'Kannada',
        'mr' => 'Marathi', 'gu' => 'Gujarati', 'or' => 'Odia', 'as' => 'Assamese', 'ur' => 'Urdu',
        'ne' => 'Nepali', 'si' => 'Sinhala', 'zh' => 'Chinese', 'ja' => 'Japanese', 'ko' => 'Korean',
        'th' => 'Thai', 'vi' => 'Vietnamese', 'id' => 'Indonesian', 'ms' => 'Malay', 'tl' => 'Tagalog',
        'sw' => 'Swahili', 'yo' => 'Yoruba', 'ig' => 'Igbo', 'ha' => 'Hausa', 'zu' => 'Zulu', 'xh' => 'Xhosa',
        'am' => 'Amharic', 'af' => 'Afrikaans', 'ga' => 'Irish', 'cy' => 'Welsh', 'la' => 'Latin',
        'sa' => 'Sanskrit', 'zxx' => 'Instrumental (no lyrics)',
    ];

    /**
     * Seeds the starter lists once. After that, genres and languages belong to
     * the label (Settings → Catalogue), so upgrades never re-add deleted ones.
     */
    public function run(): void
    {
        if (! Genre::query()->exists()) {
            foreach (self::GENRES as $name) {
                Genre::query()->firstOrCreate(['name' => $name]);
            }
        }

        if (! Language::query()->exists()) {
            foreach (self::LANGUAGES as $code => $name) {
                Language::query()->firstOrCreate(['code' => $code], ['name' => $name]);
            }
        }

        // Track moods: PDL's list with PDL's exact values, so deliveries never need translating.
        if (Schema::hasTable('moods') && ! Mood::query()->exists()) {
            $sort = 0;
            foreach (Mood::PDL as $value => $name) {
                Mood::query()->firstOrCreate(['value' => $value], ['name' => $name, 'sort' => ++$sort]);
            }
        }
    }
}
