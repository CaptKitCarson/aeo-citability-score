=== AEO Citability Score ===
Contributors: kitmobley
Tags: seo, aeo, ai search, google ai overviews, chatgpt, perplexity, citation, content score
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Score every post on the factors that actually cause AI citation. Not another llms.txt generator — 97% of those go unread by AI crawlers. This measures what Google AI Overviews, ChatGPT, and Perplexity actually look at.

== Description ==

Every post gets a 0-100 citability score computed from the signals AI search engines use to decide who to cite: front-loaded answers, question-formatted headings, entity clarity, source attribution, speakable coverage, and structured Q&A patterns.

= Free =

* Structural scoring on every published post — no AI needed
* Auto-recalculates on save
* Per-post editor sidebar with score gauge + per-signal breakdown
* Site dashboard: average score, distribution histogram, top-10 needs-attention posts
* Bulk-scan tool for existing catalog
* Works with Yoast / Rank Math / AIOSEO / SEOPress side by side

= Solo Pro — $79/yr, 1 site =

* **BYO API key** — OpenAI, Anthropic, or Google Gemini. Keys never leave your server.
* AI rubric scoring: full-post analysis with 7 weighted signals + weak-passage callouts
* Inline paragraph rewrites — click "Fix" on any flagged passage, get a Gemini/Claude/GPT rewrite
* Historical trend (track score over time as you improve posts)
* CSV export of site-wide scores
* Automatic plugin updates + priority support

= Agency Pro — $299/yr, 25 sites =

* Everything in Solo across 25 client sites
* Deactivate + move between clients freely

Buy at https://kitmobley.com/plugins/aeo-citability-score/

== Frequently Asked Questions ==

= Why not just generate an llms.txt file? =

Because AI crawlers ignore them. Ahrefs analyzed 137,000 sites in May 2026 and found 97% of llms.txt files got zero requests. Of the requests that did come, 77% were from non-AI bots. Meanwhile every content page has fixable structural problems that actually drive citation — buried leads, no question headings, thin entity signals. This plugin fixes what AI actually reads.

= Do I need to pay for AI on top of Pro? =

You bring your own OpenAI, Anthropic, or Google Gemini API key. Pro pays for the plugin (rubric, rewrites, history, bulk, CSV). The LLM calls run against your own account at whatever the provider charges (typically cents per post with cheap models like gpt-4o-mini or claude-haiku).

= Will it slow my site? =

No. Structural scoring is pure PHP — no external calls. Runs on save (async at the WP level). AI rubric only runs when you explicitly click "Run AI analysis" in the editor.

= Does the free tier really work without an AI key? =

Yes. Structural signals catch ~80% of what makes a post citable. The AI rubric is deeper and generates rewrites — but many sites can get to a solid 70+ score just by fixing what free-tier scoring flags.

== Changelog ==

= 1.0.0 =
* Initial release
* Structural scorer with 8 weighted signals
* Per-post editor sidebar meta box
* Site-wide dashboard + bulk scan
* AI rubric scoring (Pro): OpenAI / Anthropic / Gemini BYO API key
* Inline paragraph rewrites (Pro)
* Built on the shared kitmobley/wp-plugin-core library

== Upgrade Notice ==

= 1.0.0 =
First release. Score every post on 8 structural signals immediately, or upgrade to Pro for AI rubric + inline rewrites.
