<?php

namespace Tests\Unit;

use App\Enums\LinkPlatform;
use App\Services\DocumentService;
use App\Support\Format;
use PHPUnit\Framework\TestCase;

class FormatTest extends TestCase
{
    public function test_decimal_formatting_without_floats(): void
    {
        $this->assertSame('1,234,567.89', Format::decimal('1234567.885'));
        $this->assertSame('0.10', Format::decimal('0.1'));
        $this->assertSame('-1,000.01', Format::decimal('-1000.005'));
        $this->assertSame('99,999,999,999.99', Format::decimal('99999999999.990000'));
        $this->assertSame('—', Format::decimal(null));
        $this->assertSame('1,235', Format::decimal('1234.5', 0));
    }

    public function test_percent_and_bytes(): void
    {
        $this->assertSame('70%', Format::percent('70.0000'));
        $this->assertSame('12.5%', Format::percent('12.5000'));
        $this->assertSame('0%', Format::percent('0.0000'));
        $this->assertSame('512 B', Format::bytes(512));
        $this->assertSame('1.5 MB', Format::bytes(1572864));
    }

    public function test_link_platform_host_matching(): void
    {
        $this->assertTrue(LinkPlatform::Spotify->accepts('https://open.spotify.com/artist/1'));
        $this->assertFalse(LinkPlatform::Spotify->accepts('https://spotify.com.evil.net/x'));
        $this->assertFalse(LinkPlatform::Spotify->accepts('https://notspotify.com/x'));
        $this->assertTrue(LinkPlatform::YouTube->accepts('https://youtu.be/abc'));
        $this->assertTrue(LinkPlatform::Website->accepts('https://anything.example'));
    }

    public function test_document_names_are_sanitised(): void
    {
        $this->assertSame('passwd', DocumentService::safeName('../../etc/passwd'));
        $this->assertSame('evil.pdf', DocumentService::safeName('C:\\temp\\evil.pdf'));
        $this->assertSame('a_b.pdf', DocumentService::safeName("a\"\nb.pdf"));
        $this->assertSame('document', DocumentService::safeName('...'));
    }
}
