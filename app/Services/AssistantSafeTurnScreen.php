<?php

namespace App\Services;

class AssistantSafeTurnScreen
{
    /**
     * Public, non-identifying chat vocabulary only. Unknown words stay local.
     */
    private const WORDS = [
        'a', 'about', 'and', 'can', 'chat', 'cheerful', 'clothes', 'could', 'fashion',
        'fun', 'funny', 'give', 'happy', 'i', 'joke', 'kind', 'lets', 'like', 'little',
        'me', 'more', 'of', 'on', 'outfits', 'playful', 'positive', 'reuse', 'reusing',
        'say', 'secondhand', 'shopping', 'something', 'talk', 'tell', 'the', 'thrift',
        'thrifting', 'thought', 'thoughts', 'to', 'upcycling', 'we', 'with', 'would',
        'you',
    ];

    public function screen(string $question): ?string
    {
        $question = trim($question);

        if ($question === '' || strlen($question) > 220 || preg_match('/\A[a-zA-Z\s?.!,\x27-]+\z/D', $question) !== 1) {
            return null;
        }

        $turn = trim(preg_replace('/\s+/', ' ', preg_replace('/[?.!,\x27-]/', ' ', strtolower($question)) ?? '') ?? '');
        $words = explode(' ', $turn);

        if (count($words) > 30 || array_diff($words, self::WORDS) !== []) {
            return null;
        }

        if (preg_match('/\A(?:tell me|say|give me|can we|could we|lets|i would like to)\b/', $turn) !== 1
            || preg_match('/\b(?:thrift|thrifting|secondhand|reuse|reusing|upcycling|clothes|outfits|fashion)\b/', $turn) !== 1) {
            return null;
        }

        return $turn;
    }
}
