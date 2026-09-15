<?php
declare(strict_types=1);
defined('DF_ENTRY') || exit(header('HTTP/1.1 404 Not Found'));

/**
 * Every string on the public page. All of it overridable from the admin CMS.
 *
 * Body fields use a tiny markup: a blank line starts a paragraph, a line starting "- " becomes
 * a bullet. Nothing else is interpreted and everything is escaped on output.
 *
 * Editorial rules: UK English, no em dashes, no en dashes, ranges written with the word "to",
 * capability first rather than limitation first, and no claim the tool cannot actually prove.
 */
final class Content
{
    public const DEFAULTS = [

        /* ------------------------------------------------------------- meta */
        'meta_title'       => 'Free Keyword Rank Checker | Check Google Position Fast',
        'meta_description' => 'Check where your domain ranks for any keyword across 239 countries. See your exact position, every result above you, and what to do next. Free, no signup.',
        'og_alt'           => 'DoctorFizz free keyword rank checker',

        /* ------------------------------------------------------------- hero */
        'hero_kicker'  => 'Free rank checker',
        'hero_a'       => 'WHERE DO YOU',
        'hero_b'       => 'ACTUALLY RANK?',
        'hero_deck'    => 'One keyword. One domain. Ten seconds. You get your exact organic position, every page ranking above you, and the single move worth making next.',
        'form_note'    => 'Five checks a day. Repeat checks are cached and free.',

        /* ------------------------------------------------------------- what it does */
        'does_a'    => 'WHAT IT',
        'does_b'    => 'DOES',
        'does_lede' => 'Three things, done properly.',

        /* ------------------------------------------------------------- how it works */
        'how_a'    => 'HOW IT',
        'how_b'    => 'WORKS',
        'how_lede' => 'Four steps, and the scan stops the moment it finds you.',

        /* ------------------------------------------------------------- the result */
        'result_a'    => 'WHAT YOU',
        'result_b'    => 'GET BACK',
        'result_lede' => 'Every check returns the same four things, so two checks a week apart are directly comparable.',

        /* ------------------------------------------------------------- position bands */
        'bands_a'    => 'WHAT THE',
        'bands_b'    => 'NUMBER MEANS',
        'bands_lede' => 'The same position means four different jobs. This is the one the checker picks for you.',

        /* ------------------------------------------------------------- limits */
        'limits_a'    => 'WHAT IT',
        'limits_b'    => 'WILL NOT DO',
        'limits_lede' => 'Said before you ask, so the number you take away is the number you can defend.',

        /* ------------------------------------------------------------- built for */
        'for_a'    => 'BUILT',
        'for_b'    => 'FOR',
        'for_lede' => 'Anyone who needs a number they can defend.',

        /* ------------------------------------------------------------- accuracy */
        'acc_a'    => 'THE',
        'acc_b'    => 'DATA',
        'acc_lede' => 'Where the number comes from, said plainly.',
        'acc_body' => "This checks Google organic results through SerpApi, using the country and language you select. It returns a neutral, unpersonalised position, which is the number worth tracking week to week.\n\nYour own browser shows something different because it knows your location, your history and your account. A rank checker has none of that, so the same query run twice returns the same answer. That is what makes it a baseline.\n\nUse it as a baseline and it will serve you well. Semrush states the same thing on their own free checker.",

        /* ------------------------------------------------------------- ai */
        'ai_a'    => 'RANK IS NOT',
        'ai_b'    => 'THE WHOLE STORY',
        'ai_body' => "Position still decides who gets found. It now decides less about who gets clicked.\n\nAhrefs studied 300,000 keywords and measured a 58 percent lower click through rate for the top ranking page when an AI Overview sits above it. Seer Interactive tracked 2.43 billion impressions and found organic click through on those queries running at 0.61 percent, against 1.62 percent without one.\n\nThe number that should change your week is different. BrightEdge found only 17 percent of AI Overview citations now come from pages in the organic top 10, down from 76 percent in mid 2024. Ranking first and being cited first have come apart.",
        'ai_note' => 'Check your position here. Then check whether the answer engines name you, because those are two separate wins.',

        /* ------------------------------------------------------------- upgrade modal */
        'up_title' => 'Checking more than one keyword?',
        'up_body'  => 'This page checks one keyword at a time, on demand. DoctorFizz is the platform the same team is building around it, currently in private beta. Join the beta list to hear when it opens.',
        'up_cta'   => 'Join the DoctorFizz beta',
        'up_url'   => 'https://doctorfizz.com',
        'up_note'  => 'Opens doctorfizz.com in a new tab. Close this and it will not ask again.',

        /* ------------------------------------------------------------- close */
        'cta_a'      => 'NOT THE NUMBER',
        'cta_b'      => 'YOU WANTED?',
        'cta_body'   => 'Knowing the position is the easy half. Closing the gap against the pages above you is the work, and it is the work Itzfizz Digital does for clients across the United Kingdom and India. Send the keyword and we will tell you what is holding the page back.',
        'cta_button' => 'Get a free gap review',
        'cta_url'    => 'https://itzfizz.com/contact',

        'parent_k'    => 'Who built this',
        'parent_body' => 'Itzfizz Digital is a digital marketing agency in Bengaluru working with clients across the United Kingdom and India, running SEO, generative engine optimisation, content and technical programmes. DoctorFizz is the tooling those programmes run on, and this checker is one piece of it, given away free.',
        'parent_cta'  => 'See how Itzfizz works',

        /* ------------------------------------------------------------- footer */
        'author_line' => 'Built and maintained by the search team at Itzfizz Digital, Bengaluru.',
        'footer_note' => 'This tool stores a hashed, non identifying key so it can count your daily allowance, and one flag in your browser session so the DoctorFizz notice is only shown once. It does not store your IP address and it sets no advertising cookies.',
    ];

    /** What the tool does. Capability statements only. */
    public const DEFAULT_DOES = [
        ['FINDS', 'Your exact position', 'Scans up to the first 50 organic results in the country and language you pick, and reports the position your domain holds.'],
        ['SHOWS', 'Everyone above you', 'Returns every competing result with its title and URL, so you can see what Google currently treats as the answer.'],
        ['TELLS', 'The next move', 'Places your position in a band and gives the single highest return action for that band.'],
    ];

    /** The pinned four step sequence. */
    public const DEFAULT_STEPS = [
        ['01', 'Type the keyword', 'Use the phrase a customer would actually search, not your product name.'],
        ['02', 'Add your domain', 'Just the domain. Subdomains count, so blog.yoursite.com matches yoursite.com.'],
        ['03', 'Pick the market', '239 countries and 82 interface languages. The search runs from there, not from where you are sitting.'],
        ['04', 'Read the gap', 'Position, competing results, and the action for your band, in one screen.'],
    ];

    /** What comes back in every result. */
    public const DEFAULT_RESULT = [
        ['Position', 'The organic rank your domain holds for that query, in that market.'],
        ['Ranking URL', 'The exact page Google chose, which is often not the one you expected.'],
        ['The field', 'Every result above you, with the competitor appearing most often called out.'],
        ['The action', 'One instruction, matched to your band, from protect it to check indexing first.'],
    ];

    /** Position bands. Mirrors the advice engine in the app layer, one to one. */
    public const DEFAULT_BANDS = [
        ['1 to 3',   'Own it',            'You are the answer for this query. Keep the page current and check whether the AI answer above you cites you or somebody else.', '1'],
        ['4 to 10',  'Under the fold',    'Page one, but below the first screen. The title and the opening answer move this further than more words will.', '0.7'],
        ['11 to 20', 'Striking distance', 'The highest return work on the site. Internal links from your strongest related pages, then close the gaps against the top five.', '0.4'],
        ['21 plus',  'Wrong match',       'Too far back to tune. Work out whether the page and the query are actually matched before touching the copy.', '0.18'],
    ];

    /** Honest boundaries. Stated plainly rather than buried in the FAQ. */
    public const DEFAULT_LIMITS = [
        ['Track keywords for you', 'Every check is run on demand, one keyword at a time. Nothing is scheduled and nothing is stored against an account, because there are no accounts.'],
        ['Show your personalised result', 'It reports the neutral index position for the market you pick. What you see signed in, in your own city, will differ, and that is the point of a baseline.'],
        ['Measure AI Overview citations', 'The check reads organic results only. Whether an answer engine names you is a separate question, and one worth asking separately.'],
        ['Scan past the first 50', 'The scan covers the first 50 organic results at most. Beyond that, position is not the problem worth solving.'],
    ];

    /** Audience cards, mirroring the way the DoctorFizz site frames its users. */
    public const DEFAULT_FOR = [
        ['Founders and D2C brands', 'Check whether the money keywords actually rank before paying anyone to improve them.'],
        ['Agencies', 'Pull a defensible baseline position for a pitch or a monthly report in seconds.'],
        ['Freelancers and consultants', 'Qualify a lead by checking three of their keywords before the call.'],
        ['In house marketers', 'Track the same query the same way each week and compare like with like.'],
    ];

    /** Headline figures for the AI section. Each carries its source. */
    public const DEFAULT_AI_STATS = [
        ['58', '%', 'lower click through rate for the top ranking page when an AI Overview is present', 'Ahrefs, 300,000 keywords, December 2025'],
        ['17', '%', 'of AI Overview citations come from the organic top 10, down from 76 percent in mid 2024', 'BrightEdge, February 2026'],
        ['0.61', '%', 'organic click through on AI Overview queries, against 1.62 percent without one', 'Seer Interactive, 2.43bn impressions, 2026'],
    ];

    /** Outbound authoritative sources, rendered as real links. */
    public const DEFAULT_SOURCES = [
        ['Ahrefs: AI Overviews reduce clicks, December 2025 update', 'https://ahrefs.com/blog/ai-overviews-reduce-clicks-update'],
        ['Seer Interactive: AI Overview impact on Google CTR, 2026', 'https://www.seerinteractive.com/insights/aio-impact-on-google-ctr-2026-update'],
        ['Search Engine Land: AI Overviews CTR recovery study', 'https://searchengineland.com/google-ai-overviews-ctr-recovery-study-475566'],
        ['SerpApi: Google Search API documentation', 'https://serpapi.com/search-api'],
    ];

    /** FAQ pairs. Also published as FAQPage structured data. */
    public const DEFAULT_FAQ = [
        ['Is this rank checker really free?',
         'Yes. No signup, no email, no card. Every visitor gets five checks a day, and repeat checks of the same keyword and domain come from cache without using the allowance.'],
        ['How accurate is it?',
         'It returns a neutral, unpersonalised position from a Google search index for the country and language you choose. Run the same check twice and you get the same answer, which is what makes it usable as a baseline you can track.'],
        ['Why might it differ from what I see in Google?',
         'Your own results are shaped by your location, search history and signed in account, and by AI Overviews, map packs and ads pushing organic results down the visible page. The checker has no history, so it reports the underlying organic position.'],
        ['Does it check subdomains?',
         'Yes. It matches on hostname, so blog.yoursite.com counts as a match for yoursite.com, and it reports the exact URL that ranked.'],
        ['Can I check other countries?',
         'Yes, 239 countries and 82 interface languages. This matters most when you sell into a market you are not based in, because the local result set is the only one that counts.'],
        ['How deep does it scan?',
         'Up to the first 50 organic results, your choice of 10, 20, 30 or 50. The scan stops as soon as it finds your domain.'],
        ['How often should I check?',
         'Weekly suits most sites, monthly in slow moving industries. Pick a fixed day and compare like with like, because daily checking mostly measures normal fluctuation.'],
        ['Does ranking first still mean getting the clicks?',
         'Less than it did. Ahrefs measured a 58 percent lower click through rate for the top ranking page when an AI Overview is present. Position one is still the best place to be, it simply earns fewer visits than it did two years ago.'],
        ['Can I use this for client reporting?',
         'Yes, as a baseline, as long as you state where the number came from. It is an index position rather than a client specific personalised result.'],
        ['Do you store my keyword or domain?',
         'The keyword, domain and country are stored so repeat checks can be cached. Your IP address is never stored. A one way keyed hash is used only to count your daily allowance.'],
    ];

    /**
     * House style is enforced when content is saved, not left to whoever is typing.
     * Surrounding whitespace is absorbed so "word , word" never appears.
     */
    public static function deDash(string $s): string
    {
        $s = preg_replace('/\s*\x{2014}\s*/u', ', ', $s) ?? $s;
        $s = preg_replace('/\s*\x{2013}\s*/u', ' to ', $s) ?? $s;
        return $s;
    }

    /** Renders the tiny body markup. Everything is escaped. */
    public static function render(string $raw): string
    {
        $out = '';
        $bullets = [];

        $flush = static function () use (&$bullets, &$out): void {
            if ($bullets !== []) {
                $out .= '<ul class="rt-list">';
                foreach ($bullets as $b) {
                    $out .= '<li>' . Security::e($b) . '</li>';
                }
                $out .= '</ul>';
                $bullets = [];
            }
        };

        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') { $flush(); continue; }
            if (str_starts_with($line, '- ')) { $bullets[] = substr($line, 2); continue; }
            $flush();
            $out .= '<p>' . Security::e($line) . '</p>';
        }
        $flush();

        return $out;
    }

    /** @return array<int,array{0:string,1:string}> */
    public static function faq(): array
    {
        $raw = App::content('faq_json');
        if ($raw !== '') {
            $parsed = json_decode($raw, true);
            if (is_array($parsed) && $parsed !== []) {
                return array_values(array_filter($parsed, static fn($r) => is_array($r) && count($r) === 2));
            }
        }
        return self::DEFAULT_FAQ;
    }
}
