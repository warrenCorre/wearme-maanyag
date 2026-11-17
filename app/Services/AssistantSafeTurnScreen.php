<?php

namespace App\Services;

class AssistantSafeTurnScreen
{
    /** Recognized local chat; unfamiliar wording can use the separately screened model route. */
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

    private const ACKNOWLEDGEMENTS = ['ok', 'okay', 'alright', 'got it'];

    public function screenBriefFollowUp(string $question, AssistantStoreTurnScreen $storeTurnScreen): ?string
    {
        if (preg_match('/\A(?:not|too|so|very|quite|really|pretty|dili) +[a-z]{4,32}[.!?]*\z/iD', trim($question)) !== 1) {
            return null;
        }

        return $this->screenForModel($question, $storeTurnScreen, allowBriefFollowUp: true);
    }

    public function screenForModel(string $question, AssistantStoreTurnScreen $storeTurnScreen, bool $allowBriefFollowUp = false): ?string
    {
        if (($knownTurn = $this->screen($question)) !== null) {
            return $knownTurn;
        }

        $question = trim($question);
        $turn = trim(preg_replace('/\s+/', ' ', preg_replace('/[?.!,\x27-]/', ' ', strtolower($question)) ?? '') ?? '');

        if (strlen($question) <= 100 && preg_match('/\A[a-zA-Z ?.!,\x27-]+\z/D', $question) === 1
            && preg_match('/\A(?:(?:hi|hello|hey)(?: (?:there|maanyag))?|good (?:morning|afternoon|evening)(?: maanyag)?|(?:thanks|thank you|salamat)(?: maanyag)?|how are you(?: doing)?|hows it going|how is it going|hows your day|whats up|what are you up to|what can you do|how can you help|who are you|can we chat|can you chat with me|bye|goodbye|see you(?: later)?)\z/D', $turn) === 1) {
            return $turn;
        }

        $screened = $storeTurnScreen->screenNaturalIntent($question, allowPublicChat: true, allowBriefPublicChat: $allowBriefFollowUp);

        if ($screened === null
            || $storeTurnScreen->screenSalesFollowUp($question) !== null
            || preg_match('/\b(?:sales?|transactions?|inventory|stock|products?|items?|balances?|unpaid|pending|payments?|revenue|profit|prices?|collections?|collected|sellers?|totals?|counts?|records?|reports?|shop|store|business|money|activity|happened|halin|baligya|utang|nabayran|medical|health|diagnosed|politics|president|capital|news|forecast)\b/', $screened['turn']) === 1
            || preg_match('/\bmy\b(?!\s+(?:day|week|morning|afternoon|evening|mood|energy)\b)/', $screened['turn']) === 1
            || preg_match('/\A(?:what|who|when|where) (?:is|are)\b/', $screened['turn']) === 1) {
            return null;
        }

        return $screened['turn'];
    }

    public function screen(string $question): ?string
    {
        $question = trim($question);

        if ($question === '' || strlen($question) > 220 || preg_match('/\A[a-zA-Z ?.!,\x27-]+\z/D', $question) !== 1) {
            return null;
        }

        $turn = trim(preg_replace('/\s+/', ' ', preg_replace('/[?.!,\x27-]/', ' ', strtolower($question)) ?? '') ?? '');
        $words = explode(' ', $turn);

        if (in_array($turn, self::ACKNOWLEDGEMENTS, true)) {
            return $turn;
        }

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
