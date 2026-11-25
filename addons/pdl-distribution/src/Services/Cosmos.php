<?php

namespace LabelDesk\Addons\PdlDistribution\Services;

/**
 * COSMOS controlled vocabularies, as listed in PDL's "COSMOS data ingestion
 * API Guide" (genre list updated 24/08/2026). The guide has no lookup
 * endpoint for genres, languages or moods, so these lists are offered in
 * Settings → PDL distribution for mapping; spellings are PDL's own.
 */
final class Cosmos
{
    public const DEFAULT_BASE_URL = 'http://pdl.gaonaweb.com';

    public const API_PREFIX = '/v2.0';

    public const PLATFORMS = [
        'AllPlatformWithYouTube' => 'All platforms including YouTube',
        'AllPlatformNotOnYouTube' => 'All platforms except YouTube',
        'OnlyYouTube' => 'Only YouTube',
    ];

    public const ALBUM_TYPES = ['Album', 'Film'];

    /** Content type / album sub-type. */
    public const CONTENT_TYPES = ['Album', 'Single', 'Compilation', 'Remix', 'Acoustic', 'Extended', 'Karaoke', 'Live', 'Medley', 'Studio Version'];

    public const MOODS = ['Romantic', 'Happy', 'Sad', 'Dance', 'Bhangra', 'Partiotic', 'Nostalgic', 'Inspirational', 'Enthusiastic', 'Optimistic', 'Passion', 'Pessimistic', 'Spiritual', 'Peppy', 'Philosophical', 'Mellow', 'Calm'];

    public const LANGUAGES = [
        'ahirani', 'arabic', 'assamese', 'awadhi', 'banjara', 'bengali', 'bhojpuri', 'burmese', 'chhattisgarhi', 'chinese', 'dogri',
        'english', 'french', 'garhwali', 'garo', 'gujarati', 'haryanvi', 'himachali', 'hindi', 'iban', 'indonesian', 'instrumental',
        'italian', 'japanese', 'javanese', 'kannada', 'kashmiri', 'khasi', 'kokborok', 'konkani', 'korean', 'kumauni', 'latin',
        'maithili', 'malay', 'malayalam', 'mandarin', 'manipuri', 'marathi', 'marwari', 'naga', 'nagpuri', 'nepali', 'odia', 'pali',
        'persian', 'punjabi', 'rajasthani', 'sainthili', 'sambalpuri', 'sanskrit', 'santali', 'sindhi', 'sinhala', 'spanish', 'swahili',
        'tamil', 'telugu', 'thai', 'tibetan', 'tulu', 'turkish', 'ukrainian', 'urdu', 'zxx',
    ];

    /** ISO 639 codes whose English name differs from the COSMOS spelling. */
    public const LANGUAGE_ALIASES = ['or' => 'odia', 'zh' => 'mandarin', 'fa' => 'persian', 'ms' => 'malay', 'zxx' => 'instrumental'];

    /** Genre => sub-genres. */
    public const GENRES = [
        'Hip-Hop/Rap' => ['Hip-Hop', 'Rap', 'Hip Hop/Rap', 'Underground Rap', 'Old School Rap', 'Hardcore Rap', 'Gangsta Rap', 'Alternative Rap'],
        'Hindustani Classical' => ['Instrumental', 'Vocal'],
        'Hindu Devotional' => ['Aarti', 'Bhajan', 'Chalisa', 'Chant', 'Geet', 'Kirtan', 'Mantra', 'Bhaktigeet'],
        'Buddhist Devotional' => ['Chant', 'Mantra'],
        'Christian Devotional' => ['Carol', 'Gospel', 'Hymn', 'Christian Pop', 'Christian Rock'],
        'Jain Devotional' => ['Chant', 'Mantra'],
        'Islamic Devotional' => ['Islamic Shabd', 'Qawwali', "Na'at", 'Mapilla', 'Ghazal'],
        'Sikh Devotional' => ['Gurbani', 'Kirtan', 'Paath', 'Shabad'],
        'Carnatic Classical' => ['Instrumental', 'Vocal'],
        'Ambient / Instrumental' => ['Soft', 'Easy Listening', 'Lounge'],
        'Film' => ['Devotional', 'Dialogue', 'Ghazal', 'Hip-Hop/ Rap', 'Instrumental', 'Patriotic', 'Remix', 'Romantic', 'Unplugged', 'Item Song', 'Dance', 'Qawallis', 'Love Songs', 'Sad Songs', 'BGM'],
        'Pop' => ['Acoustic Pop', 'Band Songs', 'Chill Pop', 'Contemporary Pop', 'Regional Pop', 'Dance Pop', 'Electro Pop', 'Lo-Fi Pop', 'Love Songs', 'Pop Rap', 'Pop Singer-Songwriter', 'Sad Songs', 'Soft Pop', 'Indian Pop', 'Punjabi Pop', 'Patriotic', 'Bhojpuri Pop', 'Bangla Pop', 'Desi Pop', 'Gujarati Pop', 'Haryanvi Pop', 'Hindi Pop', 'Kannada Pop', 'Malayalam Pop', 'Marathi Pop', 'Tamil Pop', 'Telugu Pop', 'Assamese Pop', 'Rajasthani Pop', 'Odia Pop'],
        'Folk' => ['Ainchaliyan', 'Alha', 'Atulprasadi', 'Baalgeet', 'Banvarh', 'Barhamasa', 'Basant Geet', 'Baul Geet', 'Bhadu Gaan', 'Bhagawati', 'Bhand', 'Bhangra', 'Bhatiali', 'Bhavageete', 'Bhawaiya', 'Bhuta song', 'Bihugeet', 'Birha', 'Borgeet', 'Burrakatha', 'Chappeli', 'Daff', 'Dandiya Raas', 'Dasakathia', 'Deijendrageeti', 'Deknni', 'Dhamal', 'Gadhwali', 'Gagor', 'Garba', 'Ghasiyari Geet', 'Ghoomar', 'Gidda', 'Gugga', 'Hafiz Nagma', 'Heliam', 'Hereileu', 'Hori', 'Jaanapada Geethe', 'Jaita', 'Jhoori', 'Jhora', 'Jhumur', 'Jugni', 'Kajari', 'Kajri', 'Karwa Chauth Songs', 'Khor', 'Koligeet', 'Kumayuni', 'Kummi Paatu', 'Lagna Geet', 'Lalongeeti', 'Lavani', 'Lokgeet', 'Loor', 'Maand', 'Madiga Dappu', 'Mando', 'Mapilla', 'Naatupura Paadalgal', 'Naqual', 'Nati', 'Nautanki', 'Nazrulgeeti', 'Neuleu', 'Nyioga', 'Oggu Katha', 'Paani Hari', 'Pai Song', 'Pandavani', 'Pankhida', 'Patua Sangeet', 'Phag Dance', 'Powada', 'Qawwali', 'Rabindra Sangeet', 'Rajanikantageeti', 'Ramprasadi', 'Rasiya', 'Rasiya Geet', 'Raslila', 'Raut Nacha', 'Saikuthi Zai', 'Sana Lamok', 'Shakunakhar-Mangalgeet', 'Shyama Sangeet', 'Sohar', 'Sumangali', 'Surma', 'Suvvi paatalu', 'Tappa', 'Teej songs', 'Tusu Gaan', 'Villu Pattu', 'Qawwals', 'Marriage Song'],
        'Indie' => ['Indian Indie', 'Indie Dance', 'Indie Folk', 'Indie Hip-Hop', 'Indie Lo-Fi', 'Indie Pop', 'Indie Rock', 'Indie Singer -Songwriter'],
        "Children's Music" => ["Children's Music", 'Lullabies', 'Sing-Along', 'Stories'],
        'Worldwide' => ['Worldwide', 'Asia'],
        'New Age' => ['New Age', 'Healing', 'Meditation', 'Nature', 'Relaxation', 'Travel', 'Yoga'],
        'Jazz' => ['Jazz'],
        'Electronic' => ['Electronic', 'Ambient', 'Bass', 'Downtempo', 'Dubstep', "Electro-Cha'abi", 'Electronica', 'IDM/Experimental', 'Industrial'],
        'Dance' => ['Dance', 'Breakbeat', 'Garage', 'Hardcore', 'House', 'Techno', 'Trance'],
        'Rock' => ['Pop/Rock', 'Rock', 'Adult Alternative', 'Arena Rock', 'Blues-Rock', 'Death Metal/Black Metal', 'Glam Rock', 'Hair Metal', 'Hard Rock', 'Heavy Metal', 'Jam Bands', 'Prog-Rock/Art Rock', 'Psychedelic', 'Rock & Roll', 'Rockabilly', 'Roots Rock', 'Singer/Songwriter', 'Surf'],
        'Ghazal' => ['Ghazal'],
    ];

    /** Audio specifications COSMOS accepts: sample rate (Hz) => bit depths. */
    public const AUDIO_SPECS = [
        192000 => [24, 32], 176400 => [24, 32], 96000 => [24, 32], 88200 => [24, 32], 48000 => [16, 24, 32], 44100 => [16, 24, 32],
    ];

    public const ARTWORK_MIN_PX = 1500;

    public const ARTWORK_MAX_PX = 6000;

    /** release_status values of releases[] (per platform) in the album listing. */
    public const RELEASE_STATUS = [
        0 => 'not released', 1 => 'released', 2 => 'live', 3 => 'takedown initiated', 4 => 'taken down',
        5 => 'live (updated)', 6 => 'deleted', 7 => 'update initiated', 8 => 'update completed', 9 => 'release error',
    ];

    /** @return list<string> "Genre|Sub-genre" options. */
    public static function genreOptions(): array
    {
        $options = [];
        foreach (self::GENRES as $genre => $subs) {
            foreach ($subs as $sub) {
                $options[] = $genre.'|'.$sub;
            }
        }

        return $options;
    }
}
