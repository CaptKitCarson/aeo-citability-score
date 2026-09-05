<?php
/**
 * Structural citability scorer — free tier.
 *
 * Given a post's content + meta, returns:
 *   array{
 *     score:int (0-100),
 *     tier:string (poor|fair|good|excellent),
 *     signals: array<string,array{score:int,label:string,detail:string}>,
 *     computed_at:string,
 *   }
 *
 * Signals (each 0-100 subscore, weighted into the composite):
 *   - front_loaded    : the payload sentence lives in the first ~155 chars
 *   - question_h2s    : count of question-formatted H2/H3 headings
 *   - entity_density  : capitalization ratio in first paragraph
 *   - qa_blocks       : structured Q&A pairs (HTML details/summary or heading→p)
 *   - source_attribution : count of external links to non-social domains
 *   - speakable_hint  : presence of speakable class or hint in content
 *   - lede_strength   : first paragraph length + sentence count
 *   - schema_ready    : post has schema-friendly markup
 *
 * Weights are tuned from the AEO Citability Score research memo (see
 * kitmobley.com/plugins/aeo-citability-score/#pricing FAQ for methodology).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class AECS_Scorer {

	/**
	 * Weight per signal. Sum ≈ 100 but not required — scaled at the end.
	 */
	const WEIGHTS = array(
		'front_loaded'       => 20,
		'question_h2s'       => 15,
		'entity_density'     => 10,
		'qa_blocks'          => 10,
		'source_attribution' => 15,
		'speakable_hint'     => 10,
		'lede_strength'      => 12,
		'schema_ready'       => 8,
	);

	public function score_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) return $this->empty_result();
		return $this->score_content( $post->post_content, $post->post_title, $post_id );
	}

	public function score_content( $raw, $title = '', $post_id = 0 ) {
		$signals = array(
			'front_loaded'       => $this->signal_front_loaded( $raw, $title ),
			'question_h2s'       => $this->signal_question_h2s( $raw ),
			'entity_density'     => $this->signal_entity_density( $raw ),
			'qa_blocks'          => $this->signal_qa_blocks( $raw ),
			'source_attribution' => $this->signal_source_attribution( $raw ),
			'speakable_hint'     => $this->signal_speakable_hint( $raw, $post_id ),
			'lede_strength'      => $this->signal_lede_strength( $raw ),
			'schema_ready'       => $this->signal_schema_ready( $raw, $post_id ),
		);

		$total_weight = 0;
		$weighted_sum = 0;
		foreach ( self::WEIGHTS as $key => $w ) {
			$sub = $signals[ $key ]['score'] ?? 0;
			$weighted_sum += $sub * $w;
			$total_weight += $w;
		}
		$score = $total_weight > 0 ? (int) round( $weighted_sum / $total_weight ) : 0;
		$score = max( 0, min( 100, $score ) );

		return array(
			'score'       => $score,
			'tier'        => $this->tier_for( $score ),
			'signals'     => $signals,
			'computed_at' => gmdate( 'c' ),
		);
	}

	// ── Signals ──────────────────────────────────────────────────

	private function signal_front_loaded( $raw, $title ) {
		$stripped = trim( wp_strip_all_tags( $raw ) );
		if ( '' === $stripped ) return $this->sub( 0, 'No content', 'Empty post body.' );
		$first = mb_substr( $stripped, 0, 200 );
		$has_answer_verb = (bool) preg_match( '/\b(is|are|means|refers to|involves|includes|are used|works by|allows you|helps you|lets you|shows|explains|describes|answers)\b/i', $first );
		$title_terms = preg_split( '/\s+/', preg_replace( '/[^\w\s]/u', '', mb_strtolower( $title ) ) );
		$overlap = 0;
		$first_lc = mb_strtolower( $first );
		foreach ( (array) $title_terms as $t ) {
			$t = trim( (string) $t );
			if ( mb_strlen( $t ) < 4 ) continue;
			if ( str_contains( $first_lc, $t ) ) $overlap++;
		}
		$title_overlap_ratio = $title_terms ? min( 1, $overlap / max( 1, count( array_filter( $title_terms, fn( $t ) => mb_strlen( $t ) >= 4 ) ) ) ) : 0;

		$score = 0;
		if ( $has_answer_verb ) $score += 55;
		$score += (int) ( $title_overlap_ratio * 45 );
		$score = min( 100, $score );

		$detail = sprintf(
			'First 200 chars %sinclude an answer verb; %d%% of title terms surface early.',
			$has_answer_verb ? '' : 'do NOT ',
			(int) ( $title_overlap_ratio * 100 )
		);
		return $this->sub( $score, 'Front-loaded answer', $detail );
	}

	private function signal_question_h2s( $raw ) {
		preg_match_all( '#<h[23][^>]*>(.*?)</h[23]>#is', $raw, $m );
		$headings = $m[1] ?? array();
		if ( empty( $headings ) ) {
			return $this->sub( 0, 'Question-formatted H2/H3', 'No H2 or H3 headings detected.' );
		}
		$question_count = 0;
		foreach ( $headings as $h ) {
			$text = trim( wp_strip_all_tags( $h ) );
			if ( '' === $text ) continue;
			if ( str_ends_with( $text, '?' ) ) { $question_count++; continue; }
			if ( preg_match( '/^(what|how|why|when|where|which|who|can|does|do|is|are)\b/i', $text ) ) $question_count++;
		}
		$ratio = count( $headings ) ? $question_count / count( $headings ) : 0;
		$score = min( 100, (int) ( 15 * $question_count + 30 * $ratio ) );
		return $this->sub( $score, 'Question-formatted headings', sprintf( '%d of %d H2/H3 headings are questions.', $question_count, count( $headings ) ) );
	}

	private function signal_entity_density( $raw ) {
		$stripped = wp_strip_all_tags( $raw );
		$first_paragraph = mb_substr( $stripped, 0, 400 );
		if ( '' === trim( $first_paragraph ) ) return $this->sub( 0, 'Entity clarity', 'Empty content.' );
		$words = preg_split( '/\s+/', trim( $first_paragraph ) ) ?: array();
		$capital_count = 0;
		foreach ( $words as $w ) {
			$w = trim( (string) $w );
			if ( mb_strlen( $w ) < 3 ) continue;
			// Skip if the whole word is uppercase (headline acronyms don't count much).
			if ( preg_match( '/^[A-Z][a-z]+/', $w ) ) $capital_count++;
		}
		$density = count( $words ) ? $capital_count / max( 1, count( $words ) ) : 0;
		// Sweet spot: ~5-15% proper nouns. Too few = no entity anchors. Too many = spam-ish.
		if ( $density >= 0.05 && $density <= 0.18 ) $score = 100;
		elseif ( $density < 0.05 ) $score = (int) ( ( $density / 0.05 ) * 60 );
		else $score = max( 30, 100 - (int) ( ( $density - 0.18 ) * 400 ) );
		return $this->sub( $score, 'Entity clarity', sprintf( '%d%% proper-noun density in first 400 chars.', (int) ( $density * 100 ) ) );
	}

	private function signal_qa_blocks( $raw ) {
		$count = 0;
		if ( preg_match_all( '#<details[^>]*>#i', $raw, $m ) ) $count += count( $m[0] );
		// Heading immediately followed by paragraph — H2/H3 + <p>
		if ( preg_match_all( '#</h[23]>\s*<p\b#i', $raw, $m ) ) $count += (int) ( count( $m[0] ) / 2 );
		// Bold-question pattern: <strong>...?</strong>
		if ( preg_match_all( '#<strong>[^<]{5,120}\?</strong>#i', $raw, $m ) ) $count += count( $m[0] );
		if ( 0 === $count ) return $this->sub( 0, 'Q&A blocks', 'No structured Q&A pairs detected.' );
		$score = min( 100, $count * 25 );
		return $this->sub( $score, 'Q&A blocks', sprintf( '%d structured Q&A pair%s found.', $count, $count === 1 ? '' : 's' ) );
	}

	private function signal_source_attribution( $raw ) {
		$stripped = wp_strip_all_tags( $raw, true );
		// Delimiter is ~ deliberately: with # as the delimiter the unescaped #
		// inside the character class ends the pattern early, so this match always
		// failed and every post scored zero for source attribution.
		preg_match_all( '~href=[\'"](https?://[^\'"#]+)[\'"]~i', $raw, $m );
		$urls = $m[1] ?? array();
		if ( empty( $urls ) ) return $this->sub( 0, 'Source attribution', 'No external links found.' );
		$site_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$social = array( 'facebook.com', 'twitter.com', 'x.com', 'instagram.com', 'pinterest.com', 'linkedin.com', 'tiktok.com', 't.co' );
		$external = 0;
		$authoritative = 0;
		foreach ( $urls as $u ) {
			$host = wp_parse_url( $u, PHP_URL_HOST );
			if ( ! $host ) continue;
			$host = strtolower( preg_replace( '/^www\./', '', $host ) );
			if ( $host === $site_host ) continue;
			if ( in_array( $host, $social, true ) ) continue;
			$external++;
			if ( preg_match( '/\.(gov|edu|mil|nih|nasa\.gov)$/i', $host ) ) $authoritative++;
			elseif ( preg_match( '/(wikipedia|reuters|apnews|bbc|nytimes|nature|arxiv|sciencedirect|springer|jstor)\./i', $host ) ) $authoritative++;
		}
		$score = min( 100, ( $external * 20 ) + ( $authoritative * 15 ) );
		return $this->sub( $score, 'Source attribution', sprintf( '%d external link%s (%d authoritative).', $external, $external === 1 ? '' : 's', $authoritative ) );
	}

	private function signal_speakable_hint( $raw, $post_id ) {
		if ( strpos( $raw, 'speakable' ) !== false ) return $this->sub( 100, 'Speakable markup', 'Speakable class or reference found in content.' );
		// A well-configured NewsArticle Schema Pack sibling might inject speakable via schema — check the SCA/NASP meta signals.
		if ( get_post_meta( $post_id, '_nasp_meta_description', true ) ) return $this->sub( 60, 'Speakable markup', 'Post has meta description but no explicit speakable class.' );
		return $this->sub( 20, 'Speakable markup', 'No speakable class in content. Consider adding class="speakable" to the lede.' );
	}

	private function signal_lede_strength( $raw ) {
		$stripped = wp_strip_all_tags( $raw );
		if ( '' === trim( $stripped ) ) return $this->sub( 0, 'Lede strength', 'Empty content.' );
		$paragraphs = preg_split( '/\n{2,}/', trim( $stripped ) );
		$first = trim( $paragraphs[0] ?? '' );
		$length = mb_strlen( $first );
		$sentences = preg_split( '/[.!?]+/', $first );
		$sentence_count = count( array_filter( $sentences, function ( $s ) { return trim( $s ) !== ''; } ) );

		// Ideal: 60-320 char lede with 2-4 sentences. Extreme lengths tank the score.
		if ( $length < 30 )  return $this->sub( 20, 'Lede strength', sprintf( 'Lede is only %d chars — too short to answer anything.', $length ) );
		if ( $length > 500 ) return $this->sub( 40, 'Lede strength', sprintf( 'Lede runs %d chars — split into a punchier opener.', $length ) );
		$sentence_score = min( 100, $sentence_count * 30 );
		$length_score = min( 100, ( $length / 4 ) );
		$score = (int) round( ( $sentence_score + $length_score ) / 2 );
		return $this->sub( $score, 'Lede strength', sprintf( 'Lede: %d chars, %d sentences.', $length, $sentence_count ) );
	}

	private function signal_schema_ready( $raw, $post_id ) {
		$hits = 0;
		if ( preg_match( '/class=[\'"][^\'\"]*(schema|structured|hentry)[^\'"]*[\'"]/', $raw ) ) $hits++;
		if ( preg_match( '/itemtype=[\'"]https?:\/\/schema\.org/', $raw ) ) $hits++;
		if ( has_excerpt( $post_id ) ) $hits++;
		if ( has_post_thumbnail( $post_id ) ) $hits++;
		$score = min( 100, $hits * 30 );
		return $this->sub( $score, 'Schema readiness', sprintf( '%d/4 schema-friendly signals present.', $hits ) );
	}

	// ── Helpers ──────────────────────────────────────────────────

	private function sub( $score, $label, $detail ) {
		return array( 'score' => max( 0, min( 100, (int) $score ) ), 'label' => $label, 'detail' => $detail );
	}

	private function tier_for( $score ) {
		if ( $score >= 85 ) return 'excellent';
		if ( $score >= 70 ) return 'good';
		if ( $score >= 50 ) return 'fair';
		return 'poor';
	}

	private function empty_result() {
		return array( 'score' => 0, 'tier' => 'poor', 'signals' => array(), 'computed_at' => gmdate( 'c' ) );
	}
}
