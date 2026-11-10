<?php

namespace App\Services;

class AssistantSafeTurnScreen
{
    /** Public chat vocabulary, not a list of reply templates. Unknown words stay local. */
    private const WORDS = [
        'a', 'about', 'also', 'an', 'and', 'another', 'are', 'be', 'can', 'chat', 'cheerful',
        'clothes', 'clothing', 'could', 'do', 'enjoy', 'fashion', 'feel', 'feels', 'for',
        'fun', 'funny', 'give', 'great', 'happy', 'how', 'i', 'idea', 'ideas', 'is',
        'joke', 'jokes', 'kind', 'lets', 'like', 'little', 'lovely', 'me', 'more', 'nice',
        'of', 'on', 'outfits', 'playful', 'positive', 'preloved', 'reuse', 'reusing',
        'say', 'secondhand', 'share', 'shopping', 'so', 'some', 'something', 'style',
        'sustainable', 'talk', 'tell', 'the', 'think', 'thought', 'thoughts', 'thrift',
        'thrifting', 'to', 'upcycling', 'vintage', 'we', 'what', 'why', 'with', 'would',
        'you', 'your',
    ];

    private const PUBLIC_TOPICS = [
        'chat', 'clothes', 'clothing', 'fashion', 'fun', 'funny', 'joke', 'jokes',
        'outfits', 'preloved', 'reuse', 'reusing', 'secondhand', 'style',
        'sustainable', 'thrift', 'thrifting', 'upcycling', 'vintage',
    ];

    public function screen(string $question): ?string
    {
        $question = trim($question);

        if ($question === '' || strlen($question) > 220 || preg_match('/\A[a-zA-Z ?.!,\x27-]+\z/D', $question) !== 1) {
            return null;
        }

        $turn = trim(preg_replace('/\s+/', ' ', preg_replace('/[?.!,\x27-]/', ' ', strtolower($question)) ?? '') ?? '');
        $words = explode(' ', $turn);

        if (count($words) > 30 || array_diff($words, self::WORDS) !== []
            || array_intersect($words, self::PUBLIC_TOPICS) === []) {
            return null;
        }

        if (preg_match('/\A(?:what|who|when|where) (?:is|are)\b/', $turn) === 1) {
            return null;
        }

        return $turn;
    }
}
