<?php

namespace Tests\Feature;

use App\Services\TrackParsing\DTO\ParsedTrack;
use App\Services\TrackParsing\SourceAudioVersionAnalyzer;
use Tests\TestCase;

class SourceAudioVersionAnalyzerTest extends TestCase
{
    public function test_unlabeled_file_near_a_named_remix_is_not_assumed_to_be_the_original_recording(): void
    {
        $analyzer = new SourceAudioVersionAnalyzer();
        $tracks = [
            new ParsedTrack('Example Song', 206, 'https://audio.test/one.mp3', 'Album', audioFileId: '1'),
            new ParsedTrack('Example Song', 211, 'https://audio.test/two.mp3', null, audioFileId: '2'),
            new ParsedTrack('Example Song', 172, 'https://audio.test/three.mp3', 'Album', audioFileId: '3'),
            new ParsedTrack('Example Song (90s Remix)', 173, 'https://audio.test/four.mp3', 'Album', audioFileId: '4'),
        ];

        $ambiguous = $analyzer->ambiguousFiles($tracks);

        $this->assertSame(['muzofond-id:3'], array_keys($ambiguous));
        $this->assertTrue($ambiguous['muzofond-id:3']['near_named_version']);
    }

    public function test_distinct_cuts_are_preserved_but_the_length_outlier_stays_unmatched(): void
    {
        $analyzer = new SourceAudioVersionAnalyzer();
        $tracks = [
            new ParsedTrack('Other Song', 232, 'https://audio.test/one.mp3', 'Album', audioFileId: '1'),
            new ParsedTrack('Other Song', 234, 'https://audio.test/two.mp3', 'Album', audioFileId: '2'),
            new ParsedTrack('Other Song', 352, 'https://audio.test/three.mp3', 'Album', audioFileId: '3'),
            new ParsedTrack('Other Song (Named Remix)', 356, 'https://audio.test/four.mp3', null, audioFileId: '4'),
        ];

        $this->assertSame(['muzofond-id:3'], array_keys($analyzer->ambiguousFiles($tracks)));
    }

    public function test_two_unlabeled_files_without_a_reliable_reference_are_left_for_regular_matching(): void
    {
        $analyzer = new SourceAudioVersionAnalyzer();
        $tracks = [
            new ParsedTrack('Other Song', 200, 'https://audio.test/one.mp3', null, audioFileId: '1'),
            new ParsedTrack('Other Song', 280, 'https://audio.test/two.mp3', null, audioFileId: '2'),
        ];

        $this->assertSame([], $analyzer->ambiguousFiles($tracks));
    }

    public function test_named_version_can_identify_an_unlabeled_copy_even_with_only_two_plain_files(): void
    {
        $analyzer = new SourceAudioVersionAnalyzer();
        $tracks = [
            new ParsedTrack('Other Song', 206, 'https://audio.test/one.mp3', null, audioFileId: '1'),
            new ParsedTrack('Other Song', 172, 'https://audio.test/two.mp3', null, audioFileId: '2'),
            new ParsedTrack('Other Song (Remix)', 173, 'https://audio.test/three.mp3', null, audioFileId: '3'),
        ];

        $this->assertSame(['muzofond-id:2'], array_keys($analyzer->ambiguousFiles($tracks)));
    }

    public function test_cyrillic_remix_marker_is_recognized(): void
    {
        $this->assertTrue((new SourceAudioVersionAnalyzer())->hasVersionMarker('Песня (Иванов Ремикс)'));
    }
}
